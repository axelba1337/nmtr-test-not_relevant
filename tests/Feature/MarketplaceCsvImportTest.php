<?php

namespace Tests\Feature;

use App\Data\AI\ParsedIntent;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Search\ListingSearchService;
use Database\Seeders\NeomatraCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketplaceCsvImportTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NeomatraCatalogSeeder::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_valid_csv_imports_and_matches_a_canonical_product(): void
    {
        $path = $this->csv([
            $this->row(),
        ]);

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $path,
        ]));

        $listing = Listing::query()
            ->where('external_listing_id', 'REAL-OLX-RTX3060-001')
            ->firstOrFail();
        $this->assertSame('RTX 3060', $listing->product?->model);
        $this->assertSame('manual-import', $listing->raw_payload['connector']);
        $this->assertSame('csv', $listing->raw_payload['import_source']);
        $this->assertFalse($listing->raw_payload['demo']);
        $this->assertTrue($listing->is_active);
        $this->assertStringContainsString('olx.co.id', $listing->product_url);
    }

    public function test_facebook_accepts_only_marketplace_item_urls(): void
    {
        $valid = $this->rowForPlatform(
            'facebook',
            'REAL-FB-RTX3060-001',
            'https://www.facebook.com/marketplace/item/123456789012345?ref=search',
        );
        $invalid = [];

        foreach (['groups/123', 'posts/123', 'photo/123'] as $index => $path) {
            $invalid[] = $this->rowForPlatform(
                'facebook',
                'INVALID-FB-'.($index + 1),
                'https://www.facebook.com/'.$path,
            );
        }

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv([$valid, ...$invalid]),
        ]));

        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'REAL-FB-RTX3060-001',
            'product_url' => 'https://www.facebook.com/marketplace/item/123456789012345',
        ]);
        $this->assertSame(
            0,
            Listing::query()
                ->where('external_listing_id', 'like', 'INVALID-FB-%')
                ->count(),
        );
    }

    public function test_carousell_accepts_only_direct_listing_urls(): void
    {
        $valid = $this->rowForPlatform(
            'carousell',
            'REAL-CAROUSELL-RTX3060-001',
            'https://id.carousell.com/p/nvidia-rtx-3060-12gb-1234567890/',
        );
        $invalid = [];

        foreach (['/', '/search/rtx-3060', '/categories/computer-4', '/p/'] as $index => $path) {
            $invalid[] = $this->rowForPlatform(
                'carousell',
                'INVALID-CAROUSELL-'.($index + 1),
                'https://id.carousell.com'.$path,
            );
        }

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv([$valid, ...$invalid]),
        ]));

        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'REAL-CAROUSELL-RTX3060-001',
            'product_url' => 'https://id.carousell.com/p/nvidia-rtx-3060-12gb-1234567890',
        ]);
        $this->assertSame(
            0,
            Listing::query()
                ->where('external_listing_id', 'like', 'INVALID-CAROUSELL-%')
                ->count(),
        );
    }

    public function test_invalid_price_url_and_unknown_platform_are_rejected(): void
    {
        Platform::query()->where('slug', 'shopee')->delete();
        $invalidPrice = $this->row();
        $invalidPrice[4] = 'Rp 3.000.000';
        $invalidUrl = $this->row();
        $invalidUrl[1] = 'INVALID-URL';
        $invalidUrl[10] = 'https://example.test/fake';
        $unknownPlatform = $this->row();
        $unknownPlatform[0] = 'shopee';
        $unknownPlatform[1] = 'UNKNOWN-PLATFORM';
        $unknownPlatform[10] = 'https://shopee.co.id/real-listing';
        $path = $this->csv([
            $invalidPrice,
            $invalidUrl,
            $unknownPlatform,
        ]);

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $path,
        ]));

        $this->assertDatabaseMissing('listings', [
            'external_listing_id' => 'REAL-OLX-RTX3060-001',
        ]);
        $this->assertDatabaseMissing('listings', [
            'external_listing_id' => 'INVALID-URL',
        ]);
        $this->assertDatabaseMissing('listings', [
            'external_listing_id' => 'UNKNOWN-PLATFORM',
        ]);
    }

    public function test_duplicate_import_is_idempotent_and_existing_price_updates(): void
    {
        $initialCount = Listing::query()->count();
        $path = $this->csv([$this->row()]);

        $this->assertSame(0, $this->artisan('marketplace:import', ['path' => $path]));
        $this->assertSame(0, $this->artisan('marketplace:import', ['path' => $path]));
        $this->assertDatabaseCount('listings', $initialCount + 1);

        $updated = $this->row();
        $updated[4] = '2950000';
        $updatedPath = $this->csv([$updated]);
        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $updatedPath,
        ]));

        $this->assertSame(
            2_950_000,
            Listing::query()
                ->where('external_listing_id', 'REAL-OLX-RTX3060-001')
                ->value('price'),
        );
        $this->assertDatabaseCount('listings', $initialCount + 1);
    }

    public function test_same_url_with_a_different_external_id_is_rejected(): void
    {
        $initialCount = Listing::query()->count();
        $first = $this->rowForPlatform(
            'facebook',
            'REAL-FB-DUPLICATE-001',
            'https://facebook.com/marketplace/item/998877665544332',
        );
        $duplicate = $first;
        $duplicate[1] = 'REAL-FB-DUPLICATE-002';
        $duplicate[10] .= '?tracking=duplicate';

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv([$first]),
        ]));
        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv([$duplicate]),
        ]));

        $this->assertDatabaseCount('listings', $initialCount + 1);
        $this->assertDatabaseMissing('listings', [
            'external_listing_id' => 'REAL-FB-DUPLICATE-002',
        ]);
    }

    public function test_ambiguous_laptop_listing_remains_unmatched(): void
    {
        $row = $this->row();
        $row[1] = 'REAL-OLX-LAPTOP-001';
        $row[2] = 'Acer Nitro 5 Laptop RTX 3060 16GB SSD 512GB';
        $row[10] = 'https://www.olx.co.id/item/acer-nitro-real-iid-900002';
        $path = $this->csv([$row]);

        $this->assertSame(0, $this->artisan('marketplace:import', ['path' => $path]));
        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'REAL-OLX-LAPTOP-001',
            'product_id' => null,
        ]);
    }

    public function test_accessories_services_wanted_ads_and_bundles_remain_unmatched(): void
    {
        $titles = [
            'Box only VGA Nvidia RTX 3060 12GB',
            'Jasa service dan repair RTX 3060',
            'Dicari WTB Nvidia RTX 3060 12GB',
            'Bundle PC gaming dengan RTX 3060 12GB',
            'Bracket dan fan only RTX 3060',
        ];
        $rows = [];

        foreach ($titles as $index => $title) {
            $row = $this->rowForPlatform(
                'olx',
                'UNMATCHED-'.($index + 1),
                'https://www.olx.co.id/item/unmatched-'.($index + 1),
            );
            $row[2] = $title;
            $rows[] = $row;
        }

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv($rows),
        ]));

        $this->assertSame(
            count($titles),
            Listing::query()
                ->where('external_listing_id', 'like', 'UNMATCHED-%')
                ->whereNull('product_id')
                ->count(),
        );
    }

    public function test_search_consumes_manual_import_when_automated_platform_is_in_error(): void
    {
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        $platform->forceFill([
            'sync_enabled' => false,
            'status' => Platform::STATUS_ERROR,
        ])->save();
        Listing::query()->where('platform_id', $platform->id)->update([
            'is_active' => false,
        ]);
        $path = $this->csv([$this->row()]);
        $this->assertSame(0, $this->artisan('marketplace:import', ['path' => $path]));
        $product = Product::query()->where('model', 'RTX 3060')->firstOrFail();
        $intent = ParsedIntent::fromArray([
            'product' => 'RTX 3060',
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
        $this->assertSame(3_100_000, $results[0]['item_price']);
        $this->assertSame(
            'https://www.olx.co.id/item/vga-nvidia-rtx-3060-12gb-real-iid-900001',
            $results[0]['source_url'],
        );
    }

    public function test_manual_imports_from_all_five_platforms_are_searchable(): void
    {
        $rows = [
            $this->rowForPlatform('olx', 'SEARCH-OLX-001', 'https://www.olx.co.id/item/rtx-3060-search-iid-100001'),
            $this->rowForPlatform('tokopedia', 'SEARCH-TOKOPEDIA-001', 'https://www.tokopedia.com/neomatra/rtx-3060-search'),
            $this->rowForPlatform('shopee', 'SEARCH-SHOPEE-001', 'https://shopee.co.id/rtx-3060-search-i.123.456'),
            $this->rowForPlatform('facebook', 'SEARCH-FACEBOOK-001', 'https://www.facebook.com/marketplace/item/112233445566778'),
            $this->rowForPlatform('carousell', 'SEARCH-CAROUSELL-001', 'https://carousell.com/p/rtx-3060-search-1122334455'),
        ];

        $this->assertSame(0, $this->artisan('marketplace:import', [
            'path' => $this->csv($rows),
        ]));
        $product = Product::query()->where('model', 'RTX 3060')->firstOrFail();

        foreach (['olx', 'tokopedia', 'shopee', 'facebook', 'carousell'] as $slug) {
            $intent = ParsedIntent::fromArray([
                'product' => 'RTX 3060',
                'category' => 'GPU',
                'brand' => 'NVIDIA',
                'model' => 'RTX 3060',
                'variant' => '12 GB',
                'conditions' => ['used'],
                'platforms' => [$slug],
                'seller_types' => [],
                'locations' => [],
                'mode' => 'buyer',
                'needs_clarification' => false,
                'clarification_question' => null,
                'intent_type' => 'product_search',
            ]);
            $this->assertNotNull($intent);
            $results = app(ListingSearchService::class)->search([[
                'product' => $product,
                'similarity' => null,
                'matched_via' => 'deterministic_product',
            ]], $intent);

            $this->assertNotEmpty($results, "Platform {$slug} tidak searchable.");
            $this->assertTrue(collect($results)->contains(
                static fn (array $result): bool => str_contains(
                    strtolower((string) $result['platform']),
                    $slug === 'facebook' ? 'facebook' : $slug,
                ),
            ));
        }
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows): string
    {
        $directory = storage_path('app/imports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/test-'.bin2hex(random_bytes(8)).'.csv';
        $handle = fopen($path, 'wb');
        $this->assertNotFalse($handle);
        fputcsv($handle, [
            'platform',
            'external_listing_id',
            'title',
            'description',
            'price',
            'currency',
            'condition',
            'seller_name',
            'seller_type',
            'location',
            'product_url',
            'image_url',
            'stock_status',
            'price_type',
            'warranty',
            'source_published_at',
        ], ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }

        fclose($handle);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    /** @return list<string> */
    private function row(): array
    {
        return [
            'olx',
            'REAL-OLX-RTX3060-001',
            'VGA Nvidia RTX 3060 12GB MSI Ventus 2X',
            'Listing marketplace nyata untuk pengujian importer.',
            '3100000',
            'IDR',
            'used',
            'Seller Jakarta',
            'individual',
            'Jakarta Timur',
            'https://www.olx.co.id/item/vga-nvidia-rtx-3060-12gb-real-iid-900001',
            '',
            'in_stock',
            'full_price',
            '',
            '2026-08-20T10:00:00+07:00',
        ];
    }

    /** @return list<string> */
    private function rowForPlatform(
        string $platform,
        string $externalId,
        string $url,
    ): array {
        $row = $this->row();
        $row[0] = $platform;
        $row[1] = $externalId;
        $row[10] = $url;

        return $row;
    }
}
