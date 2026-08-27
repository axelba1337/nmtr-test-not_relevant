<?php

namespace Tests\Feature;

use App\Data\AI\ParsedIntent;
use App\Data\Marketplace\MarketplaceListingData;
use App\Data\Marketplace\MarketplaceSyncReport;
use App\Data\Marketplace\MarketplaceSyncRequest;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Marketplace\MarketplaceConnectorRegistry;
use App\Services\Marketplace\MarketplaceProductMatcher;
use App\Services\Marketplace\MarketplaceSyncService;
use App\Services\Marketplace\OlxMarketplaceConnector;
use App\Services\Search\ListingSearchService;
use Database\Seeders\NeomatraCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OlxMarketplaceConnectorTest extends TestCase
{
    use RefreshDatabase;

    private int $olxDetailIndex = 0;

    private const SEARCH_URL = 'https://www.olx.co.id/items/q-rtx-3060-12gb';

    private const DETAIL_URL = 'https://www.olx.co.id/item/vga-nvidia-rtx-3060-12gb-iid-947122632';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'marketplace.olx.base_url' => 'https://www.olx.co.id',
            'marketplace.olx.search_terms' => ['rtx 3060 12gb'],
            'marketplace.olx.max_listings' => 20,
            'marketplace.olx.timeout' => 2,
            'marketplace.olx.retries' => 0,
            'marketplace.olx.retry_delay_ms' => 0,
        ]);
        Http::preventStrayRequests();
    }

    public function test_connector_supports_only_olx_and_is_not_a_full_snapshot(): void
    {
        $connector = app(OlxMarketplaceConnector::class);

        $this->assertSame('olx-public-web', $connector->key());
        $this->assertTrue($connector->supports('olx'));
        $this->assertTrue($connector->supports('OLX'));
        $this->assertFalse($connector->supports('tokopedia'));
        $this->assertFalse($connector->providesFullSnapshot());
    }

    public function test_connector_parses_public_json_ld_and_deduplicates_urls(): void
    {
        $this->fakeOlx(3_100_000);

        $result = app(OlxMarketplaceConnector::class)->fetch(
            new MarketplaceSyncRequest('olx'),
        );

        $this->assertTrue($result->succeeded());
        $this->assertCount(1, $result->listings);
        $listing = $result->listings[0];
        $this->assertSame('947122632', $listing->externalListingId);
        $this->assertSame(3_100_000, $listing->price);
        $this->assertSame('IDR', $listing->currency);
        $this->assertSame(Listing::CONDITION_USED, $listing->condition);
        $this->assertSame('Basra', $listing->sellerName);
        $this->assertSame('Duren Sawit, Jakarta Timur', $listing->location);
        $this->assertSame(self::DETAIL_URL, $listing->productUrl);
        Http::assertSentCount(2);
    }

    public function test_connector_rejects_an_invalid_price_record(): void
    {
        $this->fakeOlx(0);

        $result = app(OlxMarketplaceConnector::class)->fetch(
            new MarketplaceSyncRequest('olx'),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('parse_failure', $result->failure?->category);
    }

    public function test_connection_failures_preserve_the_curl_reason(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::failedConnection(
                'cURL error 60: SSL certificate problem',
            ),
        ]);

        $result = app(OlxMarketplaceConnector::class)->fetch(
            new MarketplaceSyncRequest('olx'),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('tls_failure', $result->failure?->category);
        $this->assertSame('curl_60', $result->failure?->upstreamCode);
    }

    public function test_http2_reset_is_reported_precisely(): void
    {
        Http::fake([
            self::SEARCH_URL => Http::failedConnection(
                'cURL error 92: HTTP/2 stream 1 reset by server',
            ),
        ]);

        $result = app(OlxMarketplaceConnector::class)->fetch(
            new MarketplaceSyncRequest('olx'),
        );

        $this->assertFalse($result->succeeded());
        $this->assertSame('http2_reset', $result->failure?->category);
        $this->assertSame('curl_92', $result->failure?->upstreamCode);
    }

    public function test_olx_sync_creates_then_updates_without_duplicates(): void
    {
        $platform = $this->olxPlatform();
        $initialCount = Listing::query()->count();
        $this->fakeOlxSequence([3_100_000, 2_950_000, 2_950_000]);

        $first = app(MarketplaceSyncService::class)->sync($platform, 1);

        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $first->status);
        $this->assertSame(1, $first->created);
        $this->assertDatabaseCount('listings', $initialCount + 1);
        $listing = Listing::query()
            ->where('external_listing_id', '947122632')
            ->firstOrFail();
        $this->assertSame('RTX 3060', $listing->product?->model);
        $this->assertSame('olx-public-web', $listing->raw_payload['connector']);
        $this->assertFalse($listing->raw_payload['demo']);

        $second = app(MarketplaceSyncService::class)->sync($platform->fresh(), 1);
        $third = app(MarketplaceSyncService::class)->sync($platform->fresh(), 1);

        $this->assertSame(1, $second->updated);
        $this->assertSame(1, $third->unchanged);
        $this->assertDatabaseCount('listings', $initialCount + 1);
        $this->assertSame(2_950_000, $listing->fresh()?->price);
    }

    public function test_matcher_accepts_standalone_gpu_but_rejects_laptop(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $matcher = app(MarketplaceProductMatcher::class);
        $standalone = $this->listingData(
            'VGA Nvidia RTX 3060 12GB MSI Ventus 2X OC',
        );
        $laptop = $this->listingData(
            'Acer Nitro 5 RTX 3060 16GB SSD 512GB',
            'https://www.olx.co.id/item/acer-nitro-iid-100002',
        );

        $this->assertSame('RTX 3060', $matcher->match($standalone)?->model);
        $this->assertNull($matcher->match($laptop));
    }

    public function test_unmatched_listing_remains_without_a_product(): void
    {
        $platform = $this->olxPlatform();
        $this->fakeOlx(
            1_500_000,
            'Perangkat elektronik langka model XQZ 9000',
        );

        $report = app(MarketplaceSyncService::class)->sync($platform, 1);

        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $report->status);
        $this->assertDatabaseHas('listings', [
            'platform_id' => $platform->id,
            'external_listing_id' => '947122632',
            'product_id' => null,
        ]);
    }

    public function test_database_search_can_consume_a_synced_olx_listing(): void
    {
        $platform = $this->olxPlatform();
        Listing::query()->where('platform_id', $platform->id)->update([
            'is_active' => false,
        ]);
        $this->fakeOlx(3_100_000);
        app(MarketplaceSyncService::class)->sync($platform, 1);
        $product = Product::query()->where('model', 'RTX 3060')->firstOrFail();
        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060 12GB',
            'category' => 'GPU',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB',
            'conditions' => ['used'],
            'platforms' => ['olx'],
            'seller_types' => [],
            'locations' => [],
            'mode' => 'buyer',
            'needs_clarification' => false,
            'clarification_question' => null,
            'intent_type' => 'product_search',
        ]);
        $this->assertNotNull($intent);

        $results = app(ListingSearchService::class)->search([
            [
                'product' => $product,
                'similarity' => null,
                'matched_via' => 'deterministic_product',
            ],
        ], $intent);

        $this->assertCount(1, $results);
        $this->assertSame('OLX', $results[0]['platform']);
        $this->assertSame(self::DETAIL_URL, $results[0]['source_url']);
        $this->assertSame(3_100_000, $results[0]['item_price']);
    }

    public function test_registry_routes_olx_to_real_connector_and_keeps_demo(): void
    {
        $registry = app(MarketplaceConnectorRegistry::class);

        $this->assertInstanceOf(
            OlxMarketplaceConnector::class,
            $registry->find('olx-public-web', 'olx'),
        );
        $this->assertNotNull($registry->find('demo-dataset', 'tokopedia'));
        $this->assertNull($registry->find('olx-public-web', 'shopee'));
    }

    public function test_probe_reports_connector_results_without_writing_listings(): void
    {
        $this->fakeOlx(3_100_000);
        $before = Listing::query()->count();

        $exitCode = $this->artisan('marketplace:probe', ['platform' => 'olx']);

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, Listing::query()->count());
    }

    private function olxPlatform(): Platform
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        $platform->forceFill([
            'source_type' => Platform::SOURCE_SCRAPE,
            'access_type' => Platform::ACCESS_PUBLIC_HTML,
            'connector' => 'olx-public-web',
            'sync_enabled' => true,
            'status' => Platform::STATUS_READY,
        ])->save();

        return $platform;
    }

    private function fakeOlx(
        int $price,
        string $title = 'VGA Nvidia RTX 3060 12GB MSI Ventus 2X OC',
    ): void {
        $this->fakeOlxSequence([$price], $title);
    }

    /** @param list<int> $prices */
    private function fakeOlxSequence(
        array $prices,
        string $title = 'VGA Nvidia RTX 3060 12GB MSI Ventus 2X OC',
    ): void {
        $search = '<html><body>'
            .'<a href="/item/vga-nvidia-rtx-3060-12gb-iid-947122632">Listing</a>'
            .'<a href="'.self::DETAIL_URL.'">Duplikat</a>'
            .'</body></html>';
        $this->olxDetailIndex = 0;

        Http::fake(function ($request) use (
            $search,
            $prices,
            $title,
        ) {
            if ($request->url() === self::SEARCH_URL) {
                return Http::response($search);
            }

            $price = $prices[min($this->olxDetailIndex, count($prices) - 1)];
            $this->olxDetailIndex++;
            $schema = json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $title,
                'description' => 'Kondisi bekas, barang pribadi.',
                'image' => ['https://apollo.olx.co.id/image.jpg'],
                'offers' => [
                    '@type' => 'Offer',
                    'price' => $price,
                    'priceCurrency' => 'IDR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $detail = '<html><head>'
                .'<script type="application/ld+json">'.$schema.'</script>'
                .'<meta property="article:published_time" content="2026-08-20T10:00:00+07:00">'
                .'</head><body>'
                .'<div data-aut-id="itemCondition">Bekas</div>'
                .'<div data-aut-id="itemSellerName">Basra</div>'
                .'<div data-aut-id="item-location">Duren Sawit, Jakarta Timur</div>'
                .'</body></html>';

            return Http::response($detail);
        });
    }

    private function listingData(
        string $title,
        string $url = self::DETAIL_URL,
    ): MarketplaceListingData {
        $listing = MarketplaceListingData::fromArray('olx', [
            'title' => $title,
            'description' => 'Kondisi bekas.',
            'price' => 3_000_000,
            'currency' => 'IDR',
            'condition' => 'used',
            'product_url' => $url,
            'stock_status' => 'in_stock',
            'price_type' => 'full_price',
        ]);

        $this->assertInstanceOf(MarketplaceListingData::class, $listing);

        return $listing;
    }
}
