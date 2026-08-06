<?php

namespace App\Services\Neomatra;

use InvalidArgumentException;

final class RankingCalculator
{
    public const SCORE_RELEVANCE = 'relevance';

    public const SCORE_PRICE = 'price';

    public const SCORE_CONDITION = 'condition';

    public const SCORE_SELLER = 'seller';

    public const SCORE_FRESHNESS = 'freshness';

    /** @var array<string, float> */
    public const DEFAULT_WEIGHTS = [
        self::SCORE_RELEVANCE => 0.40,
        self::SCORE_PRICE => 0.30,
        self::SCORE_CONDITION => 0.15,
        self::SCORE_SELLER => 0.10,
        self::SCORE_FRESHNESS => 0.05,
    ];

    /**
     * Subscores must already be normalized to 0..1 by their deterministic
     * domain rules. This method deliberately does not invent that mapping.
     *
     * @param  array<string, int|float>  $scores
     * @param  array<string, int|float>|null  $weights
     */
    public function calculate(
        array $scores,
        ?array $weights = null,
    ): float {
        $weights ??= self::DEFAULT_WEIGHTS;
        $this->assertCompleteValues($scores, 'score');
        $this->assertCompleteValues($weights, 'bobot');

        $weightSum = array_sum($weights);

        if (abs($weightSum - 1.0) > 0.0001) {
            throw new InvalidArgumentException(
                'Total bobot ranking harus tepat 1,00.',
            );
        }

        $rank = 0.0;

        foreach (array_keys(self::DEFAULT_WEIGHTS) as $name) {
            $rank += (float) $scores[$name] * (float) $weights[$name];
        }

        return $rank;
    }

    /** @param array<string, int|float> $values */
    private function assertCompleteValues(array $values, string $label): void
    {
        foreach (array_keys(self::DEFAULT_WEIGHTS) as $name) {
            if (! array_key_exists($name, $values)
                || ! is_numeric($values[$name])) {
                throw new InvalidArgumentException(
                    ucfirst($label)." {$name} wajib berupa angka.",
                );
            }

            $value = (float) $values[$name];

            if (! is_finite($value) || $value < 0 || $value > 1) {
                throw new InvalidArgumentException(
                    ucfirst($label)." {$name} harus berada pada rentang 0 sampai 1.",
                );
            }
        }
    }
}
