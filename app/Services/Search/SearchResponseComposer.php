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
                ($detail['different_brand'] ?? false) === true => __('ui.search_response.different_brand'),
                ($detail['different_family'] ?? false) === true => __('ui.search_response.different_family'),
                ($detail['different_generation'] ?? false) === true => __('ui.search_response.different_generation'),
                default => __('ui.search_response.closest_family'),
            };

            return __('ui.search_response.alternative', [
                'count' => count($outcome->results),
                'product' => (string) $first['product']['name'],
                'cost' => number_format((int) $first['costs']['total'], 0, ',', '.'),
                'detail' => $difference,
            ]);
        }

        if ($outcome->state === SearchState::FILTER_TOO_STRICT
            && $outcome->results !== []) {
            $first = $outcome->results[0];
            $relaxed = implode(', ', array_map(
                static fn (string $filter): string => match ($filter) {
                    'seller_type' => __('ui.search_response.filters.seller_type'),
                    'location' => __('ui.search_response.filters.location'),
                    'platforms' => __('ui.search_response.filters.platforms'),
                    'budget' => __('ui.search_response.filters.budget'),
                    default => $filter,
                },
                $outcome->relaxedFilters,
            ));
            $budgetNote = $outcome->budgetDifference !== null
                && $outcome->budgetDifference > 0
                ? __('ui.search_response.budget_note', [
                    'amount' => number_format($outcome->budgetDifference, 0, ',', '.'),
                ])
                : '';

            return __('ui.search_response.strict', [
                'count' => count($outcome->results),
                'filters' => $relaxed === '' ? __('ui.search_response.optional_filters') : $relaxed,
                'title' => (string) $first['title'],
                'cost' => number_format((int) $first['costs']['total'], 0, ',', '.'),
                'note' => $budgetNote,
            ]);
        }

        if ($outcome->state === SearchState::PARTIAL_RESULT
            && $outcome->results !== []) {
            return __('ui.search_response.partial', [
                'count' => count($outcome->results),
                'platforms' => implode(', ', $outcome->platformsFailed),
            ]);
        }

        if ($outcome->state === SearchState::PLATFORM_UNAVAILABLE) {
            return __('ui.search_response.platform_unavailable');
        }

        $product = mb_substr(trim($intent->product), 0, 160, 'UTF-8');

        return __('ui.search_response.no_listing', [
            'product' => $product === '' ? '' : ' "'.$product.'"',
        ]);
    }

    public function unknownProduct(ParsedIntent $intent): string
    {
        $product = mb_substr(trim($intent->product), 0, 160, 'UTF-8');

        return __('ui.search_response.unknown_product', [
            'product' => $product === '' ? '' : ' "'.$product.'"',
        ]);
    }
}
