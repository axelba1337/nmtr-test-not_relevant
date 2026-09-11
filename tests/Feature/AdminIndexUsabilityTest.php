<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminIndexUsabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_sort_order_is_optional_and_blank_defaults_to_zero(): void
    {
        foreach ([null, ''] as $sortOrder) {
            $root = $this->root();
            $payload = [
                'parent_id' => $root->id,
                'name' => 'GPU',
                'slug' => 'gpu',
                'is_active' => '1',
            ];

            if ($sortOrder !== null) {
                $payload['sort_order'] = $sortOrder;
            }

            $this->actingAs($this->admin())
                ->post(route('admin.categories.store'), $payload)
                ->assertSessionDoesntHaveErrors()
                ->assertRedirect(route('admin.categories.index'));

            $this->assertDatabaseHas('categories', [
                'slug' => 'gpu',
                'sort_order' => 0,
            ]);

            Category::query()->where('slug', 'gpu')->delete();
        }
    }

    public function test_category_sort_order_accepts_non_negative_integer_and_rejects_negative_value(): void
    {
        $root = $this->root();

        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), [
                'parent_id' => $root->id,
                'name' => 'GPU',
                'slug' => 'gpu',
                'is_active' => '1',
                'sort_order' => '23',
            ])
            ->assertSessionDoesntHaveErrors();

        $this->assertDatabaseHas('categories', [
            'slug' => 'gpu',
            'sort_order' => 23,
        ]);

        Category::query()->where('slug', 'gpu')->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), [
                'parent_id' => $root->id,
                'name' => 'GPU',
                'slug' => 'gpu',
                'is_active' => '1',
                'sort_order' => '-1',
            ])
            ->assertSessionHasErrors('sort_order');
    }

    public function test_category_form_marks_required_fields_but_not_optional_sort_order(): void
    {
        $this->root();

        $response = $this->actingAs($this->admin())
            ->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('Opsional. Mengatur posisi kategori pada daftar.')
            ->assertSee('class="required-marker"', false)
            ->assertDontSee('id="category-order" name="sort_order" type="number" min="0" value="" required', false);

        $this->assertSame(2, substr_count(
            $response->getContent(),
            'class="required-marker"',
        ));
    }

    public function test_admin_category_and_product_helpers_use_plain_indonesian_wording(): void
    {
        $this->root();
        $admin = $this->admin();

        $category = $this->actingAs($admin)
            ->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSeeText('Pilih kategori induk yang sesuai dengan jenis produk.')
            ->assertSeeText('Opsional. Kosongkan agar slug dibuat otomatis dari nama.')
            ->assertSeeText('Opsional. Mengatur posisi kategori pada daftar. Angka lebih kecil tampil lebih dulu.')
            ->assertDontSeeText('taxonomy Neomatra');

        $this->assertStringNotContainsString(
            'form-hint',
            $category->getContent(),
        );

        $this->actingAs($admin)
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSeeText('Pilih kategori yang sesuai dengan produk. Neomatra hanya mendukung PC Components, Handphone, Laptop, PC Accessories, dan Networking.')
            ->assertSeeText('Masukkan nama lain yang biasa digunakan untuk produk ini.')
            ->assertDontSeeText('taxonomy');

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeText('Cakupan Kategori Neomatra')
            ->assertSeeText('Neomatra mengelola produk dalam 5 kategori utama: PC Components, Handphone, Laptop, PC Accessories, dan Networking.');

        $this->assertStringNotContainsString(
            'taxonomy',
            strtolower(json_encode([
                __('ui.admin_pages'),
                __('ui.validation.custom'),
            ], JSON_THROW_ON_ERROR)),
        );
    }

    public function test_admin_category_scope_copy_exists_in_english(): void
    {
        $this->root();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSeeText('Select the parent category that matches the product type.')
            ->assertSeeText('Optional. Leave blank to generate the slug automatically from the name.');

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.products.create'))
            ->assertOk()
            ->assertSeeText('Neomatra supports PC Components, Mobile Phones, Laptops, PC Accessories, and Networking.');

        $this->actingAs($admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeText('Neomatra Category Scope')
            ->assertSeeText('Neomatra manages products within 5 main categories: PC Components, Mobile Phones, Laptops, PC Accessories, and Networking.');
    }

    public function test_category_index_supports_all_sort_modes_and_invalid_sort_falls_back_to_newest(): void
    {
        $older = $this->category('Zulu', false, '2026-01-01 00:00:00');
        $newer = $this->category('Alpha', true, '2026-02-01 00:00:00');
        $admin = $this->admin();

        $this->assertCategoryOrder($admin, 'newest', [$newer->id, $older->id]);
        $this->assertCategoryOrder($admin, 'oldest', [$older->id, $newer->id]);
        $this->assertCategoryOrder($admin, 'alphabetical', [$newer->id, $older->id]);
        $this->assertCategoryOrder($admin, 'status', [$newer->id, $older->id]);
        $this->assertCategoryOrder($admin, 'not-valid', [$newer->id, $older->id]);

        $arrayResponse = $this->actingAs($admin)
            ->get(route('admin.categories.index').'?sort[]=newest')
            ->assertOk();
        $this->assertSame(
            [$newer->id, $older->id],
            $arrayResponse->viewData('categories')->getCollection()->pluck('id')->all(),
        );
    }

    public function test_category_pagination_uses_fifty_rows_and_preserves_query_string(): void
    {
        foreach (range(1, 51) as $index) {
            $this->category(
                sprintf('Category %02d', $index),
                true,
                sprintf('2026-01-%02d 00:00:00', (($index - 1) % 28) + 1),
            );
        }

        $response = $this->actingAs($this->admin())->get(route(
            'admin.categories.index',
            ['status' => 'all', 'sort' => 'alphabetical'],
        ))->assertOk();

        $categories = $response->viewData('categories');
        $this->assertCount(50, $categories->items());
        $this->assertSame(51, $categories->total());
        $this->assertStringContainsString('sort=alphabetical', (string) $categories->nextPageUrl());
        $this->assertStringContainsString('status=all', (string) $categories->nextPageUrl());

        $pageTwo = $this->actingAs($this->admin())->get(route(
            'admin.categories.index',
            ['status' => 'all', 'sort' => 'alphabetical', 'page' => 2],
        ))->assertOk()->viewData('categories');

        $this->assertCount(1, $pageTwo->items());
    }

    public function test_all_paginated_admin_indexes_render_the_consistent_sort_control(): void
    {
        $admin = $this->admin();

        foreach ([
            ['admin.categories.index', 'category-sort'],
            ['admin.products.index', 'product-sort'],
            ['admin.platforms.index', 'platform-sort'],
            ['admin.listings.index', 'listing-sort'],
            ['admin.listings.unmatched', 'listing-sort'],
            ['admin.knowledge.index', 'knowledge-sort'],
            ['admin.unanswered.index', 'unanswered-sort'],
        ] as [$routeName, $id]) {
            $this->actingAs($admin)
                ->get(route($routeName))
                ->assertOk()
                ->assertSee('id="'.$id.'" name="sort"', false)
                ->assertSee('value="newest" selected', false);
        }
    }

    private function assertCategoryOrder(User $admin, string $sort, array $ids): void
    {
        $response = $this->actingAs($admin)
            ->get(route('admin.categories.index', ['sort' => $sort]))
            ->assertOk();

        $this->assertSame(
            $ids,
            $response->viewData('categories')->getCollection()->pluck('id')->all(),
        );
    }

    private function admin(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'admin-index@example.test'],
            ['name' => 'Admin Index', 'password' => 'password', 'is_admin' => true],
        );
    }

    private function root(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'pc-dan-komponen'],
            ['name' => 'PC Components', 'is_active' => true, 'sort_order' => 0],
        );
    }

    private function category(string $name, bool $active, string $createdAt): Category
    {
        $category = Category::query()->create([
            'name' => $name,
            'slug' => str($name)->slug()->append('-', uniqid())->toString(),
            'is_active' => $active,
            'sort_order' => 0,
        ]);

        $category->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->saveQuietly();

        return $category;
    }
}
