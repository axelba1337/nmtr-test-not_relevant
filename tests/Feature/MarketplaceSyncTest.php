<?php

namespace Tests\Feature;

use App\Data\Marketplace\MarketplaceSyncReport;
use App\Models\Listing;
use App\Models\MarketplaceSyncRun;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Marketplace\MarketplaceSyncService;
use Database\Seeders\NeomatraCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Command\Command;
use Tests\TestCase;

final class MarketplaceSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'array',
            'marketplace.provider' => 'demo',
            'marketplace.sync_mode' => MarketplaceSyncRun::MODE_MANUAL,
            'marketplace.targets' => ['tokopedia', 'shopee', 'olx'],
            'marketplace.page_size' => 100,
            'marketplace.max_pages' => 10,
            'marketplace.lock_seconds' => 60,
        ]);
        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_listing_schema_supports_acquisition_freshness(): void
    {
        $this->assertTrue(Schema::hasColumns('listings', [
            'source_published_at',
            'first_seen_at',
            'last_seen_at',
            'last_synced_at',
        ]));
    }

    public function test_demo_sync_is_idempotent_and_preserves_product_mapping(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $initialCount = Listing::query()->count();
        $sync = app(MarketplaceSyncService::class);

        $first = $sync->sync($platform);
        $second = $sync->sync($platform->fresh());

        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $first->status);
        $this->assertSame(1, $first->seen);
        $this->assertSame(
            1,
            $first->created + $first->updated + $first->unchanged,
        );
        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $second->status);
        $this->assertSame(1, $second->unchanged);
        $this->assertSame($initialCount, Listing::query()->count());
        $this->assertSame(
            $initialCount,
            Listing::query()->whereNotNull('product_id')->count(),
        );
        $this->assertDatabaseCount('marketplace_sync_runs', 2);
        $this->assertDatabaseHas('marketplace_sync_runs', [
            'platform_id' => $platform->id,
            'mode' => MarketplaceSyncRun::MODE_MANUAL,
            'status' => MarketplaceSyncRun::STATUS_COMPLETED,
            'records_seen' => 1,
        ]);
        Http::assertNothingSent();
    }

    public function test_sync_creates_updates_then_leaves_the_same_record_unchanged(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $product = Product::query()
            ->where('model', 'RTX 3060')
            ->where('variant', '12 GB')
            ->firstOrFail();
        $initialCount = Listing::query()->count();
        $record = $this->newDemoRecord(
            (string) $product->normalized_key,
            2700000,
        );
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);

        $created = $sync->sync($platform);

        $this->assertSame(1, $created->created);
        $this->assertDatabaseCount('listings', $initialCount + 1);
        $this->assertDatabaseHas('listings', [
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => 'demo-sync-new-001',
            'price' => 2700000,
            'condition' => Listing::CONDITION_USED,
            'seller_type' => Listing::SELLER_STORE,
        ]);

        $record['price'] = 2650000;
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $updated = $sync->sync($platform->fresh());
        $unchanged = $sync->sync($platform->fresh());

        $this->assertSame(1, $updated->updated);
        $this->assertSame(1, $unchanged->unchanged);
        $this->assertDatabaseCount('listings', $initialCount + 1);
        $this->assertDatabaseHas('listings', [
            'external_listing_id' => 'demo-sync-new-001',
            'price' => 2650000,
        ]);
        $listing = Listing::query()
            ->where('external_listing_id', 'demo-sync-new-001')
            ->firstOrFail();
        $this->assertSame(
            (string) $product->normalized_key,
            $listing->raw_payload['product_reference'],
        );
        $this->assertTrue($listing->raw_payload['demo']);
        $this->get(route('listings.open', $listing))
            ->assertRedirect($record['product_url']);

        $product->update(['is_active' => false]);
        $this->get(route('listings.open', $listing))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_sync_preserves_first_seen_and_refreshes_last_seen(): void
    {
        $this->travelTo('2026-08-16 09:00:00');
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $product = Product::query()
            ->where('model', 'RTX 3060')
            ->where('variant', '12 GB')
            ->firstOrFail();
        $record = $this->newDemoRecord(
            (string) $product->normalized_key,
            2700000,
        );
        $record['catalog_marker'] = 'source-payload-preserved';
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);

        $sync->sync($platform);
        $listing = Listing::query()
            ->where('external_listing_id', 'demo-sync-new-001')
            ->firstOrFail();
        $firstSeenAt = $listing->first_seen_at?->toDateTimeString();

        $this->assertSame('2026-08-16 09:00:00', $firstSeenAt);
        $this->assertSame(
            'source-payload-preserved',
            $listing->raw_payload['source_payload']['catalog_marker'],
        );

        $this->travel(5)->minutes();
        $report = $sync->sync($platform->fresh());
        $listing->refresh();

        $this->assertSame(1, $report->unchanged);
        $this->assertSame($firstSeenAt, $listing->first_seen_at?->toDateTimeString());
        $this->assertSame(
            '2026-08-16 09:05:00',
            $listing->last_seen_at?->toDateTimeString(),
        );
        $this->assertSame(
            '2026-08-16 09:05:00',
            $listing->last_synced_at?->toDateTimeString(),
        );
        Http::assertNothingSent();
    }

    public function test_external_id_enrichment_reuses_the_url_listing(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $record = $this->newDemoRecord('unknown', 2700000);
        unset($record['product_reference'], $record['external_listing_id']);
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);

        $first = $sync->sync($platform);
        $countAfterFirst = Listing::query()->count();

        $record['external_listing_id'] = 'enriched-external-id';
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $second = $sync->sync($platform->fresh());

        $this->assertSame(1, $first->created);
        $this->assertSame(1, $second->updated);
        $this->assertDatabaseCount('listings', $countAfterFirst);
        $this->assertDatabaseHas('listings', [
            'platform_id' => $platform->id,
            'external_listing_id' => 'enriched-external-id',
        ]);
        Http::assertNothingSent();
    }

    public function test_full_sync_deactivates_missing_connector_listings(): void
    {
        $this->travelTo('2026-08-16 10:00:00');
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $record = $this->newDemoRecord('unknown', 2700000);
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);
        $sync->sync($platform);
        $listing = Listing::query()
            ->where('external_listing_id', 'demo-sync-new-001')
            ->firstOrFail();

        $this->travel(5)->minutes();
        config(['marketplace.demo.listings.tokopedia' => []]);
        $report = $sync->sync($platform->fresh());

        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $report->status);
        $this->assertFalse((bool) $listing->fresh()?->is_active);
        $this->assertSame(
            '2026-08-16 10:00:00',
            $listing->fresh()?->last_seen_at?->toDateTimeString(),
        );
        Http::assertNothingSent();
    }

    public function test_invalid_snapshot_does_not_deactivate_history(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $record = $this->newDemoRecord('unknown', 2700000);
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);
        $sync->sync($platform);
        $listing = Listing::query()
            ->where('external_listing_id', 'demo-sync-new-001')
            ->firstOrFail();
        config([
            'marketplace.demo.listings.tokopedia' => [[
                'title' => 'Invalid snapshot row',
                'price' => 1000000,
                'product_url' => 'not-a-url',
            ]],
        ]);

        $report = $sync->sync($platform->fresh());

        $this->assertSame(1, $report->skipped);
        $this->assertTrue((bool) $listing->fresh()?->is_active);
        Http::assertNothingSent();
    }

    public function test_unique_product_alias_maps_a_listing_without_hints(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $product = Product::query()
            ->where('model', 'RTX 3060')
            ->where('variant', '12 GB')
            ->firstOrFail();
        $alias = $product->aliases()->firstOrFail();
        $record = $this->newDemoRecord('unused', 2700000);
        unset($record['product_reference']);
        $record['title'] = $alias->alias;
        config(['marketplace.demo.listings.tokopedia' => [$record]]);

        app(MarketplaceSyncService::class)->sync($platform);

        $this->assertDatabaseHas('listings', [
            'platform_id' => $platform->id,
            'external_listing_id' => 'demo-sync-new-001',
            'product_id' => $product->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_invalid_demo_rows_are_skipped_without_breaking_sync(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $initialCount = Listing::query()->count();
        config([
            'marketplace.demo.listings.tokopedia' => [[
                'external_listing_id' => 'unsafe-demo-row',
                'title' => 'Invalid URL row',
                'price' => 1000000,
                'product_url' => 'javascript:alert(1)',
            ]],
        ]);

        $report = app(MarketplaceSyncService::class)->sync($platform);

        $this->assertSame(MarketplaceSyncReport::STATUS_COMPLETED, $report->status);
        $this->assertSame(1, $report->seen);
        $this->assertSame(1, $report->skipped);
        $this->assertSame(0, $report->created);
        $this->assertSame($initialCount, Listing::query()->count());
        $this->assertDatabaseHas('marketplace_sync_runs', [
            'platform_id' => $platform->id,
            'records_seen' => 1,
            'records_skipped' => 1,
            'records_failed' => 0,
        ]);
        Http::assertNothingSent();
    }

    public function test_explicit_unmatched_product_hints_remove_a_stale_mapping(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $product = Product::query()
            ->where('model', 'RTX 3060')
            ->where('variant', '12 GB')
            ->firstOrFail();
        $record = $this->newDemoRecord(
            (string) $product->normalized_key,
            2700000,
        );
        $record['seller_type'] = 'NVIDIA Official Store';
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync = app(MarketplaceSyncService::class);
        $sync->sync($platform);
        $listing = Listing::query()
            ->where('external_listing_id', 'demo-sync-new-001')
            ->firstOrFail();
        $this->assertSame($product->id, $listing->product_id);
        $this->assertSame(Listing::SELLER_OFFICIAL, $listing->seller_type);

        $record['product_reference'] = 'unknown|product|variant';
        config(['marketplace.demo.listings.tokopedia' => [$record]]);
        $sync->sync($platform->fresh());

        $this->assertNull($listing->fresh()?->product_id);
        Http::assertNothingSent();
    }

    public function test_demo_connector_rejects_zero_or_out_of_domain_prices(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $record = $this->newDemoRecord('unknown', 1);
        $zero = [...$record, 'external_listing_id' => 'zero', 'price' => 0];
        $tooLarge = [
            ...$record,
            'external_listing_id' => 'too-large',
            'price' => (string) (Listing::MAX_MONEY + 1),
        ];
        config([
            'marketplace.demo.listings.tokopedia' => [$zero, $tooLarge],
        ]);

        $report = app(MarketplaceSyncService::class)->sync($platform);

        $this->assertSame(2, $report->seen);
        $this->assertSame(2, $report->skipped);
        $this->assertSame(0, $report->created);
        Http::assertNothingSent();
    }

    public function test_lock_prevents_a_duplicate_sync_request(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $lock = Cache::lock('marketplace-sync:'.$platform->id, 60);
        $this->assertTrue($lock->get());

        try {
            $report = app(MarketplaceSyncService::class)->sync($platform);
        } finally {
            $lock->release();
        }

        $this->assertSame(MarketplaceSyncReport::STATUS_FAILED, $report->status);
        $this->assertSame('already_running', $report->errorCode);
        $this->assertSame(
            'Sinkronisasi platform ini sedang berjalan.',
            $report->errorMessage,
        );
        $this->assertDatabaseCount('marketplace_sync_runs', 0);
        Http::assertNothingSent();
    }

    public function test_disabled_platform_cannot_sync_or_open_public_listings(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);
        $platform = Platform::query()
            ->where('slug', 'tokopedia')
            ->firstOrFail();
        $listing = $platform->listings()->firstOrFail();
        $platform->update(['status' => Platform::STATUS_DISABLED]);

        $report = app(MarketplaceSyncService::class)->sync($platform->fresh());

        $this->assertSame(MarketplaceSyncReport::STATUS_FAILED, $report->status);
        $this->assertSame('platform_not_syncable', $report->errorCode);
        $this->get(route('listings.open', $listing))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_sync_command_processes_only_the_requested_platform(): void
    {
        $this->seed(NeomatraCatalogSeeder::class);

        $exitCode = Artisan::call('marketplace:sync', [
            'platform' => 'shopee',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('shopee', Artisan::output());
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
        $run = MarketplaceSyncRun::query()->firstOrFail();
        $this->assertSame(
            'shopee',
            $run->platform()->value('slug'),
        );
        $this->assertSame(MarketplaceSyncRun::STATUS_COMPLETED, $run->status);
        Http::assertNothingSent();
    }

    /** @return array<string, mixed> */
    private function newDemoRecord(
        string $productReference,
        int $price,
    ): array {
        return [
            'product_reference' => $productReference,
            'external_listing_id' => 'demo-sync-new-001',
            'title' => 'RTX 3060 12GB Bekas - Sinkronisasi Demo',
            'description' => 'Data test terstruktur, bukan penawaran nyata.',
            'price' => $price,
            'currency' => 'IDR',
            'condition' => 'bekas',
            'seller_name' => 'Toko Sinkronisasi Demo',
            'seller_type' => 'toko',
            'location' => 'Surabaya',
            'product_url' => 'https://example.test/neomatra-demo/demo-sync-new-001',
            'stock_status' => 'available',
            'price_type' => 'full',
            'source_published_at' => '2026-08-02T00:00:00+07:00',
        ];
    }
}
