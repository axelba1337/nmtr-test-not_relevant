<?php

namespace App\Services\Neomatra;

use InvalidArgumentException;

final class UndervaluedCalculator
{
    public const LABEL_NORMAL = 'Harga normal';

    public const LABEL_SLIGHTLY_BELOW = 'Sedikit di bawah pasar';

    public const LABEL_UNDERVALUED = 'Undervalued';

    public const LABEL_VERY_UNDERVALUED = 'Sangat undervalued, perlu pemeriksaan';

    public const LABEL_PRICE_ANOMALY = 'Anomali harga atau risiko tinggi';

    /** @return array{percentage: float, label: string} */
    public function calculate(int $marketPrice, int $totalCost): array
    {
        if ($marketPrice <= 0) {
            throw new InvalidArgumentException(
                'Harga pasar harus lebih besar dari nol.',
            );
        }

        if ($totalCost < 0) {
            throw new InvalidArgumentException(
                'Total biaya tidak boleh bernilai negatif.',
            );
        }

        $percentage = (($marketPrice - $totalCost) / $marketPrice) * 100;

        return [
            'percentage' => $percentage,
            'label' => $this->label($percentage),
        ];
    }

    public function label(float $percentage): string
    {
        if ($percentage < 5) {
            return self::LABEL_NORMAL;
        }

        if ($percentage < 10) {
            return self::LABEL_SLIGHTLY_BELOW;
        }

        if ($percentage < 20) {
            return self::LABEL_UNDERVALUED;
        }

        if ($percentage <= 35) {
            return self::LABEL_VERY_UNDERVALUED;
        }

        return self::LABEL_PRICE_ANOMALY;
    }
}
