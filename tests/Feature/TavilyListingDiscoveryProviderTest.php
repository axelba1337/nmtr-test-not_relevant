<?php

namespace Tests\Feature;

use App\Contracts\AI\ChatClient;
use App\Contracts\AI\EmbeddingClient;
use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\User;
use App\Services\AI\GeminiChatAdapter;
use App\Services\AI\GeminiEmbeddingAdapter;
use App\Services\Marketplace\GeminiListingDiscoveryProvider;
use App\Services\Marketplace\ListingCandidateReviewService;
use App\Services\Marketplace\ListingDiscoveryService;
use App\Services\Marketplace\TavilyListingDiscoveryProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TavilyListingDiscoveryProviderTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_URL = 'https://api.tavily.test';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'cache.default' => 'array',
            'listing-discovery.provider' => 'tavily',
            'listing-discovery.tavily.api_key' => 'fake-tavily-key',
            'listing-discovery.tavily.base_url' => self::BASE_URL,
            'listing-discovery.tavily.search_depth' => 'basic',
            'listing-discovery.tavily.search_max_results' => 20,
            'listing-discovery.tavily.country' => 'indonesia',
            'listing-discovery.tavily.extract_enabled' => true,
            'listing-discovery.tavily.extract_depth' => 'basic',
            'listing-discovery.tavily.extract_max_urls_per_product' => 3,
            'listing-discovery.max_new_listings_per_product' => 3,
            'listing-discovery.max_per_platform_per_product' => 2,
            'listing-discovery.http_probe.enabled' => false,
            'listing-discovery.platform_fallback.enabled' => false,
            'listing-discovery.search_cache_hours' => 0,
            'listing-discovery.http.retries' => 0,
        ]);
        Cache::flush();
        $this->platforms();
    }

    public function test_provider_resolution_keeps_gemini_for_chat_and_embedding(): void
    {
        config([
            'ai.chat.provider' => 'gemini',
            'ai.embedding.provider' => 'gemini',
        ]);

        $this->assertInstanceOf(
            TavilyListingDiscoveryProvider::class,
            app(ListingDiscoveryProvider::class),
        );
        $this->assertInstanceOf(GeminiChatAdapter::class, app(ChatClient::class));
        $this->assertInstanceOf(
            GeminiEmbeddingAdapter::class,
            app(EmbeddingClient::class),
        );

        config(['listing-discovery.provider' => 'gemini']);
        $this->assertInstanceOf(
            GeminiListingDiscoveryProvider::class,
            app(ListingDiscoveryProvider::class),
        );
    }

    public function test_enabled_admin_page_shows_tavily_settings_without_calling_provider(): void
    {
        config([
            'listing-discovery.enabled' => true,
            'listing-discovery.schedule.enabled' => false,
            'listing-discovery.require_approval' => true,
            'listing-discovery.auto_import' => false,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertSeeText('Discovery Listing Marketplace Otomatis')
            ->assertSeeText('Tavily')
            ->assertSeeText('Basic')
            ->assertSeeText('3 Listing tersedia / Produk')
            ->assertSeeText('Gemini tetap digunakan untuk Chat dan Embedding')
            ->assertDontSeeText('Gemini + Google Search Grounding')
            ->assertDontSee('fake-tavily-key');

        Http::assertNothingSent();
    }

    public function test_search_and_extract_are_bounded_filtered_and_normalized(): void
    {
        $product = $this->product();
        $olxUrl = 'https://www.olx.co.id/item/vga-rtx-3060-iid-771100';
        $shopeeUrl = 'https://shopee.co.id/VGA-RTX-3060-i.12345.67890';
        $carousellUrl = 'https://id.carousell.com/p/rtx-3060-12gb-1234567890';
        Http::fake([
            self::BASE_URL.'/search' => Http::response([
                'results' => [
                    [
                        'title' => 'VGA RTX 3060 bekas',
                        'url' => $olxUrl.'?utm_source=tavily',
                        'content' => 'NVIDIA GeForce RTX 3060 12GB.',
                    ],
                    [
                        'title' => 'VGA RTX 3060 duplikat',
                        'url' => $olxUrl.'?ref=duplicate',
                        'content' => 'Listing yang sama.',
                    ],
                    [
                        'title' => 'AMD Radeon RX 6600',
                        'url' => 'https://www.olx.co.id/item/rx-6600-iid-771101',
                        'content' => 'Produk berbeda.',
                    ],
                    [
                        'title' => 'Search RTX 3060',
                        'url' => 'https://www.tokopedia.com/search?q=rtx+3060',
                        'content' => 'Search page.',
                    ],
                    [
                        'title' => 'RTX 3060 blog review',
                        'url' => 'https://example.test/review/rtx-3060',
                        'content' => 'Unsupported domain.',
                    ],
                    [
                        'title' => 'GeForce RTX 3060 Rp3,2 juta',
                        'url' => $shopeeUrl,
                        'content' => 'Ready stock.',
                    ],
                    [
                        'title' => 'Laptop gaming RTX 3060',
                        'url' => 'https://www.olx.co.id/item/laptop-rtx-3060-iid-771102',
                        'content' => 'Laptop dengan GPU RTX 3060.',
                    ],
                    [
                        'title' => 'PC rakitan RTX 3060',
                        'url' => 'https://www.olx.co.id/item/pc-rtx-3060-iid-771103',
                        'content' => 'Full PC gaming.',
                    ],
                    [
                        'title' => 'RTX 3060 12GB',
                        'url' => $carousellUrl,
                        'content' => 'Listing Carousell langsung.',
                    ],
                ],
            ]),
            self::BASE_URL.'/extract' => Http::response([
                'results' => [
                    [
                        'url' => $olxUrl,
                        'raw_content' => 'Harga Rp 3.100.000. Kondisi bekas.',
                    ],
                    [
                        'url' => $shopeeUrl,
                        'raw_content' => 'Harga 3,2 juta. Ready stock.',
                    ],
                ],
                'failed_results' => [[
                    'url' => $carousellUrl,
                    'error' => 'Page was unavailable.',
                ]],
            ]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover($product);

        $this->assertTrue($result->succeeded());
        $this->assertCount(2, $result->candidates);
        $candidates = collect($result->candidates)->keyBy('platform');
        $this->assertSame(['olx', 'shopee'], $candidates->keys()->sort()->values()->all());
        $this->assertSame(3_100_000, $candidates['olx']['price']);
        $this->assertSame(Listing::CONDITION_USED, $candidates['olx']['condition']);
        $this->assertSame(3_200_000, $candidates['shopee']['price']);
        $this->assertSame(Listing::CONDITION_UNKNOWN, $candidates['shopee']['condition']);
        Http::assertSentCount(2);
        Http::assertSent(static function ($request): bool {
            if ($request->url() !== self::BASE_URL.'/search') {
                return false;
            }

            return $request->hasHeader('Authorization', 'Bearer fake-tavily-key')
                && $request['search_depth'] === 'basic'
                && $request['topic'] === 'general'
                && $request['country'] === 'indonesia'
                && $request['max_results'] === 20
                && $request['include_answer'] === false
                && $request['include_raw_content'] === false
                && $request['include_domains'] === [
                    'tokopedia.com',
                    'shopee.co.id',
                    'olx.co.id',
                    'facebook.com',
                    'id.carousell.com',
                    'carousell.com',
                ];
        });
        Http::assertSent(static fn ($request): bool => $request->url() === self::BASE_URL.'/extract'
            && $request['extract_depth'] === 'basic'
            && $request['urls'] === [$olxUrl, $carousellUrl]);
    }

    public function test_search_evidence_prefers_platform_diversity_without_extract(): void
    {
        config([
            'listing-discovery.tavily.extract_max_urls_per_product' => 2,
            'listing-discovery.max_new_listings_per_product' => 3,
        ]);
        $product = $this->product();
        $firstOlx = 'https://www.olx.co.id/item/rtx-3060-a-iid-880001';
        $secondOlx = 'https://www.olx.co.id/item/rtx-3060-b-iid-880002';
        $facebook = 'https://www.facebook.com/marketplace/item/880003';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [
                ['title' => 'RTX 3060 A', 'url' => $firstOlx, 'content' => 'Rp 3.000.000'],
                ['title' => 'RTX 3060 B', 'url' => $secondOlx, 'content' => 'Rp 3.100.000'],
                ['title' => 'RTX 3060 Facebook', 'url' => $facebook, 'content' => 'Rp 3.200.000'],
            ]]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover($product, 2);

        $this->assertTrue($result->succeeded());
        $this->assertSame(['olx', 'facebook'], array_column(
            $result->candidates,
            'platform',
        ));
        $this->assertNull($result->candidates[0]['seller_name']);
        $this->assertNull($result->candidates[0]['location']);
        $this->assertSame(Listing::STOCK_UNKNOWN, $result->candidates[0]['stock_status']);
        Http::assertSentCount(1);
        Http::assertNotSent(static fn ($request): bool => $request->url() === self::BASE_URL.'/extract');
    }

    public function test_ambiguous_or_non_full_price_is_rejected(): void
    {
        $url = 'https://www.olx.co.id/item/rtx-3060-iid-990001';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [[
                'title' => 'VGA RTX 3060',
                'url' => $url,
                'content' => 'NVIDIA graphics card.',
            ]]]),
            self::BASE_URL.'/extract' => Http::response(['results' => [[
                'url' => $url,
                'raw_content' => 'Harga normal Rp 5.000.000, promo Rp 4.000.000 atau cicilan Rp 350.000 per bulan.',
            ]]]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame([], $result->candidates);
    }

    public function test_zero_results_and_provider_failures_are_safe(): void
    {
        $responses = Http::sequence()
            ->push(['results' => []])
            ->push([], 429)
            ->pushFailedConnection('timeout');
        Http::fake([self::BASE_URL.'/search' => $responses]);
        $zero = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
        );

        $this->assertTrue($zero->succeeded());
        $this->assertSame([], $zero->candidates);

        $limited = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product('NVIDIA GeForce RTX 3060 B'),
        );

        $this->assertFalse($limited->succeeded());
        $this->assertSame('quota_or_rate_limit', $limited->failureCategory);
        $this->assertStringNotContainsString(
            'fake-tavily-key',
            (string) $limited->failureMessage,
        );

        $connection = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product('NVIDIA GeForce RTX 3060 C'),
        );

        $this->assertFalse($connection->succeeded());
        $this->assertSame('connection', $connection->failureCategory);
        Http::assertSentCount(3);
    }

    public function test_tavily_candidate_uses_shared_validator_and_requires_approval(): void
    {
        config([
            'listing-discovery.target_active_listings' => 3,
            'listing-discovery.max_products_per_run' => 1,
        ]);
        $product = $this->product();
        $url = 'https://www.olx.co.id/item/vga-rtx-3060-iid-771122';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [[
                'title' => 'VGA NVIDIA RTX 3060 bekas Rp 3.100.000',
                'url' => $url,
                'content' => 'Standalone graphics card.',
            ]]]),
            self::BASE_URL.'/extract' => Http::response(['results' => [[
                'url' => $url,
                'raw_content' => 'Kondisi bekas. Harga Rp 3.100.000.',
            ]]]),
        ]);
        $run = ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_QUEUED,
            'provider' => 'tavily',
            'model' => 'search:basic+extract:basic',
            'region' => 'Indonesia',
        ]);
        $productsBefore = Product::query()->count();

        app(ListingDiscoveryService::class)->execute($run);

        $candidate = ListingCandidate::query()->sole();
        $this->assertSame(ListingCandidate::REVIEW_PENDING, $candidate->review_status);
        $this->assertSame(ListingCandidate::VALIDATION_VALID, $candidate->validation_status);
        $this->assertTrue((bool) data_get($candidate->raw_payload, 'validated_row.matched'));
        $this->assertDatabaseCount('listings', 0);
        $this->assertSame($productsBefore, Product::query()->count());

        $listing = app(ListingCandidateReviewService::class)->approve(
            $candidate,
            $this->admin(),
        );

        $this->assertSame($product->id, $listing->product_id);
        $this->assertDatabaseCount('listings', 1);
        $this->assertSame($productsBefore, Product::query()->count());
    }

    public function test_one_product_failure_preserves_other_candidates_and_marks_run_partial(): void
    {
        config([
            'listing-discovery.target_active_listings' => 3,
            'listing-discovery.max_products_per_run' => 2,
        ]);
        $this->product('A NVIDIA GeForce RTX 3060');
        $this->product('B NVIDIA GeForce RTX 3060');
        $url = 'https://www.olx.co.id/item/vga-rtx-3060-iid-881122';
        Http::fake([
            self::BASE_URL.'/search' => Http::sequence()
                ->push(['results' => [[
                    'title' => 'VGA RTX 3060 bekas Rp 3.100.000',
                    'url' => $url,
                    'content' => 'Standalone graphics card.',
                ]]])
                ->push([], 429),
            self::BASE_URL.'/extract' => Http::response(['results' => [[
                'url' => $url,
                'raw_content' => 'Harga Rp 3.100.000. Kondisi bekas.',
            ]]]),
        ]);
        $run = ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_QUEUED,
            'provider' => 'tavily',
            'model' => 'search:basic+extract:basic',
            'region' => 'Indonesia',
        ]);

        app(ListingDiscoveryService::class)->execute($run);
        $run->refresh();

        $this->assertSame(ListingDiscoveryRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(2, $run->products_checked);
        $this->assertSame(1, $run->provider_failures);
        $this->assertSame(1, $run->valid_candidates);
        $this->assertDatabaseCount('listing_candidates', 1);
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_http_probe_structured_offer_creates_candidate_without_extract(): void
    {
        config(['listing-discovery.http_probe.enabled' => true]);
        $url = 'https://www.olx.co.id/item/crucial-p3-plus-iid-881199';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [[
                'title' => 'VGA NVIDIA RTX 3060 12GB',
                'url' => $url,
                'content' => 'Standalone graphics card listing.',
            ]]]),
            $url => Http::response(<<<'HTML'
                <html><head>
                    <meta property="og:title" content="VGA NVIDIA RTX 3060 12GB">
                    <meta property="og:description" content="Ready stock">
                    <script type="application/ld+json">{"@type":"Product","name":"RTX 3060","offers":{"@type":"Offer","price":"4300000","priceCurrency":"IDR","availability":"https://schema.org/InStock"}}</script>
                </head></html>
                HTML, 200, ['Content-Type' => 'text/html']),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
            1,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(4_300_000, $result->candidates[0]['price']);
        $this->assertSame(Listing::STOCK_IN_STOCK, $result->candidates[0]['stock_status']);
        $this->assertSame(1, $result->diagnostics['http_probe_attempts']);
        $this->assertSame(1, $result->diagnostics['http_probe_success']);
        $this->assertSame(0, $result->diagnostics['extract_attempts']);
        Http::assertNotSent(static fn ($request): bool => $request->url() === self::BASE_URL.'/extract');
    }

    public function test_extract_failure_preserves_valid_search_evidence_candidate(): void
    {
        $shopee = 'https://shopee.co.id/RTX-3060-i.123.888001';
        $olx = 'https://www.olx.co.id/item/rtx-3060-iid-888002';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [
                [
                    'title' => 'RTX 3060 Rp 4.300.000',
                    'url' => $shopee,
                    'content' => 'Ready stock.',
                ],
                [
                    'title' => 'RTX 3060 listing',
                    'url' => $olx,
                    'content' => 'Harga tidak tercantum pada snippet.',
                ],
            ]]),
            self::BASE_URL.'/extract' => Http::response([], 503),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
            2,
        );

        $this->assertTrue($result->succeeded());
        $this->assertTrue($result->hasTechnicalFailure());
        $this->assertSame('provider_unavailable', $result->partialFailureCategory);
        $this->assertCount(1, $result->candidates);
        $this->assertSame($shopee, $result->candidates[0]['product_url']);
        $this->assertSame(1, $result->diagnostics['extract_attempts']);
        $this->assertSame(1, $result->diagnostics['rejection_reasons']['extract_unavailable']);
    }

    public function test_unknown_and_out_of_stock_are_valid_candidate_states_but_only_unknown_is_available(): void
    {
        config([
            'listing-discovery.target_active_listings' => 1,
            'listing-discovery.max_products_per_run' => 1,
        ]);
        $product = $this->product();
        $aliasesBefore = ProductAlias::query()->count();
        $url = 'https://www.olx.co.id/item/rtx-3060-out-of-stock-iid-889001';
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [[
                'title' => 'RTX 3060 Rp 4.300.000',
                'url' => $url,
                'content' => 'Sold out. Kondisi tidak disebutkan.',
            ]]]),
        ]);
        $run = ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_QUEUED,
            'provider' => 'tavily',
            'model' => 'search:basic+extract:fallback',
            'region' => 'Indonesia',
        ]);

        app(ListingDiscoveryService::class)->execute($run);
        $candidate = ListingCandidate::query()->sole();
        $this->assertSame(Listing::CONDITION_UNKNOWN, $candidate->condition);
        $this->assertSame(
            Listing::STOCK_OUT_OF_STOCK,
            data_get($candidate->raw_payload, 'provider_candidate.stock_status'),
        );

        $listing = app(ListingCandidateReviewService::class)->approve(
            $candidate,
            $this->admin(),
        );

        $this->assertSame(Listing::STOCK_OUT_OF_STOCK, $listing->stock_status);
        $this->assertSame(0, $product->listings()->available()->count());
        $this->assertSame($aliasesBefore, ProductAlias::query()->count());
    }

    public function test_indonesian_full_price_formats_are_normalized_and_installment_or_dp_are_rejected(): void
    {
        $urls = [
            'https://www.olx.co.id/item/rtx-3060-price-a-iid-890001',
            'https://shopee.co.id/RTX-3060-price-b-i.123.890002',
            'https://www.facebook.com/marketplace/item/890003',
            'https://id.carousell.com/p/rtx-3060-dp-890004',
            'https://www.tokopedia.com/neomatra/rtx-3060-installment',
        ];
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [
                ['title' => 'RTX 3060 Rp4.300.000', 'url' => $urls[0], 'content' => 'Harga tunai.'],
                ['title' => 'RTX 3060', 'url' => $urls[1], 'content' => 'Harga 4.300.000'],
                ['title' => 'RTX 3060', 'url' => $urls[2], 'content' => 'Harga 4,3 juta'],
                ['title' => 'RTX 3060 DP', 'url' => $urls[3], 'content' => 'DP Rp 500.000'],
                ['title' => 'RTX 3060 cicilan', 'url' => $urls[4], 'content' => 'Cicilan Rp 350.000 per bulan'],
            ]]),
            self::BASE_URL.'/extract' => Http::response(['results' => []]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
            5,
        );

        $this->assertTrue($result->succeeded());
        $this->assertCount(3, $result->candidates);
        $this->assertSame([4_300_000], array_values(array_unique(array_column(
            $result->candidates,
            'price',
        ))));
        $this->assertArrayHasKey('price_ambiguous', $result->diagnostics['rejection_reasons']);
    }

    public function test_sku_and_brand_model_match_while_full_pc_is_rejected(): void
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'ssd'],
            ['name' => 'SSD', 'is_active' => true],
        );
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Crucial P3 Plus NVMe SSD 2TB',
            'brand' => 'Crucial',
            'model' => 'P3 Plus',
            'variant' => '2TB',
            'sku_or_part_number' => 'CT2000P3PSSD8',
            'is_active' => true,
        ]);
        ProductAlias::query()->create([
            'product_id' => $product->id,
            'alias' => 'Crucial P3 Plus 2TB',
            'normalized_alias' => 'crucial p3 plus 2tb',
        ]);
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [
                [
                    'title' => 'SSD CT2000P3PSSD8 2TB Rp 2.100.000',
                    'url' => 'https://www.olx.co.id/item/crucial-ssd-iid-891001',
                    'content' => 'Crucial storage.',
                ],
                [
                    'title' => 'Crucial P3 Plus 2TB Rp2.200.000',
                    'url' => 'https://shopee.co.id/Crucial-P3-Plus-i.123.891002',
                    'content' => 'NVMe SSD.',
                ],
                [
                    'title' => 'PC Gaming lengkap Crucial P3 Plus 2TB Rp12.000.000',
                    'url' => 'https://www.facebook.com/marketplace/item/891003',
                    'content' => 'Full PC gaming.',
                ],
            ]]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover($product, 3);

        $this->assertTrue($result->succeeded());
        $this->assertCount(2, $result->candidates);
        $this->assertSame(1, $result->diagnostics['rejection_reasons']['full_pc_mismatch']);
        Http::assertSent(static fn ($request): bool => $request->url() === self::BASE_URL.'/search'
            && str_starts_with((string) $request['query'], 'CT2000P3PSSD8')
            && str_contains((string) $request['query'], 'Crucial P3 Plus'));
    }

    public function test_targeted_fallback_is_bounded_and_stops_when_still_needed_is_satisfied(): void
    {
        config(['listing-discovery.platform_fallback.enabled' => true]);
        $responses = Http::sequence()
            ->push(['results' => [[
                'title' => 'RTX 3060 Shopee Rp4.100.000',
                'url' => 'https://shopee.co.id/RTX-3060-i.123.892001',
                'content' => 'Ready.',
            ]]])
            ->push(['results' => [[
                'title' => 'RTX 3060 Tokopedia Rp4.200.000',
                'url' => 'https://www.tokopedia.com/neomatra/rtx-3060-892002',
                'content' => 'Ready.',
            ]]])
            ->push(['results' => [[
                'title' => 'RTX 3060 OLX Rp4.000.000',
                'url' => 'https://www.olx.co.id/item/rtx-3060-iid-892003',
                'content' => 'Ready.',
            ]]]);
        Http::fake([self::BASE_URL.'/search' => $responses]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
            3,
        );

        $this->assertTrue($result->succeeded());
        $this->assertSame(['olx', 'shopee', 'tokopedia'], collect(
            array_column($result->candidates, 'platform'),
        )->sort()->values()->all());
        $this->assertSame(1, $result->diagnostics['primary_searches']);
        $this->assertSame(2, $result->diagnostics['fallback_searches']);
        Http::assertSentCount(3);
        Http::assertSent(static fn ($request): bool => $request->url() === self::BASE_URL.'/search'
            && $request['include_domains'] === [
                'tokopedia.com',
                'shopee.co.id',
                'olx.co.id',
                'facebook.com',
                'id.carousell.com',
                'carousell.com',
            ]);
    }

    public function test_primary_search_stops_fallback_when_coverage_need_is_met(): void
    {
        config(['listing-discovery.platform_fallback.enabled' => true]);
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [
                ['title' => 'RTX 3060 A Rp4.100.000', 'url' => 'https://www.olx.co.id/item/rtx-a-iid-893001', 'content' => 'Ready'],
                ['title' => 'RTX 3060 B Rp4.200.000', 'url' => 'https://shopee.co.id/RTX-B-i.123.893002', 'content' => 'Ready'],
                ['title' => 'RTX 3060 C Rp4.300.000', 'url' => 'https://www.facebook.com/marketplace/item/893003', 'content' => 'Ready'],
            ]]),
        ]);

        $result = app(TavilyListingDiscoveryProvider::class)->discover(
            $this->product(),
            3,
        );

        $this->assertCount(3, $result->candidates);
        $this->assertSame(0, $result->diagnostics['fallback_searches']);
        Http::assertSentCount(1);
    }

    public function test_search_evidence_cache_avoids_repaying_for_the_same_query(): void
    {
        config(['listing-discovery.search_cache_hours' => 24]);
        Http::fake([
            self::BASE_URL.'/search' => Http::response(['results' => [[
                'title' => 'RTX 3060 Rp4.300.000',
                'url' => 'https://www.olx.co.id/item/rtx-cache-iid-894001',
                'content' => 'Ready.',
            ]]]),
        ]);
        $provider = app(TavilyListingDiscoveryProvider::class);
        $product = $this->product();

        $first = $provider->discover($product, 1);
        $second = $provider->discover($product, 1);

        $this->assertCount(1, $first->candidates);
        $this->assertCount(1, $second->candidates);
        $this->assertSame(1, $second->diagnostics['cache_hits']);
        Http::assertSentCount(1);
    }

    private function product(
        string $name = 'NVIDIA GeForce RTX 3060',
    ): Product {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'gpu'],
            ['name' => 'GPU', 'is_active' => true],
        );
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12GB',
            'is_active' => true,
        ]);
        ProductAlias::query()->create([
            'product_id' => $product->id,
            'alias' => 'RTX 3060',
            'normalized_alias' => 'rtx 3060',
            'is_active' => true,
        ]);

        return $product;
    }

    private function platforms(): void
    {
        foreach (Platform::MVP_SLUGS as $slug) {
            Platform::query()->create([
                'name' => ucfirst($slug),
                'slug' => $slug,
                'source_type' => Platform::SOURCE_MANUAL,
                'access_type' => Platform::ACCESS_CSV_IMPORT,
                'connector' => 'manual-import',
                'status' => Platform::STATUS_READY,
                'is_active' => true,
                'sync_enabled' => false,
            ]);
        }
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin Tavily',
            'email' => 'admin-tavily@example.test',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }
}
