<?php

namespace App\Services\Marketplace;

use App\Data\Marketplace\MarketplaceListingData;
use App\Helpers\Normalizer;
use App\Models\Product;
use App\Models\ProductAlias;

final class MarketplaceProductMatcher
{
    public function match(MarketplaceListingData $listing): ?Product
    {
        $title = Normalizer::text($listing->title);
        $description = Normalizer::text((string) $listing->description);

        if ($this->isUnsafeAdvertisement($title)) {
            return null;
        }

        if ($listing->productReference !== null) {
            $referenced = $this->matchReference($listing);

            return $referenced !== null
                && $this->isCompatible($referenced, $listing, $title)
                    ? $referenced
                    : null;
        }

        $ranked = [];

        Product::query()
            ->active()
            ->whereHas('category', static fn ($query) => $query->active())
            ->with(['category.parent', 'aliases'])
            ->lazyById(100)
            ->each(function (Product $product) use (
                &$ranked,
                $listing,
                $title,
                $description,
            ): void {
                if (! $this->isCompatible($product, $listing, $title)) {
                    return;
                }

                $rank = $this->identityRank(
                    $product,
                    $listing,
                    $title,
                    $description,
                );

                if ($rank > 0) {
                    $ranked[] = ['product' => $product, 'rank' => $rank];
                }
            });

        if ($ranked === []) {
            return null;
        }

        usort($ranked, static fn (array $left, array $right): int => $right['rank'] <=> $left['rank']);
        $bestRank = $ranked[0]['rank'];
        $best = array_values(array_filter(
            $ranked,
            static fn (array $candidate): bool => $candidate['rank'] === $bestRank,
        ));

        return count($best) === 1 ? $best[0]['product'] : null;
    }

    private function matchReference(
        MarketplaceListingData $listing,
    ): ?Product {
        if ($listing->productReference === null) {
            return null;
        }

        $product = Product::query()
            ->active()
            ->where('normalized_key', $listing->productReference)
            ->with(['category.parent', 'aliases'])
            ->first();

        if ($product instanceof Product) {
            return $product;
        }

        $productIds = ProductAlias::query()
            ->where(
                'normalized_alias',
                Normalizer::text($listing->productReference),
            )
            ->whereHas('product', static fn ($query) => $query->active())
            ->distinct()
            ->limit(2)
            ->pluck('product_id');

        return $productIds->count() === 1
            ? Product::query()
                ->with(['category.parent', 'aliases'])
                ->find((int) $productIds->first())
            : null;
    }

    private function identityRank(
        Product $product,
        MarketplaceListingData $listing,
        string $title,
        string $description,
    ): int {
        $identityText = trim($title.' '.$description);
        $productSku = Normalizer::text((string) $product->sku_or_part_number);
        $requestedSku = Normalizer::text((string) $listing->productSku);

        if ($requestedSku !== '' && $requestedSku === $productSku) {
            return 600;
        }

        $requestedModel = Normalizer::text((string) $listing->productModel);
        $productModel = Normalizer::text((string) $product->model);

        if ($requestedModel !== ''
            && $this->compact($requestedModel) === $this->compact($productModel)) {
            return 550;
        }

        $rank = 0;

        foreach ($product->aliases as $alias) {
            if (! $alias instanceof ProductAlias) {
                continue;
            }

            $normalizedAlias = Normalizer::text((string) $alias->alias);

            if ($normalizedAlias !== '' && $normalizedAlias === $title) {
                $rank = max($rank, 520 + mb_strlen($normalizedAlias, 'UTF-8'));
            } elseif ($this->containsIdentity($identityText, $normalizedAlias)) {
                $rank = max($rank, 450 + mb_strlen($normalizedAlias, 'UTF-8'));
            }
        }

        if ($productModel !== '' && $this->containsIdentity($identityText, $productModel)) {
            $brand = Normalizer::text((string) $product->brand);
            $brandPresent = $brand !== '' && $this->containsIdentity($identityText, $brand);
            $rank = max($rank, $brandPresent ? 440 : 400);
        }

        return $rank;
    }

