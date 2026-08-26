<?php

namespace App\Services\Search;

use App\Data\AI\ParsedIntent;
use App\Data\Search\ListingSearchOutcome;
use App\Data\Search\SearchState;
use App\Models\Platform;
use App\Models\Product;

final class RelatedProductRetriever
{
    public function __construct(
        private readonly ProductFamilyResolver $families,
        private readonly ListingSearchService $listings,
    ) {}

    public function retrieve(
        Product $requested,
        ParsedIntent $intent,
        ListingSearchOutcome $exactOutcome,
    ): ?ListingSearchOutcome {
        $requested->loadMissing('category.parent');
        $requestedMetadata = $this->metadata($requested);
        $ranked = [];

        Product::query()
            ->active()
            ->whereKeyNot($requested->getKey())
            ->where('category_id', $requested->category_id)
            ->whereHas('listings', static fn ($query) => $query
                ->available()
                ->whereHas('platform', static fn ($platform) => $platform
                    ->publiclyAvailable()
                    ->where('status', '!=', Platform::STATUS_ERROR)))
            ->with(['category.parent', 'aliases'])
            ->withCount(['listings as active_listing_count' => static fn ($query) => $query
                ->available()
                ->whereHas('platform', static fn ($platform) => $platform
                    ->publiclyAvailable()
                    ->where('status', '!=', Platform::STATUS_ERROR))])
            ->lazyById(100)
            ->each(function (Product $product) use (
                &$ranked,
                $requestedMetadata,
            ): void {
                $metadata = $this->metadata($product);
                $tier = $this->tier($requestedMetadata, $metadata);
                $requestedNumber = $requestedMetadata['model_tier'];
                $candidateNumber = $metadata['model_tier'];
                $distance = is_int($requestedNumber) && is_int($candidateNumber)
                    ? abs($requestedNumber - $candidateNumber)
                    : PHP_INT_MAX;

                $ranked[] = [
                    'product' => $product,
                    'similarity' => null,
                    'matched_via' => 'related_tier_'.$tier,
                    '_tier' => $tier,
                    '_distance' => $distance,
                    '_metadata' => $metadata,
                ];
            });

        usort($ranked, static function (array $left, array $right): int {
            $tier = $left['_tier'] <=> $right['_tier'];

            if ($tier !== 0) {
                return $tier;
            }

            $distance = $left['_distance'] <=> $right['_distance'];

            if ($distance !== 0) {
                return $distance;
            }

            $availability = $right['product']->active_listing_count
                <=> $left['product']->active_listing_count;

            return $availability !== 0
                ? $availability
                : $left['product']->getKey() <=> $right['product']->getKey();
        });

        $ranked = array_slice($ranked, 0, 20);

        if ($ranked === []) {
            return null;
        }

        $appliedIntent = $this->withAppliedFilters(
            $intent,
            $exactOutcome->appliedFilters,
        );
        $results = $this->listings->search($ranked, $appliedIntent);

        if ($results === []) {
            return null;
        }

        $byProduct = [];

        foreach ($ranked as $candidate) {
            $productId = (int) $candidate['product']->getKey();
            $byProduct[$productId] = $candidate;
        }

        usort($results, static function (array $left, array $right) use ($byProduct): int {
            $leftCandidate = $byProduct[(int) $left['product_id']];
            $rightCandidate = $byProduct[(int) $right['product_id']];
            $tier = $leftCandidate['_tier'] <=> $rightCandidate['_tier'];

            if ($tier !== 0) {
                return $tier;
            }

            $distance = $leftCandidate['_distance'] <=> $rightCandidate['_distance'];

            return $distance !== 0
                ? $distance
                : $right['ranking']['rank_score'] <=> $left['ranking']['rank_score'];
        });

        $resultProductIds = array_values(array_unique(array_map(
            static fn (array $result): int => (int) $result['product_id'],
            $results,
        )));
        $best = $byProduct[$resultProductIds[0]];
        $strategy = $this->strategy((int) $best['_tier']);
        $details = [];

        foreach ($resultProductIds as $productId) {
            $candidate = $byProduct[$productId];
            $metadata = $candidate['_metadata'];
            $details[] = [
                'product_id' => $productId,
                'tier' => (int) $candidate['_tier'],
                'strategy' => $this->strategy((int) $candidate['_tier']),
                'different_brand' => $requestedMetadata['brand'] !== $metadata['brand'],
                'different_family' => $requestedMetadata['family'] !== $metadata['family'],
                'different_generation' => $requestedMetadata['generation'] !== $metadata['generation'],
                'model_distance' => $candidate['_distance'] === PHP_INT_MAX
                    ? null
                    : (int) $candidate['_distance'],
            ];
        }

        $closestPrice = null;
        $difference = null;

        if (in_array('budget', $exactOutcome->relaxedFilters, true)
            && $intent->budgetMax !== null) {
            $closestPrice = (int) $results[0]['costs']['total'];
            $difference = max(0, $closestPrice - $intent->budgetMax);
        }

        return new ListingSearchOutcome(
            SearchState::ALTERNATIVE_PRODUCT,
            $results,
            $exactOutcome->requestedFilters,
            $exactOutcome->appliedFilters,
            $exactOutcome->relaxedFilters,
            $exactOutcome->attemptCount + 1,
            $exactOutcome->platformsSearched,
            $exactOutcome->platformsFailed,
            $closestPrice,
            $difference,
            $strategy,
            (int) $requested->getKey(),
            $resultProductIds,
            'related_product',
            $details,
        );
    }

