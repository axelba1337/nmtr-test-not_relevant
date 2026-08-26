<?php

namespace App\Data\Search;

final readonly class ListingSearchOutcome
{
    /**
     * @param  list<array<string, mixed>>  $results
     * @param  array<string, mixed>  $requestedFilters
     * @param  array<string, mixed>  $appliedFilters
     * @param  list<string>  $relaxedFilters
     * @param  list<string>  $platformsSearched
     * @param  list<string>  $platformsFailed
     */
    public function __construct(
        public string $state,
        public array $results,
        public array $requestedFilters,
        public array $appliedFilters,
        public array $relaxedFilters,
        public int $attemptCount,
        public array $platformsSearched,
        public array $platformsFailed = [],
        public ?int $closestPrice = null,
        public ?int $budgetDifference = null,
        public ?string $alternativeStrategy = null,
        public ?int $requestedProductId = null,
        public array $resultProductIds = [],
        public ?string $fallbackStage = null,
        public array $alternativeDetails = [],
    ) {}
}
