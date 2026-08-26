<?php

namespace Tests\Feature;

use App\Data\Search\SearchState;
use App\Models\CategoryAlias;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Search\CategoryAliasResolver;
use App\Services\Search\ProductFamilyResolver;
use Database\Seeders\NeomatraDatasetV2Seeder;
use Database\Seeders\NeomatraSearchVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ChatbotVocabularyFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.chat.web_grounding.enabled' => false,
            'ai.http.retries' => 0,
            'ai.http.retry_delay_ms' => 0,
        ]);
        $this->seed(NeomatraDatasetV2Seeder::class);
        $this->seed(NeomatraSearchVocabularySeeder::class);
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'provider unavailable in scenario'],
            ], 503),
        ]);
    }

    public function test_category_vocabulary_is_idempotent_and_not_stored_as_generic_product_aliases(): void
    {
        $before = CategoryAlias::query()->count();
        $this->seed(NeomatraSearchVocabularySeeder::class);

        $this->assertSame($before, CategoryAlias::query()->count());
        $this->assertGreaterThanOrEqual(70, $before);
        $this->assertDatabaseHas('category_aliases', [
            'normalized_alias' => 'graphics card',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('product_aliases', [
            'normalized_alias' => 'graphics card',
        ]);
        $this->assertGreaterThanOrEqual(
            7,
            KnowledgeItem::query()
                ->where('source_name', 'Neomatra Search Vocabulary V1')
                ->active()
                ->count(),
        );
    }

    public function test_sc_cat_01_graphic_card_is_gpu_and_needs_clarification(): void
    {
        $this->postJson('/api/chatbot/query', ['message' => 'graphic card'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::NEEDS_CLARIFICATION)
            ->assertJsonPath('resolved_category.slug', 'gpu')
            ->assertJsonPath('resolved_product', null);

        Http::assertSentCount(1);
    }

    public function test_sc_cat_02_graphic_card_budget_searches_gpu_listings(): void
    {
        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'graphic card under 3 million',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('resolved_category.slug', 'gpu')
            ->assertJsonPath('resolved_product', null)
            ->assertJsonPath('requested_filters.budget_max', 3_000_000);
        $this->assertNotEmpty($response->json('results'));

        foreach ($response->json('results') as $result) {
            $this->assertSame('GPU', $result['product']['category']);
            $this->assertLessThanOrEqual(3_000_000, $result['costs']['total']);
        }
    }

    public function test_sc_cat_03_indonesian_gpu_filters_are_normalized(): void
    {
        $this->postJson('/api/chatbot/query', [
            'message' => 'kartu grafis bekas dibawah 3 juta',
        ])
            ->assertOk()
            ->assertJsonPath('resolved_category.slug', 'gpu')
            ->assertJsonPath('intent.conditions.0', 'used')
            ->assertJsonPath('intent.budget_max', 3_000_000);
    }

    public function test_sc_cat_04_vga_nvidia_resolves_category_and_brand(): void
    {
        $this->postJson('/api/chatbot/query', ['message' => 'VGA NVIDIA'])
            ->assertOk()
            ->assertJsonPath('resolved_category.slug', 'gpu')
            ->assertJsonPath('resolved_brand', 'nvidia');
    }

    public function test_sc_cat_05_curated_typo_resolves_safely(): void
    {
        $resolution = app(CategoryAliasResolver::class)->resolve(
            'grafics card under 3 million',
        );

        $this->assertSame('gpu', $resolution?->category->slug);
        $this->assertSame('category_alias_curated_typo', $resolution?->strategy);
    }

    public function test_sc_prod_01_family_resolver_extracts_rtx_metadata(): void
    {
        $family = app(ProductFamilyResolver::class)->resolve('RTX 3070');

        $this->assertSame('gpu', $family['category_slug']);
        $this->assertSame('NVIDIA', $family['brand']);
        $this->assertSame('geforce_rtx', $family['family']);
        $this->assertSame('rtx_30', $family['generation']);
        $this->assertSame(3070, $family['model_number']);
    }

    public function test_sc_prod_02_exact_rtx_3070_listing_matches(): void
    {
        $this->postJson('/api/chatbot/query', ['message' => 'RTX 3070'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::MATCHED)
            ->assertJsonPath('resolved_product.model', 'RTX 3070')
            ->assertJsonPath('resolved_category.slug', 'gpu')
            ->assertJsonPath('resolved_family', 'geforce_rtx');
    }

    public function test_sc_prod_03_rtx_3060_ti_is_the_first_exact_family_alternative(): void
    {
        $requested = Product::query()->where('model', 'RTX 3070')->firstOrFail();
        $requested->listings()->update(['is_active' => false]);

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'RTX 3070 bekas',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::ALTERNATIVE_PRODUCT)
            ->assertJsonPath(
                'alternative_strategy',
                'same_brand_same_family_same_generation',
            )
            ->assertJsonPath('results.0.product.model', 'RTX 3060 Ti')
            ->assertJsonPath('alternative_details.0.different_brand', false)
            ->assertJsonPath('alternative_details.0.different_family', false);
    }

    public function test_sc_prod_04_gtx_is_used_only_when_no_rtx_listing_exists(): void
    {
        $requested = Product::query()->where('model', 'RTX 3070')->firstOrFail();
        $this->deactivateGpuListings(static fn (Product $product): bool =>
            data_get($product->attributes, 'product_family') === 'geforce_rtx');

        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'RTX 3070 bekas',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('state', SearchState::ALTERNATIVE_PRODUCT)
            ->assertJsonPath('alternative_strategy', 'same_brand_different_family')
            ->assertJsonPath('alternative_details.0.different_family', true);
        $this->assertSame($requested->id, $response->json('requested_product.id'));
        $this->assertStringContainsString(
            'GTX',
            (string) $response->json('results.0.product.model'),
        );
        $this->assertStringNotContainsString(
            'RTX 1050',
            json_encode($response->json(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_sc_prod_05_amd_is_transparently_used_after_nvidia_is_unavailable(): void
    {
        $this->deactivateGpuListings(static fn (Product $product): bool =>
            mb_strtolower((string) $product->brand, 'UTF-8') === 'nvidia');

        $this->postJson('/api/chatbot/query', ['message' => 'RTX 3070 bekas'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::ALTERNATIVE_PRODUCT)
            ->assertJsonPath(
                'alternative_strategy',
                'same_category_different_brand',
            )
            ->assertJsonPath('results.0.product.brand', 'AMD')
            ->assertJsonPath('alternative_details.0.different_brand', true);
    }

    public function test_sc_prod_06_no_gpu_alternative_returns_external_handoff(): void
    {
        $this->deactivateGpuListings(static fn (Product $product): bool => true);

        $this->postJson('/api/chatbot/query', ['message' => 'RTX 3070 bekas'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::NO_ACTIVE_LISTING)
            ->assertJsonPath('external_handoff.0.platform', 'OLX');
    }

    public function test_sc_platform_01_tokobagus_maps_to_olx(): void
    {
        $this->postJson('/api/chatbot/query', [
            'message' => 'tokobagus RTX 3070',
        ])
            ->assertOk()
            ->assertJsonPath('intent.platforms.0', 'olx')
            ->assertJsonPath('platforms_searched.0', 'blibli');
    }

    public function test_scope_and_learning_metadata_remain_enforced(): void
    {
        Http::fake();
        $this->postJson('/api/chatbot/query', ['message' => 'Honda Civic'])
            ->assertOk()
            ->assertJsonPath('state', SearchState::OUT_OF_SCOPE)
            ->assertJsonPath('needs_clarification', false);
        Http::assertNothingSent();

        $this->fakeProviderFailure();
        $response = $this->postJson('/api/chatbot/query', [
            'message' => 'kartu grafis bekas dibawah 3 juta',
        ])->assertOk();
        $log = ChatbotQueryLog::query()->latest('id')->firstOrFail();

        $this->assertSame('gpu', data_get($log->filters, '_search.resolved_category'));
        $this->assertSame($response->json('state'), data_get($log->filters, '_search.state'));
        $this->assertNotEmpty(data_get($log->filters, '_search.result_product_ids'));
        $this->assertNotEmpty(data_get($log->filters, '_search.result_listing_ids'));
        $this->assertDatabaseMissing('category_aliases', [
            'normalized_alias' => 'interaction generated alias',
        ]);
    }

    private function deactivateGpuListings(callable $predicate): void
    {
        Product::query()
            ->whereHas('category', static fn ($query) => $query->where('slug', 'gpu'))
            ->get()
            ->each(function (Product $product) use ($predicate): void {
                if ($predicate($product)) {
                    $product->listings()->update(['is_active' => false]);
                }
            });
    }

    private function fakeProviderFailure(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'provider unavailable in scenario'],
            ], 503),
        ]);
    }
}
