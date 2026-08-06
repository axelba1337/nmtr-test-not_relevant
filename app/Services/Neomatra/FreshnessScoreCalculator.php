<?php

namespace App\Services\Neomatra;

use DateTimeImmutable;
use DateTimeInterface;

final class FreshnessScoreCalculator
{
    public const FRESH_HOURS = 24;

    public const RECENT_HOURS = 72;

    public const WEEK_HOURS = 168;

    public const MONTH_HOURS = 720;

    /**
     * SRS does not define freshness normalization. These explicit MVP buckets
     * keep the ranking deterministic and can be replaced after product review.
     */
    public function calculate(
        ?DateTimeInterface $sourceTimestamp,
        ?DateTimeInterface $reference = null,
    ): float {
        if ($sourceTimestamp === null) {
            return 0.0;
        }

        $reference ??= new DateTimeImmutable('now');
        $ageSeconds = max(
            0,
            $reference->getTimestamp() - $sourceTimestamp->getTimestamp(),
        );
        $ageHours = $ageSeconds / 3600;

        return match (true) {
            $ageHours <= self::FRESH_HOURS => 1.0,
            $ageHours <= self::RECENT_HOURS => 0.75,
            $ageHours <= self::WEEK_HOURS => 0.50,
            $ageHours <= self::MONTH_HOURS => 0.25,
            default => 0.0,
        };
    }
}
