<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CategoryAlias;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class UnmatchedListingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'ai.embedding.dimensions' => 2,
            'ai.http.retries' => 0,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'embedding' => ['values' => [1.0, 0.0]],
            ]),
        ]);
    }

    public function test_unmatched_listing_can_be_reviewed_and_approved_as_a_new_product(): void
    {
        [$admin, $gpu, $listing] = $this->scenario();

        $this->actingAs($admin)
            ->get(route('admin.listings.unmatched'))
            ->assertOk()
            ->assertSeeText($listing->title)
            ->assertSee(e(route('admin.listings.candidate.review', $listing)), false);

        $this->actingAs($admin)
            ->get(route('admin.listings.candidate.review', $listing))
            ->assertOk()
            ->assertSeeText('Tinjau Kandidat')
            ->assertSee('value="'.$gpu->id.'"', false)
            ->assertSee('RTX 5090');

        $this->actingAs($admin)
            ->post(route('admin.listings.candidate.approve', $listing), [
                'category_id' => $gpu->id,
                'name' => 'NVIDIA GeForce RTX 5090',
                'brand' => 'NVIDIA',
                'model' => 'RTX 5090',
                'variant' => '32 GB',
                'sku_or_part_number' => 'GB202',
                'aliases' => "RTX 5090\nGeForce RTX5090",
            ])
            ->assertRedirect(route('admin.listings.unmatched'))
            ->assertSessionHas('success');

        $product = Product::query()->where('model', 'RTX 5090')->firstOrFail();
        $this->assertSame(2, $product->aliases()->count());
        $this->assertSame($product->id, $listing->fresh()?->product_id);
        $this->assertNotNull($product->embedding);
        $this->assertNotNull($product->aliases()->firstOrFail()->embedding);
        Http::assertSentCount(3);
    }

    public function test_duplicate_candidate_is_not_created_and_existing_product_can_be_linked(): void
    {
        [$admin, $gpu, $listing] = $this->scenario();
        $existing = Product::query()->create([
            'category_id' => $gpu->id,
            'name' => 'NVIDIA GeForce RTX 5090',
            'brand' => 'NVIDIA',
            'model' => 'RTX 5090',
            'variant' => '32 GB',
            'sku_or_part_number' => 'GB202',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from(route('admin.listings.candidate.review', $listing))
            ->post(route('admin.listings.candidate.approve', $listing), [
                'category_id' => $gpu->id,
                'name' => 'GeForce RTX 5090',
                'brand' => 'NVIDIA',
                'model' => 'RTX 5090',
                'variant' => '32 GB',
                'sku_or_part_number' => 'GB202',
                'aliases' => 'RTX5090',
            ])
            ->assertRedirect(route('admin.listings.candidate.review', $listing))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('products', 1);
        $this->assertNull($listing->fresh()?->product_id);

        $this->actingAs($admin)
            ->post(route('admin.listings.link-product', $listing), [
                'product_id' => $existing->id,
                'aliases' => 'GeForce RTX5090',
            ])
            ->assertRedirect(route('admin.listings.unmatched'))
            ->assertSessionHas('success');

        $this->assertSame($existing->id, $listing->fresh()?->product_id);
        $this->assertDatabaseHas('product_aliases', [
            'product_id' => $existing->id,
            'normalized_alias' => 'geforce rtx5090',
        ]);
        $this->assertNotNull($existing->aliases()->firstOrFail()->embedding);
        Http::assertSentCount(1);
    }

    public function test_candidate_guardrails_reject_unsupported_categories_noisy_aliases_and_non_admins(): void
    {
        [$admin, $gpu, $listing] = $this->scenario();
        $unsupported = Category::query()->create([
            'name' => 'Vehicles',
            'slug' => 'vehicles',
            'is_active' => true,
        ]);
        $payload = [
            'category_id' => $unsupported->id,
            'name' => 'Honda Civic',
            'brand' => 'Honda',
            'model' => 'Civic',
            'aliases' => 'Civic',
        ];

        $this->actingAs($admin)
            ->post(route('admin.listings.candidate.approve', $listing), $payload)
            ->assertSessionHasErrors('category_id');

        $this->actingAs($admin)
            ->post(route('admin.listings.candidate.approve', $listing), [
                ...$payload,
                'category_id' => $gpu->id,
                'name' => 'NVIDIA GeForce RTX 5090',
                'aliases' => 'RTX 5090 MURAH READY SURABAYA',
            ])
            ->assertSessionHasErrors('aliases');

        $user = User::query()->create([
            'name' => 'Regular User',
            'email' => 'user@example.test',
            'password' => 'password',
            'is_admin' => false,
        ]);
        $this->actingAs($user)
            ->get(route('admin.listings.candidate.review', $listing))
            ->assertForbidden();
        $this->actingAs($user)
            ->post(route('admin.listings.candidate.approve', $listing), [
                ...$payload,
                'category_id' => $gpu->id,
            ])
            ->assertForbidden();

        auth()->logout();
        $this->get(route('admin.listings.candidate.review', $listing))
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseCount('products', 0);
        $this->assertNull($listing->fresh()?->product_id);
        Http::assertNothingSent();
    }

    public function test_regular_product_creation_uses_the_same_scope_and_alias_guardrails(): void
    {
        [$admin, $gpu] = $this->scenario();
        $unsupported = Category::query()->create([
            'name' => 'Vehicles',
            'slug' => 'vehicles',
            'is_active' => true,
        ]);
        $payload = [
            'category_id' => $unsupported->id,
            'name' => 'Honda Civic',
            'brand' => 'Honda',
            'model' => 'Civic',
            'aliases' => 'Civic',
            'attributes' => '',
            'is_active' => '1',
        ];

        $this->actingAs($admin)
            ->post(route('admin.products.store'), $payload)
            ->assertSessionHasErrors('category_id');

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                ...$payload,
                'category_id' => $gpu->id,
                'name' => 'NVIDIA GeForce RTX 5090',
                'aliases' => 'RTX 5090 TERMURAH READY',
            ])
            ->assertSessionHasErrors('aliases');

        $this->assertDatabaseCount('products', 0);
        Http::assertNothingSent();
    }

    /** @return array{User, Category, Listing} */
    private function scenario(): array
    {
        $admin = User::query()->create([
            'name' => 'Admin Reviewer',
            'email' => 'admin@example.test',
            'password' => 'password',
            'is_admin' => true,
        ]);
        $root = Category::query()->create([
            'name' => 'PC Components',
            'slug' => 'pc-dan-komponen',
            'is_active' => true,
        ]);
        $gpu = Category::query()->create([
            'parent_id' => $root->id,
            'name' => 'GPU',
            'slug' => 'gpu',
            'is_active' => true,
        ]);
        CategoryAlias::query()->create([
            'category_id' => $gpu->id,
            'alias' => 'VGA',
            'language' => 'id',
            'source' => 'test',
            'is_active' => true,
        ]);
        $platform = Platform::query()->create([
            'name' => 'OLX',
            'slug' => 'olx',
            'source_type' => Platform::SOURCE_SCRAPE,
            'access_type' => Platform::ACCESS_PUBLIC_HTML,
            'connector' => 'olx-public-web',
            'base_url' => 'https://www.olx.co.id',
            'is_active' => true,
            'sync_enabled' => true,
            'status' => Platform::STATUS_READY,
        ]);
        $listing = Listing::query()->create([
            'platform_id' => $platform->id,
            'source_fingerprint' => str_repeat('a', 64),
            'external_listing_id' => 'olx-rtx-5090',
            'title' => 'VGA RTX 5090 32GB Bekas',
            'price' => 32000000,
            'currency' => 'IDR',
            'condition' => Listing::CONDITION_USED,
            'condition_source' => Listing::CONDITION_SOURCE_SELLER,
            'seller_name' => 'Seller Nyata',
            'location' => 'Jakarta',
            'product_url' => 'https://www.olx.co.id/item/rtx-5090-iid-1',
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'raw_payload' => [
                'connector' => 'olx-public-web',
                'demo' => false,
                'product_brand' => 'NVIDIA',
                'product_model' => 'RTX 5090',
                'product_variant' => '32 GB',
                'product_sku' => 'GB202',
            ],
            'first_seen_at' => now()->subDay(),
            'last_seen_at' => now(),
            'last_synced_at' => now(),
            'is_active' => true,
        ]);

        return [$admin, $gpu, $listing];
    }
}