    private function isCompatible(
        Product $product,
        MarketplaceListingData $listing,
        string $title,
    ): bool {
        $categorySlug = (string) $product->category?->slug;

        if ($this->hasCategoryConflict($categorySlug, $title)) {
            return false;
        }

        foreach ([
            [$listing->productBrand, $product->brand],
            [$listing->productSku, $product->sku_or_part_number],
        ] as [$expected, $actual]) {
            if (is_string($expected)
                && trim($expected) !== ''
                && $this->compact($expected) !== $this->compact((string) $actual)) {
                return false;
            }
        }

        $listingCapacity = $this->capacity($title);
        $productCapacity = $this->capacity(Normalizer::text(implode(' ', [
            (string) $product->name,
            (string) $product->model,
            (string) $product->variant,
        ])));

        if ($listingCapacity !== null
            && $productCapacity !== null
            && $listingCapacity !== $productCapacity) {
            return false;
        }

        $productIdentity = Normalizer::text(implode(' ', [
            (string) $product->name,
            (string) $product->model,
            (string) $product->variant,
        ]));

        foreach (['ti', 'super', 'pro', 'max', 'mini', 'plus', 'ultra'] as $modifier) {
            $listingHas = preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $title) === 1;

            if ($modifier === 'ti'
                && preg_match('/\bnon[\s-]*ti\b/u', $title) === 1) {
                $listingHas = false;
            }

            $productHas = preg_match('/\b'.preg_quote($modifier, '/').'\b/u', $productIdentity) === 1;

            if ($listingHas !== $productHas) {
                return false;
            }
        }

        return true;
    }

    private function hasCategoryConflict(string $categorySlug, string $title): bool
    {
        $standaloneComponents = [
            'gpu',
            'cpu',
            'ram',
            'ssd',
            'hdd',
            'motherboard',
            'psu',
            'cpu-cooler',
        ];

        if (in_array($categorySlug, $standaloneComponents, true)
            && preg_match(
                '/\b(laptop|notebook|pc\s*(?:gaming|rakitan|desktop|fullset|full\s*set)|komputer\s*(?:rakitan|gaming)|mini\s*pc|all\s*in\s*one)\b/u',
                $title,
            ) === 1) {
            return true;
        }

        if ($categorySlug === 'gpu' && preg_match(
            '/\b(ssd|motherboard|processor|prosesor|ram|memory)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'cpu' && preg_match(
            '/\b(vga|gpu|graphics?\s*card|kartu\s*grafis|motherboard)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'monitor' && preg_match(
            '/\b(stand|bracket|mount|arm|panel|screen)\s*(?:only|saja|monitor)?\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'router' && preg_match(
            '/\b(network\s*switch|switch\s+(?:hub|managed|unmanaged)|access\s*point|wireless\s*ap|modem\s+only)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'switch' && preg_match(
            '/\b(router|access\s*point|wireless\s*ap|network\s*adapter)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'access-point' && preg_match(
            '/\b(network\s*switch|managed\s*switch|router\s+only|network\s*adapter)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        if ($categorySlug === 'smartphone' && preg_match(
            '/\b(casing|case|tempered\s*glass|screen\s*protector|charger|kabel)\b/u',
            $title,
        ) === 1) {
            return true;
        }

        return false;
    }

    private function isUnsafeAdvertisement(string $title): bool
    {
        return preg_match(
            '/\b(jasa|service|servis|repair|perbaikan|sewa|rental|dicari|mencari|wanted|wtb|terima\s+beli|box\s*only|dus\s*(?:saja|kosong)|kotak\s*(?:saja|kosong)|empty\s*box|sparepart|spare\s*part|paket|bundle|bundling|borongan|fullset|full\s*set|casing|case\s*only|cover|kabel|cable|charger|bracket|mount\s*only|stand\s*only|heatsink\s*only|fan\s*only)\b/u',
            $title,
        ) === 1;
    }

    private function containsIdentity(string $haystack, string $needle): bool
    {
        $compactNeedle = $this->compact($needle);

        return $compactNeedle !== ''
            && str_contains($this->compact($haystack), $compactNeedle);
    }

    private function capacity(string $value): ?string
    {
        return preg_match('/\b(\d+)\s*(gb|tb)\b/u', $value, $match) === 1
            ? $match[1].strtolower($match[2])
            : null;
    }

    private function compact(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Normalizer::text($value)) ?? '';
    }
}
