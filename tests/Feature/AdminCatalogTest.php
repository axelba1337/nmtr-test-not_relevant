<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ChatbotQueryLog;
use App\Models\KnowledgeItem;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
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
            route('admin.platforms.edit', $platform),
            route('admin.listings.index'),
            route('admin.listings.create'),
            route('admin.listings.edit', $listing),
            route('admin.unanswered.index'),
            route('admin.unanswered.show', $queryLog),
        ];
        $mutations = [
            ['post', route('admin.categories.store')],
            ['put', route('admin.categories.update', $category)],
            ['delete', route('admin.categories.destroy', $category)],
            ['post', route('admin.products.store')],
            ['put', route('admin.products.update', $product)],
            ['delete', route('admin.products.destroy', $product)],
            ['put', route('admin.platforms.update', $platform)],
            ['post', route('admin.listings.store')],
            ['put', route('admin.listings.update', $listing)],
            ['delete', route('admin.listings.destroy', $listing)],
            ['patch', route('admin.unanswered.update', $queryLog)],
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
        $parent = $this->createCategory();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'parent_id' => $parent->id,
                'name' => '  GPU  ',
                'slug' => '',
                'description' => '  Kategori MVP  ',
                'is_active' => '1',
                'sort_order' => '2',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $child = Category::query()->where('slug', 'gpu')->sole();
        $this->assertSame('GPU', $child->name);
        $this->assertSame($parent->id, $child->parent_id);

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

    public function test_category_name_cannot_leave_canonical_taxonomy(): void
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
            ->assertSessionHasErrors('name');

        $this->assertNotNull($product->fresh()?->embedding_hash);
        $this->assertNotNull($alias->fresh()?->embedding_hash);
        $this->assertNotNull($knowledge->fresh()?->embedding_hash);
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
                'spec_source_name' => 'NVIDIA',
                'spec_source_url' => 'https://www.nvidia.com/example-product',
                'spec_source_checked_at' => '2026-08-14 10:00:00',
                'aliases' => "3060 12GB\nVGA 3060, 3060 12gb",
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.products.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('warning');

        $product = Product::query()->sole();
        $this->assertSame(2, $product->aliases()->count());
        $this->assertSame(12, $product->attributes['capacity_gb']);
        $this->assertSame('NVIDIA', $product->spec_source_name);
        $this->assertSame(
            'https://www.nvidia.com/example-product',
            $product->spec_source_url,
        );
        $this->assertNotNull($product->spec_source_checked_at);
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
                'spec_source_name' => 'NVIDIA Official Specifications',
                'spec_source_url' => 'https://www.nvidia.com/example-product-v2',
                'spec_source_checked_at' => '2026-08-14 11:00:00',
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
            'Layanan embedding mengembalikan vektor yang tidak valid.',
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

    public function test_admin_can_safely_edit_a_fixed_platform_and_manage_listings(): void
    {
        $admin = $this->createUser();
        $category = $this->createCategory();
        $product = $this->createProduct($category);
        $platform = $this->createPlatform([
            'name' => 'Tokopedia',
            'slug' => 'tokopedia',
            'source_type' => Platform::SOURCE_MANUAL,
            'access_type' => Platform::ACCESS_CSV_IMPORT,
            'connector' => 'manual-import',
            'base_url' => 'https://www.tokopedia.com',
            'sync_enabled' => false,
        ]);

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
                'name' => 'OLX',
                'slug' => 'olx',
                'source_type' => Platform::SOURCE_SCRAPE,
                'access_type' => Platform::ACCESS_PUBLIC_HTML,
                'connector' => 'olx-public-web',
                'base_url' => 'https://www.tokopedia.com/catalog',
                'status' => $platform->status,
                'is_active' => '1',
                'sync_enabled' => '1',
            ])
            ->assertRedirect(route('admin.platforms.index'))
            ->assertSessionHas('success');
        $platform->refresh();
        $this->assertSame('Tokopedia', $platform->name);
        $this->assertSame('tokopedia', $platform->slug);
        $this->assertSame(Platform::SOURCE_MANUAL, $platform->source_type);
        $this->assertSame('manual-import', $platform->connector);
        $this->assertSame('https://www.tokopedia.com/catalog', $platform->base_url);
        $this->assertFalse($platform->sync_enabled);

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
            ->delete(route('admin.listings.destroy', $listing))
            ->assertSessionHas('success');

        $this->assertDatabaseCount('listings', 0);
        $this->assertDatabaseCount('platforms', 1);
        $this->assertDatabaseHas('products', ['id' => $product->id]);
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
            'name' => 'PC Components',
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
            'location' => 'Surabaya',
            'product_url' => 'https://example.test/items/one',
            'image_url' => 'https://example.test/images/one.jpg',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'warranty' => 'Garansi toko 30 hari',
            'raw_payload' => '{"source":"manual-test"}',
            'source_published_at' => '2026-08-01 10:00:00',
            'last_synced_at' => '2026-08-03 10:00:00',
            'is_active' => '1',
        ], $overrides);
    }
}
