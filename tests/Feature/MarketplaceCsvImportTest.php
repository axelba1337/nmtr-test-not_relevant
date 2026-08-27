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
}