    /** @return array{brand: string|null, family: string|null, generation: string|null, model_number: int|null, model_tier: int|null} */
    private function metadata(Product $product): array
    {
        $attributes = is_array($product->attributes)
            ? $product->attributes
            : [];
        $resolved = $this->families->resolve(implode(' ', array_filter([
            $product->brand,
            $product->model,
            $product->name,
        ]))) ?? [];

        return [
            'brand' => $product->brand === null
                ? null
                : mb_strtolower($product->brand, 'UTF-8'),
            'family' => $attributes['product_family']
                ?? $resolved['family']
                ?? null,
            'generation' => $attributes['generation']
                ?? $resolved['generation']
                ?? null,
            'model_number' => is_numeric($attributes['model_number'] ?? null)
                ? (int) $attributes['model_number']
                : ($resolved['model_number'] ?? null),
            'model_tier' => $this->modelTier(
                (string) $product->model,
                is_numeric($attributes['model_number'] ?? null)
                    ? (int) $attributes['model_number']
                    : ($resolved['model_number'] ?? null),
            ),
        ];
    }

    private function modelTier(string $model, mixed $number): ?int
    {
        if (! is_int($number)) {
            return null;
        }

        $normalized = mb_strtolower($model, 'UTF-8');
        $modifier = match (true) {
            preg_match('/\bti\b/u', $normalized) === 1 => 6,
            preg_match('/\bsuper\b/u', $normalized) === 1 => 3,
            default => 0,
        };

        return ($number * 10) + $modifier;
    }

    /** @param array<string, mixed> $requested @param array<string, mixed> $candidate */
    private function tier(array $requested, array $candidate): int
    {
        $sameBrand = $requested['brand'] !== null
            && $requested['brand'] === $candidate['brand'];
        $sameFamily = $requested['family'] !== null
            && $requested['family'] === $candidate['family'];
        $sameGeneration = $requested['generation'] !== null
            && $requested['generation'] === $candidate['generation'];

        return match (true) {
            $sameBrand && $sameFamily && $sameGeneration => 1,
            $sameBrand && $sameFamily => 2,
            $sameBrand => 3,
            default => 4,
        };
    }

    private function strategy(int $tier): string
    {
        return match ($tier) {
            1 => 'same_brand_same_family_same_generation',
            2 => 'same_brand_same_family',
            3 => 'same_brand_different_family',
            default => 'same_category_different_brand',
        };
    }

    /** @param array<string, mixed> $filters */
    private function withAppliedFilters(
        ParsedIntent $intent,
        array $filters,
    ): ParsedIntent {
        $values = [
            ...$intent->toArray(),
            'conditions' => $filters['conditions'] ?? $intent->conditions,
            'platforms' => $filters['platforms'] ?? $intent->platforms,
            'seller_types' => $filters['seller_types'] ?? $intent->sellerTypes,
            'locations' => $filters['locations'] ?? $intent->locations,
            'budget_min' => $filters['budget_min'] ?? null,
            'budget_max' => $filters['budget_max'] ?? null,
            'needs_clarification' => false,
            'clarification_question' => null,
        ];

        return ParsedIntent::fromArray($values) ?? $intent;
    }
}
