<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\MarketplaceSyncRun;
use App\Models\Platform;
use App\Models\Product;
use App\Models\RankingProfile;
use App\Models\User;
use App\Services\Neomatra\ListingFingerprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-03 12:00:00');
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_catalog_and_evaluation_routes_require_an_admin(): void
    {
        $category = $this->createCategory();
        $product = $this->createProduct($category);
        $platform = $this->createPlatform();
        $listing = $this->createListing($platform, $product);
        $queryLog = ChatbotQueryLog::query()->create([
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'query_text' => 'Query tanpa hasil',
            'is_unanswered' => true,
        ]);

        $getRoutes = [
            route('admin.categories.index'),
            route('admin.categories.create'),
            route('admin.categories.edit', $category),
            route('admin.products.index'),
            route('admin.products.create'),
            route('admin.products.edit', $product),
            route('admin.platforms.index'),
            route('admin.platforms.create'),
            route('admin.platforms.edit', $platform),
            route('admin.listings.index'),
            route('admin.listings.create'),
            route('admin.listings.edit', $listing),
            route('admin.unanswered.index'),
            route('admin.unanswered.show', $queryLog),
            route('admin.ranking.edit'),
        ];
        $mutations = [
            ['post', route('admin.categories.store')],
            ['put', route('admin.categories.update', $category)],
            ['delete', route('admin.categories.destroy', $category)],
            ['post', route('admin.products.store')],
            ['put', route('admin.products.update', $product)],
            ['delete', route('admin.products.destroy', $product)],
            ['post', route('admin.platforms.store')],
            ['put', route('admin.platforms.update', $platform)],
            ['delete', route('admin.platforms.destroy', $platform)],
            ['post', route('admin.listings.store')],
            ['put', route('admin.listings.update', $listing)],
            ['delete', route('admin.listings.destroy', $listing)],
            ['post', route('admin.platforms.sync', $platform)],
            ['patch', route('admin.unanswered.update', $queryLog)],
            ['patch', route('admin.ranking.update')],
        ];

        foreach ($getRoutes as $route) {
            $this->get($route)->assertRedirect(route('admin.login'));
        }

        foreach ($mutations as [$method, $route]) {
            $this->{$method}($route)->assertRedirect(route('admin.login'));
        }

        $user = $this->createUser(isAdmin: false);

        foreach ($getRoutes as $route) {
            $this->actingAs($user)->get($route)->assertForbidden();
        }

        foreach ($mutations as [$method, $route]) {
            $this->actingAs($user)->{$method}($route)->assertForbidden();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('categories', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('platforms', 1);
        $this->assertDatabaseCount('listings', 1);
    }

    public function test_admin_can_manage_category_hierarchy_safely(): void
    {
        $admin = $this->createUser();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'name' => '  PC dan Komponen  ',
                'slug' => '',
                'description' => '  Kategori MVP  ',
                'is_active' => '1',
                'sort_order' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $parent = Category::query()->sole();
        $this->assertSame('PC dan Komponen', $parent->name);
        $this->assertSame('pc-dan-komponen', $parent->slug);

        $child = Category::query()->create([
            'parent_id' => $parent->id,
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $parent), [
                'parent_id' => $child->id,
                'name' => $parent->name,
                'slug' => $parent->slug,
                'description' => 'Tidak boleh membentuk siklus',
                'is_active' => '1',
                'sort_order' => '1',
            ])
            ->assertSessionHasErrors('parent_id');
        $this->assertNull($parent->fresh()?->parent_id);

        $product = $this->createProduct($parent);
        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $parent))
            ->assertSessionHas('warning');
        $this->assertDatabaseHas('categories', ['id' => $parent->id]);

        $product->delete();
        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $parent))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $parent->id]);
        $this->assertNull($child->fresh()?->parent_id);
        Http::assertNothingSent();
    }

    public function test_category_name_change_marks_related_embeddings_stale(): void
    {
        $admin = $this->createUser();
        $category = $this->createCategory();
        $product = $this->createProduct($category);
        $alias = $product->aliases()->create(['alias' => 'VGA 3060']);
        $knowledge = KnowledgeItem::query()->create([
            'category_id' => $category->id,
            'title' => 'Panduan GPU',
            'content' => 'Periksa varian GPU.',
            'is_active' => true,
        ]);
        $model = 'catalog-model';
        $dimensions = 2;
        $product->load('category')->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => $product->expectedEmbeddingHash(
                $model,
                $dimensions,
            ),
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
        ])->save();
        $alias->load('product.category')->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => $alias->expectedEmbeddingHash(
                $model,
                $dimensions,
            ),
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
        ])->save();
        $knowledge->load('category')->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => $knowledge->expectedEmbeddingHash(
                $model,
                $dimensions,
            ),
            'embedding_model' => $model,
            'embedding_dimensions' => $dimensions,
            'embedded_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $category), [
                'name' => 'Komponen Grafis',
                'slug' => $category->slug,
                'description' => '',
                'is_active' => '1',
                'sort_order' => '1',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('warning');

        $this->assertNull($product->fresh()?->embedding_hash);
        $this->assertNull($alias->fresh()?->embedding_hash);
        $this->assertNull($knowledge->fresh()?->embedding_hash);
        $this->assertSame([1, 0], $product->fresh()?->embedding);
        Http::assertNothingSent();
    }

    public function test_admin_can_manage_products_aliases_and_safe_output(): void
    {
        $admin = $this->createUser();
        $category = $this->createCategory();
        $unsafeName = '<script>alert("product")</script> RTX 3060';

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'category_id' => $category->id,
                'name' => $unsafeName,
                'brand' => 'NVIDIA',
                'model' => 'RTX 3060',
                'variant' => '12 GB Desktop',
                'sku_or_part_number' => 'GA106',
                'attributes' => '{"capacity_gb":12,"form_factor":"desktop"}',
                'aliases' => "3060 12GB\nVGA 3060, 3060 12gb",
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $product = Product::query()->sole();
        $this->assertSame(2, $product->aliases()->count());
        $this->assertSame(12, $product->attributes['capacity_gb']);
        $this->assertNotSame('', $product->normalized_key);

        $this->actingAs($admin)
            ->get(route('admin.products.index'))
            ->assertOk()
            ->assertSee(e($unsafeName), false)
            ->assertDontSee($unsafeName, false);

        $product->forceFill([
            'embedding' => [1, 0],
            'embedding_hash' => str_repeat('a', 64),
            'embedding_model' => 'old-model',
            'embedding_dimensions' => 2,
            'embedded_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->put(route('admin.products.update', $product), [
                'category_id' => $category->id,
                'name' => 'NVIDIA GeForce RTX 3060',
                'brand' => 'NVIDIA',
                'model' => 'RTX 3060',
                'variant' => '12 GB Desktop',
                'sku_or_part_number' => 'GA106',
                'attributes' => '{"capacity_gb":12,"generation":"Ampere"}',
                'aliases' => "RTX tiga kosong enam kosong\nVGA 3060",
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('warning');

        $product->refresh();
        $this->assertSame([1, 0], $product->embedding);
        $this->assertNull($product->embedding_hash);
        $this->assertSame('old-model', $product->embedding_model);
        $this->assertSame(
            'Embedding baru gagal dibuat.',
            $product->embedding_error,
        );
        $this->assertSame(2, $product->aliases()->count());
        $this->assertDatabaseMissing('product_aliases', [
            'product_id' => $product->id,
            'normalized_alias' => '3060 12gb',
        ]);

        $platform = $this->createPlatform();
        $listing = $this->createListing($platform, $product);
        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertNull($listing->fresh()?->product_id);
        Http::assertSentCount(6);
    }

    public function test_admin_can_manage_platforms_and_listings_with_safe_urls(): void
    {
        $admin = $this->createUser();
        $category = $this->createCategory();
        $product = $this->createProduct($category);

        $this->actingAs($admin)
            ->post(route('admin.platforms.store'), [
                'name' => 'Demo Shop',
                'slug' => '',
                'source_type' => Platform::SOURCE_DEMO,
                'access_type' => Platform::ACCESS_DEMO_DATASET,
                'connector' => 'demo',
                'base_url' => 'https://example.test',
                'status' => Platform::STATUS_READY,
                'is_active' => '1',
                'sync_enabled' => '1',
            ])
            ->assertRedirect(route('admin.platforms.index'))
            ->assertSessionHas('success');

        $platform = Platform::query()->sole();

        $this->actingAs($admin)
            ->post(route('admin.listings.store'), [
                ...$this->listingPayload($platform, $product),
                'product_url' => 'javascript:alert(1)',
                'image_url' => 'javascript:alert(2)',
                'raw_payload' => '{not-json}',
            ])
            ->assertSessionHasErrors([
                'product_url',
                'image_url',
                'raw_payload',
            ]);
        $this->assertDatabaseCount('listings', 0);

        $unsafeTitle = '<img src=x onerror="alert(1)"> RTX 3060';
        $payload = $this->listingPayload($platform, $product, [
            'title' => $unsafeTitle,
            'product_url' => 'https://example.test/items/one?utm_source=test',
        ]);
        $this->actingAs($admin)
            ->post(route('admin.listings.store'), $payload)
            ->assertRedirect(route('admin.listings.index'))
            ->assertSessionHas('success');

        $listing = Listing::query()->sole();
        $expectedFingerprint = app(ListingFingerprintService::class)->make(
            $platform->slug,
            $listing->external_listing_id,
            $listing->product_url,
            $listing->title,
            $listing->price,
            $listing->seller_name,
        );
        $this->assertSame($expectedFingerprint, $listing->source_fingerprint);
        $this->assertSame('IDR', $listing->currency);

        $this->actingAs($admin)
            ->get(route('admin.listings.index'))
            ->assertOk()
            ->assertSee(e($unsafeTitle), false)
            ->assertDontSee($unsafeTitle, false);

        $this->actingAs($admin)
            ->put(route('admin.platforms.update', $platform), [
                'name' => $platform->name,
                'slug' => 'slug-baru',
                'source_type' => $platform->source_type,
                'access_type' => $platform->access_type,
                'connector' => $platform->connector,
                'base_url' => $platform->base_url,
                'status' => $platform->status,
                'is_active' => '1',
                'sync_enabled' => '1',
            ])
            ->assertSessionHasErrors('slug');
        $this->assertSame('demo-shop', $platform->fresh()?->slug);

        $this->actingAs($admin)
            ->put(
                route('admin.listings.update', $listing),
                $this->listingPayload($platform, $product, [
                    'price' => '2595000',
                    'location' => 'Bandung',
                ]),
            )
            ->assertRedirect(route('admin.listings.index'))
            ->assertSessionHas('success');
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'price' => 2595000,
            'location' => 'Bandung',
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.platforms.destroy', $platform))
            ->assertSessionHas('warning');
        $this->actingAs($admin)
            ->delete(route('admin.listings.destroy', $listing))
            ->assertSessionHas('success');
        $this->actingAs($admin)
            ->delete(route('admin.platforms.destroy', $platform))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('listings', 0);
        $this->assertDatabaseCount('platforms', 0);
        Http::assertNothingSent();
    }

    public function test_demo_marketplace_sync_is_admin_only_and_idempotent(): void
    {
        $admin = $this->createUser();
        $category = $this->createCategory();
        $this->createProduct($category);
        $platform = $this->createPlatform([
            'name' => 'Tokopedia Demo',
            'slug' => 'tokopedia',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.platforms.sync', $platform), [
                'max_pages' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseHas('marketplace_sync_runs', [
            'platform_id' => $platform->id,
            'initiated_by' => $admin->id,
            'status' => MarketplaceSyncRun::STATUS_COMPLETED,
            'records_created' => 1,
            'records_failed' => 0,
        ]);
        $this->assertNotNull($platform->fresh()?->last_synced_at);

        $this->actingAs($admin)
            ->post(route('admin.platforms.sync', $platform), [
                'max_pages' => 2,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseCount('marketplace_sync_runs', 2);
        $this->assertSame(
            1,
            MarketplaceSyncRun::query()->latest('id')->value(
                'records_skipped',
            ),
        );
        Http::assertNothingSent();
    }

    public function test_admin_can_review_unanswered_queries_without_xss(): void
    {
        $admin = $this->createUser();
        $unsafeQuery = '<script>alert("query")</script>';
        $queryLog = ChatbotQueryLog::query()->create([
            'status' => ChatbotQueryLog::STATUS_NO_RESULTS,
            'query_text' => $unsafeQuery,
            'normalized_query' => 'query tanpa hasil',
            'intent' => ['product' => 'RTX 3060'],
            'filters' => ['location' => 'Surabaya'],
            'result_count' => 0,
            'is_unanswered' => true,
            'unanswered_reason' => 'no_results',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.unanswered.index'))
            ->assertOk()
            ->assertSee(e($unsafeQuery), false)
            ->assertDontSee($unsafeQuery, false);
        $this->actingAs($admin)
            ->get(route('admin.unanswered.show', $queryLog))
            ->assertOk()
            ->assertSee('RTX 3060')
            ->assertSee('Surabaya')
            ->assertDontSee($unsafeQuery, false);

        $this->actingAs($admin)
            ->patch(route('admin.unanswered.update', $queryLog), [
                'reviewed' => '1',
                'review_notes' => 'Tambah alias dan listing demo.',
            ])
            ->assertRedirect(route('admin.unanswered.show', $queryLog))
            ->assertSessionHas('success');

        $queryLog->refresh();
        $this->assertNotNull($queryLog->reviewed_at);
        $this->assertSame($admin->id, $queryLog->reviewed_by);
        $this->assertSame(
            'Tambah alias dan listing demo.',
            $queryLog->review_notes,
        );

        $this->actingAs($admin)
            ->patch(route('admin.unanswered.update', $queryLog), [
                'reviewed' => '0',
                'review_notes' => 'Perlu ditinjau lagi.',
            ])
            ->assertSessionHas('success');
        $this->assertNull($queryLog->fresh()?->reviewed_at);
        Http::assertNothingSent();
    }

    public function test_ranking_profile_requires_weights_to_total_one(): void
    {
        $admin = $this->createUser();

        $this->actingAs($admin)
            ->patch(route('admin.ranking.update'), [
                ...$this->rankingPayload(),
                'freshness_weight' => '0.20',
            ])
            ->assertSessionHasErrors('relevance_weight');
        $this->assertDatabaseCount('ranking_profiles', 0);

        $this->actingAs($admin)
            ->patch(route('admin.ranking.update'), $this->rankingPayload())
            ->assertRedirect(route('admin.ranking.edit'))
            ->assertSessionHas('success');

        $first = RankingProfile::query()->sole();
        $this->assertTrue($first->is_active);
        $this->assertSame($admin->id, $first->updated_by);
        $this->assertEqualsWithDelta(1.0, array_sum($first->weights()), 0.0001);

        $this->actingAs($admin)
            ->patch(route('admin.ranking.update'), [
                ...$this->rankingPayload(),
                'name' => 'Eksperimen Portofolio',
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseCount('ranking_profiles', 2);
        $this->assertSame(1, RankingProfile::query()->active()->count());
        $this->assertFalse($first->fresh()?->is_active);
        $this->actingAs($admin)
            ->get(route('admin.ranking.edit'))
            ->assertOk()
            ->assertSee('Eksperimen Portofolio');
        Http::assertNothingSent();
    }

    private function createUser(bool $isAdmin = true): User
    {
        return User::query()->create([
            'name' => $isAdmin ? 'Admin Catalog' : 'User Catalog',
            'email' => $isAdmin
                ? 'admin-catalog@example.test'
                : 'user-catalog@example.test',
            'password' => 'test-password',
            'is_admin' => $isAdmin,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createCategory(array $overrides = []): Category
    {
        return Category::query()->create(array_merge([
            'name' => 'PC dan Komponen',
            'slug' => 'pc-dan-komponen',
            'is_active' => true,
            'sort_order' => 1,
        ], $overrides));
    }

    private function createProduct(Category $category): Product
    {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => 'NVIDIA GeForce RTX 3060',
            'brand' => 'NVIDIA',
            'model' => 'RTX 3060',
            'variant' => '12 GB',
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function createPlatform(array $overrides = []): Platform
    {
        return Platform::query()->create(array_merge([
            'name' => 'Demo Platform',
            'slug' => 'demo-platform',
            'source_type' => Platform::SOURCE_DEMO,
            'access_type' => Platform::ACCESS_DEMO_DATASET,
            'connector' => 'demo',
            'base_url' => 'https://example.test',
            'is_active' => true,
            'sync_enabled' => true,
            'status' => Platform::STATUS_READY,
        ], $overrides));
    }

    private function createListing(
        Platform $platform,
        ?Product $product = null,
    ): Listing {
        $fingerprint = app(ListingFingerprintService::class)->make(
            $platform->slug,
            'fixture-listing',
            'https://example.test/listings/fixture',
            'Fixture Listing',
            2500000,
            'Fixture Seller',
        );

        return Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product?->id,
            'external_listing_id' => 'fixture-listing',
            'source_fingerprint' => $fingerprint,
            'title' => 'Fixture Listing',
            'price' => 2500000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'seller_name' => 'Fixture Seller',
            'seller_type' => Listing::SELLER_STORE,
            'product_url' => 'https://example.test/listings/fixture',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function listingPayload(
        Platform $platform,
        Product $product,
        array $overrides = [],
    ): array {
        return array_merge([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => 'manual-listing-1',
            'title' => 'RTX 3060 12 GB Bekas',
            'description' => 'Berdasarkan keterangan seller.',
            'price' => '2500000',
            'currency' => 'idr',
            'condition' => Listing::CONDITION_USED,
            'seller_name' => 'Toko Demo',
            'seller_type' => Listing::SELLER_STORE,
            'location' => 'Surabaya',
            'product_url' => 'https://example.test/items/one',
            'image_url' => 'https://example.test/images/one.jpg',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'shipping_cost' => '25000',
            'service_fee' => '20000',
            'inspection_cost' => '0',
            'repair_estimate' => '50000',
            'warranty' => 'Garansi toko 30 hari',
            'raw_payload' => '{"source":"manual-test"}',
            'source_published_at' => '2026-08-01 10:00:00',
            'last_synced_at' => '2026-08-03 10:00:00',
            'is_active' => '1',
        ], $overrides);
    }

    /** @return array<string, string> */
    private function rankingPayload(): array
    {
        return [
            'name' => 'Default Neomatra',
            'relevance_weight' => '0.40',
            'price_weight' => '0.30',
            'condition_weight' => '0.15',
            'seller_weight' => '0.10',
            'freshness_weight' => '0.05',
        ];
    }
}
