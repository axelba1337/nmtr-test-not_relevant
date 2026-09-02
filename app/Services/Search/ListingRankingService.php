<?php

namespace App\Services\Search;

use App\Models\Listing;
use App\Services\Neomatra\FreshnessScoreCalculator;

final class ListingRankingService
{
    public function __construct(
        private readonly FreshnessScoreCalculator $freshness,
    ) {}

    /**
     * @return array{rank_score: float, scores: array<string, float>}
     */
    public function calculate(
        Listing $listing,
        float $cosineSimilarity,
        array $requestedLocations = [],
        ?int $minimumPrice = null,
        ?int $maximumPrice = null,
    ): array {
        $scores = [
            'relevance' => $this->relevance($cosineSimilarity),
            'price' => $this->price(
                (int) $listing->price,
                $minimumPrice,
                $maximumPrice,
            ),
            'condition' => $this->condition(
                (string) $listing->condition,
            ),
            'location' => $this->location(
                $listing->location,
                $requestedLocations,
            ),
            'freshness' => $this->freshness->calculate(
                $listing->source_published_at
                    ?? $listing->last_seen_at
                    ?? $listing->last_synced_at,
            ),
        ];

        return [
            'rank_score' => round(
                ($scores['relevance'] * 0.45)
                    + ($scores['price'] * 0.20)
                    + ($scores['condition'] * 0.15)
                    + ($scores['location'] * 0.10)
                    + ($scores['freshness'] * 0.10),
                6,
            ),
            'scores' => $scores,
        ];
    }

    private function relevance(float $similarity): float
    {
        if (! is_finite($similarity)) {
            return 0.0;
        }

        return max(0.0, min(1.0, ($similarity + 1.0) / 2.0));
    }

    private function price(
        int $price,
        ?int $minimumPrice,
        ?int $maximumPrice,
    ): float {
        if ($minimumPrice === null
            || $maximumPrice === null
            || $maximumPrice <= $minimumPrice) {
            return 1.0;
        }

        return max(0.0, min(
            1.0,
            1 - (($price - $minimumPrice) / ($maximumPrice - $minimumPrice)),
        ));
    }

    private function condition(string $condition): float
    {
        return match ($condition) {
            Listing::CONDITION_NEW => 1.0,
            Listing::CONDITION_OPEN_BOX => 0.90,
            Listing::CONDITION_USED => 0.70,
            Listing::CONDITION_EX_DISPLAY => 0.60,
            Listing::CONDITION_REFURBISHED => 0.55,
            default => 0.25,
        };
    }

    /** @param list<string> $requestedLocations */
    private function location(?string $listingLocation, array $requestedLocations): float
    {
        if ($requestedLocations === []) {
            return 1.0;
        }

        $haystack = mb_strtolower(trim((string) $listingLocation), 'UTF-8');

        foreach ($requestedLocations as $location) {
            $needle = mb_strtolower(trim($location), 'UTF-8');

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return 1.0;
            }
        }

        return 0.0;
    }
}
