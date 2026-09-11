<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class AdminPlatformSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_platform_page_is_a_fixed_manual_registry_with_real_listing_metrics(): void
    {
        $platforms = collect(Platform::MVP_SLUGS)
            ->mapWithKeys(fn (string $slug): array => [
                $slug => $this->platform($slug),
            ]);
        $unsupported = Platform::query()->create([
            'name' => 'Lazada Legacy',
            'slug' => 'lazada',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_MANUAL_URL,
            'connector' => 'manual-import',
            'status' => Platform::STATUS_DISABLED,
            'is_active' => false,
            'sync_enabled' => false,
        ]);
        $category = Category::query()->create([
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
        ]);
        $product = Product::query()->create([
            'category_id' => $category->id,
            'name' => 'NVIDIA GeForce RTX 3060',
            'is_active' => true,
        ]);
        $this->listing(
            $platforms['tokopedia'],
            $product,
            'tokopedia-active',
            '2026-09-11 10:20:00',
        );
        $this->listing(
            $platforms['tokopedia'],
            $product,
            'tokopedia-inactive',
            '2026-09-11 11:30:00',
            isActive: false,
        );
        $this->listing(
            $platforms['olx'],
            $product,
            'olx-active',
            '2026-09-10 08:15:00',
        );
        $productCount = Product::query()->count();
        $listingCount = Listing::query()->count();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.platforms.index'))
            ->assertOk()
            ->assertSeeText('Platform Marketplace')
            ->assertSeeText('Pantau 5 marketplace yang menjadi sumber listing Neomatra.')
            ->assertSeeText('Listing Aktif')
            ->assertSeeText('11 Sep 2026 11:30')
            ->assertSeeText('—')
            ->assertSeeText('Lazada Legacy')
            ->assertDontSeeText('Tambah Platform')
            ->assertDontSeeText('Hapus')
            ->assertDontSeeText('Sumber Data')
            ->assertDontSeeText('Connector')
            ->assertDontSeeText('Public Web')
            ->assertDontSeeText('CSV / Manual')
            ->assertDontSeeText('Tes Koneksi')
            ->assertDontSeeText('Sinkronkan Sekarang');

        $shown = $response->viewData('platforms');
        $this->assertEqualsCanonicalizing(
            Platform::MVP_SLUGS,
            $shown->getCollection()->pluck('slug')->all(),
        );
        $this->assertSame(
            1,
            $shown->getCollection()->firstWhere('slug', 'tokopedia')
                ?->active_listings_count,
        );
        $this->assertSame(
            1,
            $shown->getCollection()->firstWhere('slug', 'olx')
                ?->active_listings_count,
        );
        $this->assertSame(
            0,
            $shown->getCollection()->firstWhere('slug', 'carousell')
                ?->active_listings_count,
        );
        $this->assertSame(
            '2026-09-11 11:30:00',
            Carbon::parse(
                $shown->getCollection()->firstWhere('slug', 'tokopedia')
                    ?->latest_listing_activity_at,
            )->format('Y-m-d H:i:s'),
        );
        $this->assertNull(
            $shown->getCollection()->firstWhere('slug', 'carousell')
                ?->latest_listing_activity_at,
        );
        $this->assertTrue($response->viewData('unsupportedPlatforms')->contains(
            $unsupported->name,
        ));
        $this->assertSame($productCount, Product::query()->count());
        $this->assertSame($listingCount, Listing::query()->count());
        $this->assertSame(
            $listingCount,
            Listing::query()->whereNotNull('platform_id')->count(),
        );

        foreach (['create', 'store', 'destroy', 'probe', 'sync'] as $action) {
            $this->assertFalse(Route::has('admin.platforms.'.$action));
        }

        Http::assertNothingSent();
    }

    public function test_platform_edit_is_limited_and_cannot_reenable_sync_or_change_identity(): void
    {
        $platform = $this->platform('olx');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.platforms.update', $platform), [
                'name' => 'Amazon',
                'slug' => 'amazon',
                'source_type' => Platform::SOURCE_SCRAPE,
                'access_type' => Platform::ACCESS_PUBLIC_HTML,
                'connector' => 'olx-public-web',
                'base_url' => 'https://www.olx.co.id/items',
                'status' => Platform::STATUS_ERROR,
                'is_active' => '0',
                'sync_enabled' => '1',
            ])
            ->assertRedirect(route('admin.platforms.index'))
            ->assertSessionHas('success');

        $platform->refresh();
        $this->assertSame('OLX', $platform->name);
        $this->assertSame('olx', $platform->slug);
        $this->assertSame(Platform::SOURCE_MANUAL, $platform->source_type);
        $this->assertSame(Platform::ACCESS_CSV_IMPORT, $platform->access_type);
        $this->assertSame('manual-import', $platform->connector);
        $this->assertSame('https://www.olx.co.id/items', $platform->base_url);
        $this->assertFalse($platform->is_active);
        $this->assertFalse($platform->sync_enabled);
        $this->assertSame(Platform::STATUS_DISABLED, $platform->status);

        $this->actingAs($admin)
            ->put(route('admin.platforms.update', $platform), [
                'base_url' => $platform->base_url,
                'is_active' => '1',
            ])
            ->assertSessionHas('success');

        $this->assertTrue($platform->fresh()?->is_active);
        $this->assertFalse($platform->fresh()?->sync_enabled);
        $this->assertSame(Platform::STATUS_READY, $platform->fresh()?->status);
        Http::assertNothingSent();
    }

    private function platform(string $slug): Platform
    {
        $names = [
            'tokopedia' => 'Tokopedia',
            'shopee' => 'Shopee',
            'olx' => 'OLX',
            'facebook' => 'Facebook Marketplace',
            'carousell' => 'Carousell',
        ];

        return Platform::query()->create([
            'name' => $names[$slug],
            'slug' => $slug,
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'base_url' => 'https://example.test/'.$slug,
            'status' => Platform::STATUS_READY,
            'is_active' => true,
            'sync_enabled' => false,
        ]);
    }

    private function listing(
        Platform $platform,
        Product $product,
        string $reference,
        string $updatedAt,
        bool $isActive = true,
    ): Listing {
        $listing = Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => $reference,
            'source_fingerprint' => hash('sha256', $reference),
            'title' => 'RTX 3060 '.$reference,
            'price' => 2_500_000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'product_url' => 'https://example.test/'.$reference,
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'last_seen_at' => $updatedAt,
            'is_active' => $isActive,
        ]);
        DB::table('listings')->where('id', $listing->id)->update([
            'updated_at' => $updatedAt,
        ]);

        return $listing->refresh();
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Platform Admin',
            'email' => uniqid('platform-', true).'@example.test',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }
}
