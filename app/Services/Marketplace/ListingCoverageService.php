<?php

namespace App\Services\Marketplace;

use App\Models\Listing;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class ListingCoverageService
{
    /** @return array<string, int> */
    public function metrics(): array
    {
        return [
            'active' => Listing::query()->available()->count(),
            'matched' => Listing::query()
                ->available()
                ->whereNotNull('product_id')
                ->count(),
            'unmatched' => Listing::query()
                ->available()
                ->whereNull('product_id')
                ->count(),
            'without_active' => Product::query()
                ->active()
                ->whereDoesntHave(
                    'listings',
                    static fn (Builder $query): Builder => $query->available(),
                )
                ->count(),
            'needing' => $this->countNeeding($this->target()),
        ];
    }

    public function needingQuery(?int $minimum = null): Builder
    {
        $minimum ??= $this->target();

        return $this->applyNeedsCoverage(Product::query()->active(), $minimum)
            ->with('category:id,name')
            ->withCount([
                'listings as active_listings_count' => static fn (Builder $query): Builder => $query
                    ->available(),
            ])
            ->orderBy('active_listings_count')
            ->orderBy('name')
            ->orderBy('id');
    }

    /** @return Collection<int, Product> */
    public function discoveryTargets(int $target, int $limit): Collection
    {
        return $this->needingQuery(max(1, $target))
            ->limit(max(1, $limit))
            ->get();
    }

    public function countNeeding(?int $threshold = null): int
    {
        return $this->applyNeedsCoverage(
            Product::query()->active(),
            $threshold ?? $this->target(),
        )->count();
    }

    private function minimum(): int
    {
        return max(
            1,
            (int) config('listing-discovery.minimum_active_listings', 2),
        );
    }

    public function target(): int
    {
        return max(
            $this->minimum(),
            (int) config('listing-discovery.target_active_listings', 5),
        );
    }

    private function applyNeedsCoverage(Builder $query, int $minimum): Builder
    {
        return $query->has(
            'listings',
            '<',
            max(1, $minimum),
            'and',
            static fn (Builder $listingQuery): Builder => $listingQuery->available(),
        );
    }
}
