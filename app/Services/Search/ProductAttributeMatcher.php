<?php

namespace App\Services\Search;

use App\Helpers\Normalizer;
use App\Models\Product;

final class ProductAttributeMatcher
{
    public function __construct(
        private readonly ProductFamilyResolver $families,
    ) {}

    /** @param array<string, mixed> $intent */
    public function matches(Product $product, array $intent): bool
    {
        $category = Normalizer::text(
            (string) $product->category?->name,
        );
        $identity = Normalizer::text(implode(' ', array_filter([
            $product->name,
            $product->brand,
            $product->model,
            $product->variant,
            $product->sku_or_part_number,
        ], static fn (mixed $value): bool => is_string($value) && $value !== '')));

        $requestedCategorySlug = is_string($intent['category_slug'] ?? null)
            ? Normalizer::text($intent['category_slug'])
            : '';

        if ($requestedCategorySlug !== '') {
            $productCategorySlug = Normalizer::text(
                (string) $product->category?->slug,
            );
            $parentCategorySlug = Normalizer::text(
                (string) $product->category?->parent?->slug,
            );

            if ($requestedCategorySlug !== $productCategorySlug
                && $requestedCategorySlug !== $parentCategorySlug) {
                return false;
            }
        } elseif (! $this->containsField(
            $category,
            $intent['category'] ?? null,
        )) {
            return false;
        }

        if (! $this->containsField($identity, $intent['brand'] ?? null)) {
            return false;
        }

        if (! $this->containsField($identity, $intent['model'] ?? null)) {
            return false;
        }

        if (! $this->containsField($identity, $intent['variant'] ?? null)) {
            return false;
        }

        if (! $this->containsField(
            $identity,
            $intent['sku_or_part_number'] ?? null,
        )) {
            return false;
        }

        $requestedFamily = $intent['family'] ?? null;

        if (is_string($requestedFamily) && trim($requestedFamily) !== '') {
            $attributes = is_array($product->attributes)
                ? $product->attributes
                : [];
            $productFamily = $attributes['product_family'] ?? null;

            if (! is_string($productFamily) || $productFamily === '') {
                $resolved = $this->families->resolve($identity);
                $productFamily = $resolved['family'] ?? null;
            }

            if ($productFamily !== $requestedFamily) {
                return false;
            }
        }

        return $this->hasNoVariantConflict(
            Normalizer::text((string) ($intent['query'] ?? '')),
            $identity,
        );
    }

    private function containsField(string $identity, mixed $requested): bool
    {
        if (! is_string($requested) || trim($requested) === '') {
            return true;
        }

        $needle = $this->compact($requested);

        return $needle === '' || str_contains($this->compact($identity), $needle);
    }

    private function hasNoVariantConflict(
        string $query,
        string $productIdentity,
    ): bool {
        $requestedCapacity = $this->capacity($query);
        $productCapacity = $this->capacity($productIdentity);

        if ($requestedCapacity !== null
            && $productCapacity !== $requestedCapacity) {
            return false;
        }

        foreach ([
            'ti', 'super', 'laptop', 'pro', 'max', 'mini', 'plus', 'ultra',
        ] as $modifier) {
            $queryHasModifier = preg_match(
                '/\b'.preg_quote($modifier, '/').'\b/u',
                $query,
            ) === 1;
            $productHasModifier = preg_match(
                '/\b'.preg_quote($modifier, '/').'\b/u',
                $productIdentity,
            ) === 1;

            if ($queryHasModifier !== $productHasModifier) {
                return false;
            }
        }

        return true;
    }

    private function capacity(string $value): ?string
    {
        return preg_match('/\b(\d+)\s*(gb|tb)\b/u', $value, $match) === 1
            ? $match[1].strtolower($match[2])
            : null;
    }

    private function compact(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Normalizer::text($value))
            ?? '';
    }
}
