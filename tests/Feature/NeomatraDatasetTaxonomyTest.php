<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\NeomatraDatasetV3Seeder;
use Database\Seeders\Support\SqlDatasetParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NeomatraDatasetTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
    }

    public function test_platform_registry_matches_current_connector_capabilities(): void
    {
        $this->seed(DatabaseSeeder::class);
        $expected = [
            'tokopedia',
            'shopee',
            'olx',
            'facebook',
            'carousell',
            'blibli',
            'lazada',
            'tiktok-shop',
            'jagofon',
            'laku6',
            'bhinneka',
            'jakmall',
            'enterkomputer',
            'pemmz',
            'erafone',
            'eraspace',
            'digimap',
            'asus-store-indonesia',
            'acer-estore-indonesia',
            'samsung-indonesia',
            'hp-indonesia-store',
        ];

        $this->assertEqualsCanonicalizing(
            $expected,
            Platform::query()->pluck('slug')->all(),
        );

        foreach (['tokopedia', 'shopee'] as $slug) {
            $this->assertDatabaseHas('platforms', [
                'slug' => $slug,
                'source_type' => Platform::SOURCE_DEMO,
                'access_type' => Platform::ACCESS_DEMO_DATASET,
                'connector' => 'demo-dataset',
                'sync_enabled' => false,
            ]);
        }

        $this->assertDatabaseHas('platforms', [
            'slug' => 'olx',
            'source_type' => Platform::SOURCE_SCRAPE,
            'access_type' => Platform::ACCESS_PUBLIC_HTML,
            'connector' => 'olx-public-web',
            'sync_enabled' => false,
        ]);

        foreach (['facebook', 'carousell'] as $slug) {
            $this->assertDatabaseHas('platforms', [
                'slug' => $slug,
                'source_type' => Platform::SOURCE_MANUAL,
                'access_type' => Platform::ACCESS_CSV_IMPORT,
                'connector' => 'manual-import',
                'sync_enabled' => false,
                'status' => Platform::STATUS_READY,
            ]);
        }

        foreach (array_diff($expected, [
            'tokopedia',
            'shopee',
            'olx',
            'facebook',
            'carousell',
        ]) as $slug) {
            $this->assertDatabaseHas('platforms', [
                'slug' => $slug,
                'source_type' => Platform::SOURCE_MANUAL,
                'access_type' => Platform::ACCESS_MANUAL_URL,
                'connector' => null,
                'sync_enabled' => false,
            ]);
        }

        Http::assertNothingSent();
    }

    public function test_dataset_v3_restores_facebook_and_upgrades_carousell_registration(): void
    {
        $this->seed(DatabaseSeeder::class);
        Platform::query()->where('slug', 'facebook')->delete();
        Platform::query()->where('slug', 'carousell')->update([
            'access_type' => Platform::ACCESS_MANUAL_URL,
            'connector' => null,
        ]);

        $this->seed(NeomatraDatasetV3Seeder::class);

        foreach (['facebook', 'carousell'] as $slug) {
            $this->assertDatabaseHas('platforms', [
                'slug' => $slug,
                'source_type' => Platform::SOURCE_MANUAL,
                'access_type' => Platform::ACCESS_CSV_IMPORT,
                'connector' => 'manual-import',
                'sync_enabled' => false,
                'status' => Platform::STATUS_READY,
            ]);
        }
    }

    public function test_category_hierarchy_uses_unique_stable_slugs(): void
    {
        $this->seed(DatabaseSeeder::class);
        $roots = [
            'pc-dan-komponen',
            'handphone',
            'laptop',
            'pc-accessories',
            'networking',
        ];

        foreach ($roots as $slug) {
            $this->assertDatabaseHas('categories', [
                'slug' => $slug,
                'parent_id' => null,
                'is_active' => true,
            ]);
        }

        foreach ([
            'gpu' => 'pc-dan-komponen',
            'smartphone' => 'handphone',
            'gaming-laptop' => 'laptop',
            'monitor' => 'pc-accessories',
            'router' => 'networking',
        ] as $childSlug => $parentSlug) {
            $child = Category::query()->where('slug', $childSlug)->firstOrFail();
            $this->assertSame(
                $parentSlug,
                $child->parent()->value('slug'),
            );
        }

        $duplicates = Category::query()
            ->select('slug')
            ->groupBy('slug')
            ->havingRaw('COUNT(*) > 1')
            ->count();
        $this->assertSame(0, $duplicates);
        $this->assertDatabaseHas('categories', ['slug' => 'panduan-neomatra']);
        Http::assertNothingSent();
    }

    public function test_products_cover_all_domains_with_provenance_and_aliases(): void
    {
        $this->seed(DatabaseSeeder::class);
        $products = Product::query()
            ->with(['category.parent', 'aliases'])
            ->get();
        $sourceNames = collect((new SqlDatasetParser)->rows(
            database_path('data/v3/products_v3_expanded.sql'),
            'INSERT INTO `products`',
            'ON DUPLICATE KEY UPDATE',
        ))->pluck(1);
        $unexpected = $products->whereNotIn('name', $sourceNames)->pluck('name');
        $duplicates = $products->groupBy('name')
            ->filter(fn ($group): bool => $group->count() > 1)
            ->keys();

        $this->assertCount(
            170,
            $products,
            'unexpected='.$unexpected->toJson()
                .' duplicates='.$duplicates->toJson(),
        );
        $coverage = $products->groupBy(
            fn (Product $product): string => (string) (
                $product->category?->parent?->slug
                ?? $product->category?->slug
            ),
        )->map->count();
        $this->assertGreaterThanOrEqual(10, $coverage['pc-dan-komponen'] ?? 0);
        $this->assertGreaterThanOrEqual(4, $coverage['handphone'] ?? 0);
        $this->assertGreaterThanOrEqual(4, $coverage['laptop'] ?? 0);
        $this->assertGreaterThanOrEqual(3, $coverage['pc-accessories'] ?? 0);
        $this->assertGreaterThanOrEqual(4, $coverage['networking'] ?? 0);

        foreach ($products as $product) {
            $this->assertNotEmpty($product->spec_source_name);
            $this->assertNotEmpty($product->spec_source_url);
            $this->assertNotNull($product->spec_source_checked_at);
            $this->assertGreaterThanOrEqual(3, $product->aliases->count());
            $this->assertSame(
                $product->aliases->count(),
                $product->aliases->pluck('normalized_alias')->unique()->count(),
            );
        }

        $sample = $products->firstOrFail();
        $this->assertStringContainsString($sample->name, $sample->embeddingText());
        $this->assertStringNotContainsString(
            (string) $sample->spec_source_url,
            $sample->embeddingText(),
        );
        Http::assertNothingSent();
    }

    public function test_demo_listings_are_retained_for_tests_but_inactive_in_v3(): void
    {
        $this->seed(DatabaseSeeder::class);
        $listings = Listing::query()->get();

        $this->assertGreaterThanOrEqual(100, $listings->count());

        $productsWithDemoListings = Product::query()
            ->whereHas('listings')
            ->get();
        $this->assertCount(32, $productsWithDemoListings);

        foreach ($productsWithDemoListings as $product) {
            $this->assertGreaterThanOrEqual(
                2,
                $product->listings()->where('condition', Listing::CONDITION_NEW)->count(),
            );
            $this->assertGreaterThanOrEqual(
                2,
                $product->listings()->where('condition', Listing::CONDITION_USED)->count(),
            );
        }

        foreach ($listings as $listing) {
            $this->assertFalse($listing->is_active);
            $this->assertContains($listing->condition, Listing::conditions());
            $this->assertContains($listing->seller_type, Listing::sellerTypes());
            $this->assertContains($listing->stock_status, Listing::stockStatuses());
            $this->assertContains($listing->price_type, Listing::priceTypes());
            $this->assertTrue((bool) ($listing->raw_payload['demo'] ?? false));
            $this->assertSame(
                'seeded-demo',
                $listing->raw_payload['connector'] ?? null,
            );
            $this->assertNotNull(
                $listing->first_seen_at,
                (string) $listing->external_listing_id,
            );
            $this->assertNotNull($listing->last_seen_at);
            $this->assertNotNull($listing->last_synced_at);
            $this->assertSame('example.test', parse_url($listing->product_url, PHP_URL_HOST));
            $this->assertStringContainsString('Data Demo', $listing->title);
            $this->assertStringContainsString('demonstrasi', (string) $listing->description);
            $this->assertStringContainsString('bukan penawaran', (string) $listing->description);
        }

        $this->assertEqualsCanonicalizing(
            Listing::conditions(),
            Listing::query()->distinct()->pluck('condition')->all(),
        );
        $this->assertEqualsCanonicalizing(
            Listing::sellerTypes(),
            Listing::query()->distinct()->pluck('seller_type')->all(),
        );
        Http::assertNothingSent();
    }

    public function test_v3_knowledge_is_bilingual_and_obsolete_items_are_inactive(): void
    {
        foreach ([
            'Tentang Semantic Chatbot Lite',
            'Cara chatbot menggunakan knowledge',
            'Ketika informasi tidak tersedia',
        ] as $index => $title) {
            KnowledgeItem::query()->create([
                'title' => $title,
                'content' => 'Legacy chatbot content.',
                'is_active' => true,
                'sort_order' => $index,
            ]);
        }

        $this->seed(DatabaseSeeder::class);
        $items = KnowledgeItem::query()
            ->where('source_name', 'Neomatra Dataset & Taxonomy V3')
            ->with('category')
            ->get();

        $this->assertCount(47, $items);

        $legacyMvpTitles = [
            'Seller type Neomatra / Neomatra seller types',
            'Arti official seller / Meaning of official seller',
            'Harga pasar / Market price',
            'Comparable listings',
            'Persentase undervalued / Undervalued percentage',
            'Total cost',
            'Estimasi margin reseller / Reseller margin estimate',
            'Risk signal',
            'Perbandingan dua produk / Comparing two products',
            'Kualitas sample market price / Market sample quality',
        ];

        foreach ($items as $item) {
            $this->assertSame(
                ! in_array($item->title, $legacyMvpTitles, true),
                $item->is_active,
            );
            $this->assertStringContainsString('ID:', $item->content);
            $this->assertStringContainsString('EN:', $item->content);
            $this->assertSame('panduan-neomatra', $item->category?->slug);
            $this->assertIsArray($item->keywords);
            $this->assertNotEmpty($item->keywords);
        }

        $this->assertSame(37, $items->where('is_active', true)->count());

        $this->assertSame(
            0,
            KnowledgeItem::query()
                ->whereIn('title', [
                    'Tentang Semantic Chatbot Lite',
                    'Cara chatbot menggunakan knowledge',
                    'Ketika informasi tidak tersedia',
                ])
                ->where('is_active', true)
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_database_seeder_is_idempotent_for_dataset_entities(): void
    {
        $this->seed(DatabaseSeeder::class);
        $tables = [
            'platforms',
            'categories',
            'products',
            'product_aliases',
            'listings',
            'ranking_profiles',
            'knowledge_items',
        ];
        $before = collect($tables)->mapWithKeys(
            fn (string $table): array => [$table => DB::table($table)->count()],
        );

        $this->seed(DatabaseSeeder::class);

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        Http::assertNothingSent();
    }
}
