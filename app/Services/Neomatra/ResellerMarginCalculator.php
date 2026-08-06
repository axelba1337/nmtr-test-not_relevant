<?php

namespace App\Services\Neomatra;

use InvalidArgumentException;

final class ResellerMarginCalculator
{
    /** @return array{net_margin: int, margin_percent: float} */
    public function calculate(
        int $estimatedResalePrice,
        int $totalCost,
        ?int $sellingFee = null,
        ?int $storageCost = null,
    ): array {
        $values = [
            'estimated_resale_price' => $estimatedResalePrice,
            'total_cost' => $totalCost,
            'selling_fee' => $sellingFee ?? 0,
            'storage_cost' => $storageCost ?? 0,
        ];

        foreach ($values as $name => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException(
                    "{$name} tidak boleh bernilai negatif.",
                );
            }
        }

        if ($totalCost === 0) {
            throw new InvalidArgumentException(
                'Total biaya harus lebih besar dari nol.',
            );
        }

        $netMargin = $estimatedResalePrice
            - $totalCost
            - ($sellingFee ?? 0)
            - ($storageCost ?? 0);

        return [
            'net_margin' => $netMargin,
            'margin_percent' => ($netMargin / $totalCost) * 100,
        ];
    }
}
