<?php

namespace App\Services\Search;

use App\Models\Listing;
use App\Models\RankingProfile;
use App\Services\Neomatra\FreshnessScoreCalculator;
use App\Services\Neomatra\RankingCalculator;

final class ListingRankingService
{
    /** @var array<string, float>|null */
    private ?array $resolvedWeights = null;

    private bool $weightsLoaded = false;

    public function __construct(
        private readonly FreshnessScoreCalculator $freshness,
        private readonly RankingCalculator $ranking,
    ) {}

    /**
     * SRS fixes the weight formula but leaves subscore normalization open.
     * These explicit MVP mappings keep every component within 0..1 and are
     * visible through the API response/admin settings rather than hidden.
     *
     * @return array{rank_score: float, scores: array<string, float>}
     */
    public function calculate(
        Listing $listing,
        float $cosineSimilarity,
        ?float $undervaluedPercentage,
    ): array {
        $scores = [
            RankingCalculator::SCORE_RELEVANCE => $this->relevance(
                $cosineSimilarity,
            ),
            RankingCalculator::SCORE_PRICE => $this->price(
                $undervaluedPercentage,
            ),
            RankingCalculator::SCORE_CONDITION => $this->condition(
                (string) $listing->condition,
            ),
            RankingCalculator::SCORE_SELLER => $this->seller(
                (string) $listing->seller_type,
            ),
            RankingCalculator::SCORE_FRESHNESS => $this->freshness->calculate(
                $listing->source_published_at
                    ?? $listing->last_seen_at
                    ?? $listing->last_synced_at,
            ),
        ];

        return [
            'rank_score' => $this->ranking->calculate(
                $scores,
                $this->weights(),
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

    private function price(?float $percentage): float
    {
        if ($percentage === null || ! is_finite($percentage)) {
            return 0.0;
        }

        return max(0.0, min(1.0, $percentage / 35.0));
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

    private function seller(string $sellerType): float
    {
        return match ($sellerType) {
            Listing::SELLER_OFFICIAL => 1.0,
            Listing::SELLER_STORE => 0.85,
            Listing::SELLER_RESELLER => 0.65,
            Listing::SELLER_INDIVIDUAL => 0.50,
            default => 0.25,
        };
    }

    /** @return array<string, float>|null */
    private function weights(): ?array
    {
        if (! $this->weightsLoaded) {
            $this->resolvedWeights = RankingProfile::query()
                ->active()
                ->latest('id')
                ->first()
                ?->weights();
            $this->weightsLoaded = true;
        }

        return $this->resolvedWeights;
    }
}
