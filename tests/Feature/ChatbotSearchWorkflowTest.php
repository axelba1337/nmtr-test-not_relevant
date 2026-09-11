<?php

namespace Tests\Feature;

use App\Data\AI\ParsedIntent;
use App\Data\Search\SearchState;
use App\Models\ChatbotQueryLog;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Search\ListingSearchService;
use App\Services\Search\ProductRetriever;
use Database\Seeders\NeomatraDatasetV2Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ChatbotSearchWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.http.retries' => 0,
            'ai.http.retry_delay_ms' => 0,
        ]);
        $this->seed(NeomatraDatasetV2Seeder::class);
    }

    public function test_sc05_punctuation_only_input_is_rejected_without_ai(): void
    {
        Http::fake();

        $this->postJson('/api/chatbot/query', ['message' => '!!!???---'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::INVALID_INPUT)
            ->assertJsonPath('attempt_count', 0)
            ->assertJsonPath('listings', []);

        Http::assertNothingSent();
    }

    public function test_sc06_known_gibberish_is_rejected_without_ai(): void
    {
        Http::fake();

        $this->postJson('/api/chatbot/query', ['message' => 'qwertyuiop'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::INVALID_INPUT);

        Http::assertNothingSent();
    }

    public function test_sc07_vehicle_is_out_of_scope_before_clarification(): void
    {
        Http::fake();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Carikan Honda Civic bekas yang murah.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::OUT_OF_SCOPE)
            ->assertJsonPath('needs_clarification', false)
            ->assertJsonPath('external_handoff.0.platform', 'OLX')
            ->assertJsonFragment(['slug' => 'handphone'])
            ->assertDontSee('tahun berapa', false);

        Http::assertNothingSent();
    }

    public function test_sc08_rtx_regression_matches_without_live_ai(): void
    {
        $this->fakeProviderFailure();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas di bawah Rp3 juta.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('resolved_product.name', 'NVIDIA GeForce RTX 3060')
            ->assertJsonPath('resolved_product.variant', '12 GB')
            ->assertJsonPath('requested_filters.budget_max', 3_000_000)
            ->assertJsonPath('requested_filters.conditions.0', 'used')
            ->assertJsonPath('relaxed_filters', [])
            ->assertJsonPath('meta.similarity_threshold', null)
            ->assertJsonCount(1, 'listings');
    }

    public function test_sc09_iphone_regression_resolves_exact_128gb_variant(): void
    {
        $this->fakeProviderFailure();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari iPhone 13 128GB bekas di marketplace.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('resolved_product.name', 'Apple iPhone 13 128GB')
            ->assertJsonPath('resolved_product.model', 'iPhone 13')
            ->assertJsonPath('resolved_product.variant', '128 GB')
            ->assertJsonPath('listings.0.product.name', 'Apple iPhone 13 128GB')
            ->assertJsonPath('listings.0.condition', 'used')
            ->assertJsonPath('meta.ranking_authority', 'backend');

        foreach ($response->json('listings') as $listing) {
            $this->assertSame('Apple iPhone 13 128GB', $listing['product']['name']);
            $this->assertIsInt($listing['item_price']);
            $this->assertArrayNotHasKey('market', $listing);
        }
    }

    public function test_sc10_budget_relaxation_returns_closest_alternatives_transparently(): void
    {
        $this->fakeProviderFailure();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas di bawah Rp1 juta.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::FILTER_TOO_STRICT)
            ->assertJsonPath('relaxed_filters.0', 'budget')
            ->assertJsonPath('attempt_count', 2)
            ->assertJsonPath('applied_filters.budget_max', null)
            ->assertJsonCount(3, 'listings')
            ->assertDontSee('Maaf', false);

        $log = ChatbotQueryLog::query()->latest('id')->firstOrFail();
        $this->assertSame(
            SearchState::FILTER_TOO_STRICT,
            data_get($log->filters, '_search.state'),
        );
        $this->assertGreaterThan(
            0,
            data_get($log->filters, '_search.budget_difference'),
        );
    }

    public function test_sc11_location_relaxes_in_a_bounded_order(): void
    {
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas di Medan dari toko resmi.',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::FILTER_TOO_STRICT)
            ->assertJsonPath('relaxed_filters.0', 'location')
            ->assertJsonPath('attempt_count', 2)
            ->assertJsonPath('requested_filters.conditions.0', 'used')
            ->assertJsonPath('applied_filters.conditions.0', 'used');
    }

    public function test_sc12_condition_is_never_relaxed(): void
    {
        Listing::query()->whereIn(
            'condition',
            Listing::conditionValuesForSearch(Listing::CONDITION_USED),
        )
            ->update(['is_active' => false]);
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas.',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::NO_ACTIVE_LISTING)
            ->assertJsonPath('requested_filters.conditions.0', 'used')
            ->assertJsonPath('applied_filters.conditions.0', 'used')
            ->assertJsonPath('relaxed_filters', []);
    }

    public function test_sc13_unknown_product_has_structured_external_handoff(): void
    {
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari PlayStation 99 edisi prototipe.',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::UNKNOWN_PRODUCT)
            ->assertJsonPath('resolved_product', null)
            ->assertJsonPath('external_handoff.0.platform', 'OLX')
            ->assertJsonPath('meta.similarity_threshold', null);
    }

    public function test_sc14_platform_unavailable_is_distinct_from_no_listing(): void
    {
        Platform::query()->update(['is_active' => false]);
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas.',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::PLATFORM_UNAVAILABLE)
            ->assertJsonPath('attempt_count', 0)
            ->assertJsonPath('platforms_searched', []);
    }

    public function test_sc15_deterministic_resolution_rejects_critical_variant_conflicts(): void
    {
        $matches = app(ProductRetriever::class)->retrieveDeterministically([
            'query' => 'iphone 13 128gb bekas',
            'brand' => 'apple',
            'model' => 'iphone 13',
            'variant' => '128gb',
        ]);

        $this->assertNotEmpty($matches);
        $this->assertSame('Apple iPhone 13 128GB', $matches[0]['product']->name);

        foreach ($matches as $match) {
            $identity = strtolower(implode(' ', [
                $match['product']->name,
                $match['product']->model,
                $match['product']->variant,
            ]));
            $this->assertStringNotContainsString(' pro', $identity);
            $this->assertStringNotContainsString(' max', $identity);
            $this->assertStringNotContainsString(' mini', $identity);
        }
    }

    public function test_sc16_progressive_search_never_exceeds_four_attempts(): void
    {
        $product = Product::query()->where('model', 'RTX 3060')->firstOrFail();
        Listing::query()->where('product_id', $product->id)
            ->update(['is_active' => false]);
        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060',
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB',
            'sku_or_part_number' => null,
            'budget_min' => null,
            'budget_max' => 1_000_000,
            'conditions' => ['used'],
            'locations' => ['Medan'],
            'needs_clarification' => false,
            'clarification_question' => null,
            'intent_type' => 'product_search',
        ]);
        $this->assertNotNull($intent);

        $outcome = app(ListingSearchService::class)->searchProgressively([
            [
                'product' => $product,
                'similarity' => null,
                'matched_via' => 'deterministic_product',
            ],
        ], $intent);

        $this->assertSame(SearchState::NO_ACTIVE_LISTING, $outcome->state);
        $this->assertSame(3, $outcome->attemptCount);
        $this->assertSame(
            ['location', 'budget'],
            $outcome->relaxedFilters,
        );
    }

    public function test_sc17_alias_resolves_to_the_same_canonical_product(): void
    {
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'Cari VGA RTX 3060 bekas.',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('resolved_product.name', 'NVIDIA GeForce RTX 3060');
    }

    public function test_sc18_ambiguous_supported_query_needs_clarification(): void
    {
        $this->fakeProviderFailure();

        $this->postJson('/api/chatbot/query', [
            'message' => 'cari laptop',
        ])
            ->assertOk()
            ->assertJsonPath('state', SearchState::NEEDS_CLARIFICATION)
            ->assertJsonPath('needs_clarification', true)
            ->assertJsonPath('attempt_count', 0);

        Http::assertSentCount(1);
    }

    public function test_sc19_failed_platform_returns_partial_backend_results(): void
    {
        Platform::query()->where('slug', 'shopee')->update([
            'status' => Platform::STATUS_ERROR,
        ]);
        $this->fakeProviderFailure();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::PARTIAL_RESULT)
            ->assertJsonPath('platforms_failed.0', 'shopee')
            ->assertJsonMissing(['platform' => 'Shopee']);
        $this->assertNotEmpty($response->json('listings'));
    }

    public function test_sc20_response_cannot_contain_a_non_backend_listing(): void
    {
        $this->fakeProviderFailure();

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'Cari RTX 3060 bekas.',
        ])->assertOk();

        foreach ($response->json('listings') as $listing) {
            $backend = Listing::query()->find($listing['id']);

            $this->assertNotNull($backend);
            $this->assertSame($backend->title, $listing['title']);
            $this->assertSame($backend->price, $listing['item_price']);
        }

        $response->assertDontSee('provider unavailable in test');
    }

    private function fakeProviderFailure(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'provider unavailable in test'],
            ], 503),
        ]);
    }
}
