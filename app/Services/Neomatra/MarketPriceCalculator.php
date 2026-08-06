<?php

namespace App\Services\Neomatra;

use App\Models\Listing;

final class MarketPriceCalculator
{
    /**
     * The caller must provide listings for the same canonical product,
     * variant, condition, and currency.
     */
    public function calculate(iterable $comparableListings): ?int
    {
        $pricesByFingerprint = [];

        foreach ($comparableListings as $index => $listing) {
            $price = $this->value($listing, 'price');

            if (! is_int($price) || $price <= 0) {
                continue;
            }

            if ($this->value($listing, 'is_active', true) === false
                || $this->value($listing, 'stock_status') === Listing::STOCK_OUT_OF_STOCK
                || $this->value(
                    $listing,
                    'price_type',
                    Listing::PRICE_FULL,
                ) !== Listing::PRICE_FULL) {
                continue;
            }

            $fingerprint = $this->value($listing, 'source_fingerprint');
            $key = filled($fingerprint)
                ? (string) $fingerprint
                : 'row:'.(string) $index;

            $pricesByFingerprint[$key] = $price;
        }

        return $this->median($pricesByFingerprint);
    }

    public function median(iterable $prices): ?int
    {
        $valid = [];

        foreach ($prices as $price) {
            if (is_int($price) && $price > 0) {
                $valid[] = $price;
            }
        }

        if ($valid === []) {
            return null;
        }

        sort($valid, SORT_NUMERIC);
        $count = count($valid);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $valid[$middle];
        }

        $left = $valid[$middle - 1];
        $right = $valid[$middle];

        // Round a half-rupiah upward because money is persisted as whole IDR.
        return $left + intdiv($right - $left, 2)
            + (($right - $left) % 2);
    }

    private function value(
        mixed $record,
        string $key,
        mixed $default = null,
    ): mixed {
        if (is_array($record)) {
            return $record[$key] ?? $default;
        }

        if (is_object($record)) {
            return data_get($record, $key, $default);
        }

        return $default;
    }
}
