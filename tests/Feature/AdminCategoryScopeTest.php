<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCategoryScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_create_a_root_category(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => null,
                'name' => 'Storage',
                'slug' => 'storage',
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', ['slug' => 'storage']);
    }

    public function test_ct_05_vehicle_root_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => null,
                'name' => 'Vehicle',
                'slug' => 'vehicle',
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', ['slug' => 'vehicle']);
    }

    public function test_admin_can_create_children_under_supported_roots(): void
    {
        $pcAccessories = $this->root('pc-accessories', 'PC Accessories');
        $networking = $this->root('networking', 'Networking');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => $pcAccessories->id,
                'name' => 'USB Hub',
                'slug' => 'usb-hub',
            ]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect(route('admin.categories.index'));

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => $networking->id,
                'name' => 'Managed Switch',
                'slug' => 'managed-switch',
            ]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'slug' => 'usb-hub',
            'parent_id' => $pcAccessories->id,
        ]);
        $this->assertDatabaseHas('categories', [
            'slug' => 'managed-switch',
            'parent_id' => $networking->id,
        ]);
    }

    public function test_direct_post_with_an_unsupported_parent_is_rejected(): void
    {
        $unsupported = $this->root('vehicle', 'Vehicle');

        $this->actingAs($this->admin())
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => $unsupported->id,
                'name' => 'Car Accessories',
                'slug' => 'car-accessories',
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertDatabaseMissing('categories', ['slug' => 'car-accessories']);
    }

    public function test_existing_supported_root_can_still_be_edited(): void
    {
        $root = $this->root('pc-dan-komponen', 'PC Components');

        $this->actingAs($this->admin())
            ->put(route('admin.categories.update', $root), $this->categoryPayload([
                'parent_id' => null,
                'name' => 'PC dan Komponen',
                'slug' => 'pc-dan-komponen',
            ]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect(route('admin.categories.index'));

        $this->assertDatabaseHas('categories', [
            'id' => $root->id,
            'name' => 'PC dan Komponen',
            'parent_id' => null,
        ]);
    }

    public function test_subcategory_cannot_be_moved_outside_supported_scope(): void
    {
        $supported = $this->root('networking', 'Networking');
        $unsupported = $this->root('vehicle', 'Vehicle');
        $child = $this->child($supported, 'Router', 'router');

        $this->actingAs($this->admin())
            ->put(route('admin.categories.update', $child), $this->categoryPayload([
                'parent_id' => $unsupported->id,
                'name' => $child->name,
                'slug' => $child->slug,
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->assertSame($supported->id, $child->fresh()?->parent_id);
    }

    public function test_parent_cycle_validation_and_dropdown_protection_remain_active(): void
    {
        $root = $this->root('networking', 'Networking');
        $child = $this->child($root, 'Router', 'router');
        $grandchild = $this->child($child, 'Wi-Fi 6', 'wi-fi-6');
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('admin.categories.update', $child), $this->categoryPayload([
                'parent_id' => $grandchild->id,
                'name' => $child->name,
                'slug' => $child->slug,
            ]))
            ->assertSessionHasErrors('parent_id');

        $this->actingAs($admin)
            ->get(route('admin.categories.edit', $child))
            ->assertOk()
            ->assertDontSee('value="'.$child->id.'"', false)
            ->assertDontSee('value="'.$grandchild->id.'"', false);
    }

    public function test_parent_dropdown_only_contains_supported_scope_categories(): void
    {
        $supported = $this->root('networking', 'Networking');
        $supportedChild = $this->child($supported, 'Router', 'router');
        $unsupported = $this->root('vehicle', 'Vehicle');

        $this->actingAs($this->admin())
            ->get(route('admin.categories.create'))
            ->assertOk()
            ->assertSee('value="'.$supported->id.'"', false)
            ->assertSee('value="'.$supportedChild->id.'"', false)
            ->assertDontSee('value="'.$unsupported->id.'"', false);
    }

    public function test_non_admin_is_forbidden_and_product_guardrail_still_rejects_unsupported_category(): void
    {
        $unsupported = $this->root('vehicle', 'Vehicle');
        $user = User::query()->create([
            'name' => 'Catalog User',
            'email' => 'catalog-user@example.test',
            'password' => 'test-password',
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->post(route('admin.categories.store'), $this->categoryPayload([
                'parent_id' => $unsupported->id,
            ]))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->post(route('admin.products.store'), [
                'category_id' => $unsupported->id,
                'name' => 'Unsupported Product',
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('category_id');

        $this->assertDatabaseCount('products', 0);
    }

    private function admin(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'category-admin@example.test'],
            [
                'name' => 'Category Admin',
                'password' => 'test-password',
                'is_admin' => true,
            ],
        );
    }

    private function root(string $slug, string $name): Category
    {
        return Category::query()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    private function child(
        Category $parent,
        string $name,
        string $slug,
    ): Category {
        return Category::query()->create([
            'parent_id' => $parent->id,
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function categoryPayload(array $overrides = []): array
    {
        return array_merge([
            'parent_id' => null,
            'name' => 'Test Category',
            'slug' => 'test-category',
            'description' => null,
            'is_active' => '1',
            'sort_order' => '10',
        ], $overrides);
    }
}
