<?php

namespace App\Helpers;

final class Similarity
{
    /**
     * @return list<float>|null
     */
    public static function validatedVector(
        array $vector,
        ?int $expectedDimensions = null,
    ): ?array {
        if ($vector === []) {
            return null;
        }

        if ($expectedDimensions !== null
            && ($expectedDimensions < 1 || count($vector) !== $expectedDimensions)) {
            return null;
        }

        $validated = [];
        $magnitudeSquared = 0.0;

        foreach (array_values($vector) as $value) {
            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $value = (float) $value;

            if (! is_finite($value)) {
                return null;
            }

            $magnitudeSquared += $value * $value;

            if (! is_finite($magnitudeSquared)) {
                return null;
            }

            $validated[] = $value;
        }

        return $magnitudeSquared > 0.0 ? $validated : null;
    }

    public static function cosine(array $left, array $right): ?float
    {
        $left = self::validatedVector($left);
        $right = self::validatedVector($right, count($left ?? []));

        if ($left === null || $right === null) {
            return null;
        }

        $dotProduct = 0.0;
        $leftMagnitudeSquared = 0.0;
        $rightMagnitudeSquared = 0.0;

        foreach ($left as $index => $leftValue) {
            $rightValue = $right[$index];
            $dotProduct += $leftValue * $rightValue;
            $leftMagnitudeSquared += $leftValue * $leftValue;
            $rightMagnitudeSquared += $rightValue * $rightValue;
        }

        $denominator = sqrt($leftMagnitudeSquared)
            * sqrt($rightMagnitudeSquared);

        if (! is_finite($dotProduct)
            || ! is_finite($denominator)
            || $denominator <= 0.0) {
            return null;
        }

        $similarity = $dotProduct / $denominator;

        if (! is_finite($similarity)) {
            return null;
        }

        return max(-1.0, min(1.0, $similarity));
    }
}
