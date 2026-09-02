<?php

namespace App\Services\Search;

use App\Data\AI\ParsedIntent;
use App\Data\Search\ListingSearchOutcome;
use App\Data\Search\SearchState;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class ListingSearchService
{
    public function __construct(
        private readonly ListingRankingService $ranking,
    ) {}

    /**
     * Runs at most four database retrieval stages. Only optional filters are
     * relaxed and every relaxation is returned to the caller.
     *
     * @param  list<array{product: Product, similarity: float|null, matched_via: string}>  $candidates
     */
    public function searchProgressively(
        array $candidates,
        ParsedIntent $intent,
    ): ListingSearchOutcome {
        $requested = $this->filters($intent);
        $platformsFailed = Platform::query()
            ->active()
            ->where('status', Platform::STATUS_ERROR)
            ->orderBy('name')
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();
        $platforms = Platform::query()
            ->publiclyAvailable()
            ->where(function (Builder $query): void {
                $query->where('status', '!=', Platform::STATUS_ERROR)
                    ->orWhereHas('listings', static fn (Builder $listings): Builder => $listings
                        ->available()
                        ->where('raw_payload->connector', 'manual-import'));
            })
            ->orderBy('name')
            ->pluck('slug')
            ->map(static fn (mixed $slug): string => (string) $slug)
            ->all();

        if ($platforms === []) {
            return new ListingSearchOutcome(
                SearchState::PLATFORM_UNAVAILABLE,
                [],
                $requested,
                $requested,
                [],
                0,
                [],
                $platformsFailed,
            );
        }

        $attempts = [$intent];
        $values = $intent->toArray();
        $relaxed = [];
        $relaxationsByAttempt = [];

        $present = array_values(array_filter([
            $values['platforms'] !== [] ? 'platforms' : null,
            $values['locations'] !== [] ? 'location' : null,
            $values['budget_min'] !== null || $values['budget_max'] !== null
                ? 'budget'
                : null,
        ]));
        $groups = count($present) <= 3
            ? array_map(static fn (string $filter): array => [$filter], $present)
            : [[$present[0]], [$present[1]], array_slice($present, 2)];

        foreach ($groups as $group) {
            foreach ($group as $filter) {
                match ($filter) {
                    'platforms' => $values['platforms'] = [],
                    'location' => $values['locations'] = [],
                    'budget' => [
                        $values['budget_min'] = null,
                        $values['budget_max'] = null,
                    ],
                };
                $relaxed[] = $filter;
            }

            $next = ParsedIntent::fromArray($values);

            if ($next !== null && count($attempts) < 4) {
                $attempts[] = $next;
                $relaxationsByAttempt[] = array_values($relaxed);
            }
        }

        $appliedRelaxations = [];
        $attemptCount = 0;

        foreach ($attempts as $index => $attempt) {
            $attemptCount++;
            $results = $this->search(
                $candidates,
                $attempt,
                $intent->locations,
            );

            if ($results === []) {
                continue;
            }

            $appliedRelaxations = $index === 0
                ? []
                : ($relaxationsByAttempt[$index - 1] ?? []);
            $closestPrice = null;
            $budgetDifference = null;

            if (in_array('budget', $appliedRelaxations, true)
                && $intent->budgetMax !== null) {
                usort($results, static function (array $left, array $right) use ($intent): int {
                    $leftDifference = max(
                        0,
                        (int) $left['item_price'] - $intent->budgetMax,
                    );
                    $rightDifference = max(
                        0,
                        (int) $right['item_price'] - $intent->budgetMax,
                    );
                    $difference = $leftDifference <=> $rightDifference;

                    return $difference !== 0
                        ? $difference
                        : (int) $left['item_price'] <=> (int) $right['item_price'];
                });
                $closestPrice = (int) $results[0]['item_price'];
                $budgetDifference = max(0, $closestPrice - $intent->budgetMax);
            }

            $state = $index === 0
                ? SearchState::MATCHED
                : SearchState::FILTER_TOO_STRICT;

            if ($platformsFailed !== []) {
                $state = SearchState::PARTIAL_RESULT;
            }

            return new ListingSearchOutcome(
                $state,
                $results,
                $requested,
                $this->filters($attempt),
                $appliedRelaxations,
                $attemptCount,
                $platforms,
                $platformsFailed,
                $closestPrice,
                $budgetDifference,
            );
        }

        $last = $attempts[array_key_last($attempts)];

        return new ListingSearchOutcome(
            SearchState::NO_ACTIVE_LISTING,
            [],
            $requested,
            $this->filters($last),
            $relaxed,
            $attemptCount,
            $platforms,
            $platformsFailed,
        );
    }

    /**
     * @param  list<array{product: Product, similarity: float|null, matched_via: string}>  $candidates
     * @return list<array<string, mixed>>
     */
    public function search(
        array $candidates,
        ParsedIntent $intent,
        ?array $rankingLocations = null,
    ): array {
        if ($candidates === []) {
            return [];
        }

        $scores = collect($candidates)->mapWithKeys(
            static fn (array $candidate, int $index): array => [
                (int) $candidate['product']->getKey() => [
                    'similarity' => is_numeric($candidate['similarity'])
                        ? (float) $candidate['similarity']
                        : null,
                    'matched_via' => (string) $candidate['matched_via'],
                    'candidate_order' => $index,
                ],
            ],
        );
        $productIds = $scores->keys()->all();
        $currency = strtoupper((string) config('neomatra.currency', 'IDR'));
        $query = Listing::query()
            ->available()
            ->whereIn('product_id', $productIds)
            ->where('currency', $currency)
            ->whereHas('platform', static function (Builder $query) use ($intent): Builder {
                $query->publiclyAvailable();

                if ($intent->platforms !== []) {
                    $query->whereIn('slug', array_map(
                        static fn (string $platform): string => Str::slug($platform),
                        $intent->platforms,
                    ));
                }

                return $query;
            })
            ->with(['platform', 'product.category']);
        $this->whereSourceAvailable($query);

        if ($intent->conditions !== []) {
            $query->whereIn('condition', $intent->conditions);
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
        $minimumPrice = $listings->min('price');
        $maximumPrice = $listings->max('price');
        $results = [];

        foreach ($listings as $listing) {
            $candidate = $scores->get((int) $listing->product_id);

            if (! is_array($candidate)) {
                continue;
            }

            $ranking = $this->ranking->calculate(
                $listing,
                (float) ($candidate['similarity'] ?? 0.0),
                $rankingLocations ?? $intent->locations,
                is_numeric($minimumPrice) ? (int) $minimumPrice : null,
                is_numeric($maximumPrice) ? (int) $maximumPrice : null,
            );

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
                'condition_label' => __('ui.messages.search.condition_seller'),
                'seller_name' => $listing->seller_name,
                'location' => $listing->location,
                'currency' => (string) $listing->currency,
                'item_price' => (int) $listing->price,
                'ranking' => $ranking,
                'similarity' => is_float($candidate['similarity'])
                    ? $candidate['similarity']
                    : null,
                'matched_via' => (string) $candidate['matched_via'],
                '_candidate_order' => (int) $candidate['candidate_order'],
                'source_published_at' => $listing->source_published_at?->toIso8601String(),
                'last_synced_at' => $listing->last_synced_at?->toIso8601String(),
            ];
        }

        usort($results, static function (array $left, array $right): int {
            if (str_starts_with($left['matched_via'], 'related_tier_')
                && str_starts_with($right['matched_via'], 'related_tier_')) {
                $candidateOrder = $left['_candidate_order']
                    <=> $right['_candidate_order'];

                if ($candidateOrder !== 0) {
                    return $candidateOrder;
                }
            }

            $rank = $right['ranking']['rank_score']
                <=> $left['ranking']['rank_score'];

            if ($rank !== 0) {
                return $rank;
            }

            $cost = $left['item_price'] <=> $right['item_price'];

            return $cost !== 0 ? $cost : $left['id'] <=> $right['id'];
        });

        $topK = min(
            20,
            max(1, (int) config('neomatra.search.result_top_k', 5)),
        );

        return array_map(static function (array $result): array {
            unset($result['_candidate_order']);

            return $result;
        }, array_slice($results, 0, $topK));
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

    private function whereSourceAvailable(Builder $query): void
    {
        $query->where(static function (Builder $sourceQuery): void {
            $sourceQuery
                ->where('raw_payload->connector', 'manual-import')
                ->orWhereHas('platform', static fn (Builder $platform): Builder => $platform
                    ->where('status', '!=', Platform::STATUS_ERROR));
        });
    }

    /** @return array<string, mixed> */
    private function filters(ParsedIntent $intent): array
    {
        return [
            'conditions' => $intent->conditions,
            'platforms' => $intent->platforms,
            'locations' => $intent->locations,
            'budget_min' => $intent->budgetMin,
            'budget_max' => $intent->budgetMax,
        ];
    }
}
