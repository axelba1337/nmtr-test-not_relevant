<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

final class CatalogScope
{
    /** @return list<string> */
    public function supportedRootSlugs(): array
    {
        return array_values(array_map(
            static fn (mixed $slug): string => strtolower(trim((string) $slug)),
            (array) config('neomatra.scope.supported_category_slugs', []),
        ));
    }

    public function supports(Category $category): bool
    {
        $root = $category;
        $visited = [];

        while ($root->parent_id !== null) {
            if (isset($visited[$root->getKey()])) {
                return false;
            }

            $visited[$root->getKey()] = true;
            $parent = $root->relationLoaded('parent')
                ? $root->parent
                : $root->parent()->first();

            if (! $parent instanceof Category) {
                return false;
            }

            $root = $parent;
        }

        return $category->is_active
            && $root->is_active
            && in_array($root->slug, $this->supportedRootSlugs(), true);
    }

    public function supportsId(int $categoryId): bool
    {
        $category = Category::query()->with('parent.parent')->find($categoryId);

        return $category instanceof Category && $this->supports($category);
    }

    /** @return Collection<int, Category> */
    public function categoryOptions(): Collection
    {
        return Category::query()
            ->active()
            ->with('parent.parent')
            ->ordered()
            ->get(['id', 'parent_id', 'name', 'slug', 'is_active', 'sort_order'])
            ->filter(fn (Category $category): bool => $this->supports($category))
            ->values();
    }
}
