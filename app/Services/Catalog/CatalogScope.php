<?php

namespace App\Services\Catalog;

use App\Models\Category;
use Illuminate\Support\Collection;

final class CatalogScope
{
    /** @return array<string, array<string, mixed>> */
    public function canonicalTaxonomy(): array
    {
        return (array) config('neomatra.scope.category_taxonomy', []);
    }

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
        return $this->supportsCanonicalPath($category, []);
    }

    public function isSupportedRoot(Category $category): bool
    {
        return $category->parent_id === null
            && $this->canonicalParentSlug((string) $category->slug) === null
            && $this->isCanonicalSlug((string) $category->slug);
    }

    public function canCreateChildUnder(Category $category): bool
    {
        $definition = $this->canonicalDefinition((string) $category->slug);

        return $this->supports($category)
            && is_array($definition)
            && ($definition['children'] ?? []) !== [];
    }

    public function isCanonicalSlug(string $slug): bool
    {
        return $this->canonicalDefinition($slug) !== null;
    }

    public function matchesCanonicalName(string $slug, string $name): bool
    {
        $definition = $this->canonicalDefinition($slug);

        return is_array($definition)
            && $this->normalizeName($name)
                === $this->normalizeName((string) ($definition['name'] ?? ''));
    }

    public function matchesCanonicalParent(
        string $slug,
        ?Category $parent,
    ): bool {
        if (! $this->isCanonicalSlug($slug)) {
            return false;
        }

        $expectedParentSlug = $this->canonicalParentSlug($slug);

        if ($expectedParentSlug === null) {
            return $parent === null;
        }

        return $parent instanceof Category
            && strtolower(trim((string) $parent->slug)) === $expectedParentSlug
            && $this->supports($parent);
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

    /** @return Collection<int, Category> */
    public function validParentOptions(?Category $category = null): Collection
    {
        $expectedParentSlug = $category instanceof Category
            ? $this->canonicalParentSlug((string) $category->slug)
            : null;

        return $this->categoryOptions()
            ->filter(function (Category $candidate) use (
                $category,
                $expectedParentSlug,
            ): bool {
                if ($category instanceof Category) {
                    return $expectedParentSlug !== null
                        && $candidate->slug === $expectedParentSlug;
                }

                return $this->canCreateChildUnder($candidate);
            })
            ->values();
    }

    /** @return array<string, mixed>|null */
    private function canonicalDefinition(string $slug): ?array
    {
        $slug = strtolower(trim($slug));

        foreach ($this->canonicalTaxonomy() as $rootSlug => $root) {
            if ($slug === $rootSlug) {
                return $root + ['parent_slug' => null];
            }

            foreach ((array) ($root['children'] ?? []) as $childSlug => $child) {
                if ($slug === $childSlug) {
                    return (array) $child + ['parent_slug' => $rootSlug];
                }
            }
        }

        return null;
    }

    private function canonicalParentSlug(string $slug): ?string
    {
        $definition = $this->canonicalDefinition($slug);

        return is_string($definition['parent_slug'] ?? null)
            ? $definition['parent_slug']
            : null;
    }

    /** @param array<int, true> $visited */
    private function supportsCanonicalPath(
        Category $category,
        array $visited,
    ): bool {
        $categoryId = (int) $category->getKey();

        if (isset($visited[$categoryId]) || ! $category->is_active) {
            return false;
        }

        $visited[$categoryId] = true;
        $slug = strtolower(trim((string) $category->slug));

        if (! $this->isCanonicalSlug($slug)) {
            return false;
        }

        $expectedParentSlug = $this->canonicalParentSlug($slug);

        if ($expectedParentSlug === null) {
            return $category->parent_id === null;
        }

        $parent = $category->relationLoaded('parent')
            ? $category->parent
            : $category->parent()->first();

        return $parent instanceof Category
            && strtolower(trim((string) $parent->slug)) === $expectedParentSlug
            && $this->supportsCanonicalPath($parent, $visited);
    }

    private function normalizeName(string $name): string
    {
        return mb_strtolower(
            preg_replace('/\s+/u', ' ', trim($name)) ?? '',
            'UTF-8',
        );
    }
}
