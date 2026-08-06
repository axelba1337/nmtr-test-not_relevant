<?php

namespace App\Services\Neomatra;

use InvalidArgumentException;
use OverflowException;

final class TotalCostCalculator
{
    public function calculate(
        int $itemPrice,
        ?int $shippingCost = null,
        ?int $serviceFee = null,
        ?int $inspectionCost = null,
        ?int $repairEstimate = null,
    ): int {
        $components = [
            'item_price' => $itemPrice,
            'shipping_cost' => $shippingCost ?? 0,
            'service_fee' => $serviceFee ?? 0,
            'inspection_cost' => $inspectionCost ?? 0,
            'repair_estimate' => $repairEstimate ?? 0,
        ];
        $total = 0;

        foreach ($components as $name => $amount) {
            if ($amount < 0) {
                throw new InvalidArgumentException(
                    "{$name} tidak boleh bernilai negatif.",
                );
            }

            if ($amount > PHP_INT_MAX - $total) {
                throw new OverflowException('Total biaya melebihi batas integer.');
            }

            $total += $amount;
        }

        return $total;
    }
}
