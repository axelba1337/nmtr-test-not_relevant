<?php

namespace App\Services\Search;

use App\Data\AI\ParsedIntent;
use App\Data\Search\ListingSearchOutcome;
use App\Data\Search\SearchState;

final class SearchResponseComposer
{
    public function forOutcome(
        ListingSearchOutcome $outcome,
        ParsedIntent $intent,
    ): string {
        if ($outcome->state === SearchState::ALTERNATIVE_PRODUCT
            && $outcome->results !== []) {
            $first = $outcome->results[0];
            $detail = $outcome->alternativeDetails[0] ?? [];
            $difference = match (true) {
                ($detail['different_brand'] ?? false) === true => ' Alternatif teratas berasal dari brand berbeda.',
                ($detail['different_family'] ?? false) === true => ' Alternatif teratas berasal dari family berbeda.',
                ($detail['different_generation'] ?? false) === true => ' Alternatif teratas berasal dari generasi berbeda.',
                default => ' Alternatif teratas berada pada brand, family, dan generasi yang paling dekat.',
            };

            return sprintf(
                'Produk exact belum memiliki listing usable. Ditemukan %d listing produk terkait; prioritas teratas adalah %s dengan total biaya estimasi Rp%s.%s',
                count($outcome->results),
                (string) $first['product']['name'],
                number_format((int) $first['costs']['total'], 0, ',', '.'),
                $difference,
            );
        }

        if ($outcome->state === SearchState::FILTER_TOO_STRICT
            && $outcome->results !== []) {
            $first = $outcome->results[0];
            $relaxed = implode(', ', array_map(
                static fn (string $filter): string => match ($filter) {
                    'seller_type' => 'tipe seller',
                    'location' => 'lokasi',
                    'platforms' => 'platform',
                    'budget' => 'budget',
                    default => $filter,
                },
                $outcome->relaxedFilters,
            ));
            $budgetNote = $outcome->budgetDifference !== null
                && $outcome->budgetDifference > 0
                ? sprintf(
                    ' Alternatif terdekat melebihi budget sekitar Rp%s.',
                    number_format($outcome->budgetDifference, 0, ',', '.'),
                )
                : '';

            return sprintf(
                'Filter awal terlalu ketat. Ditemukan %d alternatif setelah melonggarkan %s. Hasil terdekat: %s dengan total biaya estimasi Rp%s.%s',
                count($outcome->results),
                $relaxed === '' ? 'filter opsional' : $relaxed,
                (string) $first['title'],
                number_format((int) $first['costs']['total'], 0, ',', '.'),
                $budgetNote,
            );
        }

        if ($outcome->state === SearchState::PARTIAL_RESULT
            && $outcome->results !== []) {
            return sprintf(
                'Sebagian sumber berhasil diperiksa; %d hasil berikut berasal dari platform yang tersedia. Platform yang gagal: %s.',
                count($outcome->results),
                implode(', ', $outcome->platformsFailed),
            );
        }

        if ($outcome->state === SearchState::PLATFORM_UNAVAILABLE) {
            return 'Platform sumber sedang tidak tersedia. Coba lagi setelah sumber marketplace aktif, atau gunakan tautan platform eksternal yang tersedia.';
        }

        $product = mb_substr(trim($intent->product), 0, 160, 'UTF-8');

        return sprintf(
            'Produk%s dikenali, tetapi belum memiliki listing aktif yang dapat dibandingkan. Coba kondisi atau lokasi lain, atau buka platform eksternal yang tersedia.',
            $product === '' ? '' : ' "'.$product.'"',
        );
    }

    public function unknownProduct(ParsedIntent $intent): string
    {
        $product = mb_substr(trim($intent->product), 0, 160, 'UTF-8');

        return sprintf(
            'Produk%s belum dikenali pada katalog aktif. Periksa nama model atau varian, lalu coba lagi; Anda juga dapat meneruskan pencarian ke platform eksternal yang tersedia.',
            $product === '' ? '' : ' "'.$product.'"',
        );
    }
}
