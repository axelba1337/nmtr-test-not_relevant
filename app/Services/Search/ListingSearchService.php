<?php

namespace App\Services\Search;

use App\Data\AI\ParsedIntent;
use App\Models\Listing;
use App\Models\Product;
use App\Services\Neomatra\MarketPriceCalculator;
use App\Services\Neomatra\ResellerMarginCalculator;
use App\Services\Neomatra\RiskSignalService;
use App\Services\Neomatra\TotalCostCalculator;
use App\Services\Neomatra\UndervaluedCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListingSearchService
{
    public function __construct(
        private readonly MarketPriceCalculator $marketPrice,
        private readonly TotalCostCalculator $totalCost,
        private readonly UndervaluedCalculator $undervalued,
        private readonly ResellerMarginCalculator $resellerMargin,
        private readonly RiskSignalService $risk,
        private readonly ListingRankingService $ranking,
    ) {}

    /**
     * @param  list<array{product: Product, similarity: float, matched_via: string}>  $candidates
     * @return list<array<string, mixed>>
     */
    public function search(array $candidates, ParsedIntent $intent): array
    {
        if ($candidates === []) {
            return [];
        }

        $scores = collect($candidates)->mapWithKeys(
            static fn (array $candidate): array => [
                (int) $candidate['product']->getKey() => [
                    'similarity' => (float) $candidate['similarity'],
                    'matched_via' => (string) $candidate['matched_via'],
                ],
            ],
        );
        $productIds = $scores->keys()->all();
        $currency = strtoupper((string) config('neomatra.currency', 'IDR'));
        $query = Listing::query()
            ->available()
            ->whereIn('product_id', $productIds)
            ->where('currency', $currency)
            ->whereHas('platform', static fn (Builder $query): Builder => $query
                ->publiclyAvailable())
            ->with(['platform', 'product.category']);

        if ($intent->conditions !== []) {
            $query->whereIn('condition', $intent->conditions);
        }

        if ($intent->sellerTypes !== []) {
            $query->whereIn('seller_type', $intent->sellerTypes);
        }

        if ($intent->locations !== []) {
            $query->where(function (Builder $locationQuery) use ($intent): void {
                foreach ($intent->locations as $location) {
                    $locationQuery->orWhere('location', 'like', '%'.$this->escapeLike(
                        $location,
                    ).'%');
                }
            });
        }

        if ($intent->budgetMin !== null) {
            $query->where('price', '>=', $intent->budgetMin);
        }

        if ($intent->budgetMax !== null) {
            $query->where('price', '<=', $intent->budgetMax);
        }

        $listings = $query->get();
        $comparables = Listing::query()
            ->available()
            ->whereIn('product_id', $productIds)
            ->where('currency', $currency)
            ->whereHas('platform', static fn (Builder $query): Builder => $query
                ->publiclyAvailable())
            ->get()
            ->groupBy(static fn (Listing $listing): string => implode('|', [
                $listing->product_id,
                $listing->condition,
                strtoupper((string) $listing->currency),
            ]));
        $results = [];

        foreach ($listings as $listing) {
            $candidate = $scores->get((int) $listing->product_id);

            if (! is_array($candidate)) {
                continue;
            }

            $totalCost = $this->totalCost->calculate(
                (int) $listing->price,
                $listing->shipping_cost,
                $listing->service_fee,
                $listing->inspection_cost,
                $listing->repair_estimate,
            );

            if ($intent->budgetMax !== null && $totalCost > $intent->budgetMax) {
                continue;
            }

            $comparisonKey = implode('|', [
                $listing->product_id,
                $listing->condition,
                strtoupper((string) $listing->currency),
            ]);
            $comparisonRows = $this->uniqueComparables(
                $comparables->get($comparisonKey, collect()),
            );
            $minimumComparables = max(
                1,
                (int) config('neomatra.pricing.minimum_comparables', 2),
            );
            $marketPrice = $comparisonRows->count() >= $minimumComparables
                ? $this->marketPrice->calculate($comparisonRows)
                : null;
            $undervalued = $marketPrice === null
                ? null
                : $this->undervalued->calculate($marketPrice, $totalCost);
            $risk = $undervalued === null
                ? [
                    'signal' => 'insufficient_market_data',
                    'label' => 'Data pembanding belum cukup',
                    'requires_review' => true,
                    'disclaimer' => 'Kondisi, seller, harga, dan margin merupakan informasi atau estimasi, bukan jaminan keamanan maupun keuntungan.',
                ]
                : $this->risk->fromUndervaluedPercentage(
                    $undervalued['percentage'],
                );
            $ranking = $this->ranking->calculate(
                $listing,
                $candidate['similarity'],
                $undervalued['percentage'] ?? null,
            );
            $margin = $intent->mode === 'reseller' && $marketPrice !== null
                ? $this->resellerMargin->calculate(
                    $marketPrice,
                    $totalCost,
                )
                : null;
            $missingCosts = $this->missingCostFields($listing);

            $results[] = [
                'id' => (int) $listing->id,
                'product_id' => (int) $listing->product_id,
                'product' => [
                    'name' => (string) $listing->product?->name,
                    'brand' => $listing->product?->brand,
                    'model' => $listing->product?->model,
                    'variant' => $listing->product?->variant,
                    'category' => $listing->product?->category?->name,
                ],
                'title' => (string) $listing->title,
                'image_url' => $this->safeUrl($listing->image_url),
                'source_url' => $this->safeUrl($listing->product_url),
                'platform' => (string) $listing->platform?->name,
                'condition' => (string) $listing->condition,
                'condition_label' => 'Berdasarkan keterangan seller',
                'seller_name' => $listing->seller_name,
                'seller_type' => (string) $listing->seller_type,
                'location' => $listing->location,
                'currency' => (string) $listing->currency,
                'item_price' => (int) $listing->price,
                'costs' => [
                    'shipping' => $listing->shipping_cost,
                    'service_fee' => $listing->service_fee,
                    'inspection' => $listing->inspection_cost,
                    'repair_estimate' => $listing->repair_estimate,
                    'total' => $totalCost,
                    'complete' => $missingCosts === [],
                    'missing_fields' => $missingCosts,
                ],
                'market' => [
                    'price' => $marketPrice,
                    'comparable_count' => $comparisonRows->count(),
                    'minimum_comparables' => $minimumComparables,
                ],
                'undervalued' => $undervalued,
                'reseller' => $margin === null ? null : [
                    'estimated_resale_price' => $marketPrice,
                    'selling_fee' => 0,
                    'storage_cost' => 0,
                    ...$margin,
                ],
                'risk' => $risk,
                'ranking' => $ranking,
                'similarity' => (float) $candidate['similarity'],
                'matched_via' => (string) $candidate['matched_via'],
                'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            ];
        }

        usort($results, static function (array $left, array $right): int {
            $rank = $right['ranking']['rank_score']
                <=> $left['ranking']['rank_score'];

            if ($rank !== 0) {
                return $rank;
            }

            $cost = $left['costs']['total'] <=> $right['costs']['total'];

            return $cost !== 0 ? $cost : $left['id'] <=> $right['id'];
        });

        $topK = min(
            20,
            max(1, (int) config('neomatra.search.result_top_k', 5)),
        );

        return array_slice($results, 0, $topK);
    }

    private function uniqueComparables(Collection $rows): Collection
    {
        return $rows->unique(static function (Listing $listing): string {
            return filled($listing->source_fingerprint)
                ? (string) $listing->source_fingerprint
                : 'listing:'.$listing->getKey();
        })->values();
    }

    /** @return list<string> */
    private function missingCostFields(Listing $listing): array
    {
        $missing = [];

        foreach ([
            'shipping_cost',
            'service_fee',
            'inspection_cost',
            'repair_estimate',
        ] as $field) {
            if ($listing->getAttribute($field) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function safeUrl(?string $url): ?string
    {
        if (! is_string($url)
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || ! in_array(
                strtolower((string) parse_url($url, PHP_URL_SCHEME)),
                ['http', 'https'],
                true,
            )) {
            return null;
        }

        return $url;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value,
        );
    }
}
