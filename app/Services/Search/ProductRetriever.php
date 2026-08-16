<?php

namespace App\Services\Search;

use App\Helpers\Normalizer;
use App\Helpers\Similarity;
use App\Models\Product;
use App\Models\ProductAlias;

final class ProductRetriever
{
    public function __construct(
        private readonly ProductAttributeMatcher $attributeMatcher,
    ) {}

    /**
     * @param  list<float|int>  $queryVector
     * @param  array<string, mixed>  $intent
     * @return list<array{product: Product, similarity: float|null, matched_via: string}>
     */
    public function retrieve(
        array $queryVector,
        string $model,
        int $dimensions,
        array $intent,
    ): array {
        $queryVector = Similarity::validatedVector(
            $queryVector,
            $dimensions,
        );

        if ($queryVector === null || trim($model) === '') {
            return [];
        }

        $candidates = [];

        Product::query()
            ->active()
            ->whereHas('category', fn ($query) => $query
                ->where('is_active', true))
            ->with(['category', 'aliases'])
            ->lazyById(100)
            ->each(function (Product $product) use (
                &$candidates,
                $queryVector,
                $model,
                $dimensions,
            ): void {
                $best = null;
                $matchedVia = 'product';

                if ($product->hasCurrentEmbedding($model, $dimensions)) {
                    $best = Similarity::cosine(
                        $queryVector,
                        $product->embedding,
                    );
                }

                foreach ($product->aliases as $alias) {
                    if (! $alias instanceof ProductAlias
                        || ! $alias->hasCurrentEmbedding($model, $dimensions)) {
                        continue;
                    }

                    $score = Similarity::cosine(
                        $queryVector,
                        $alias->embedding,
                    );

                    if ($score !== null && ($best === null || $score > $best)) {
                        $best = $score;
                        $matchedVia = 'alias';
                    }
                }

                if ($best !== null) {
                    $candidates[] = [
                        'product' => $product,
                        'similarity' => $best,
                        'matched_via' => $matchedVia,
                    ];
                }
            });

        usort(
            $candidates,
            static function (array $left, array $right): int {
                $similarity = $right['similarity'] <=> $left['similarity'];

                return $similarity !== 0
                    ? $similarity
                    : $left['product']->getKey() <=> $right['product']->getKey();
            },
        );

        $candidateK = min(
            100,
            max(1, (int) config(
                'neomatra.search.product_candidate_k',
                20,
            )),
        );

        $candidates = array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => $this->attributeMatcher->matches(
                $candidate['product'],
                $intent,
            ),
        ));

        return array_slice($candidates, 0, $candidateK);
    }

    /**
     * Resolve explicit canonical names, models, aliases, and critical variants
     * without depending on an external provider. This is identity matching,
     * not a numeric similarity cutoff.
     *
     * @param  array<string, mixed>  $intent
     * @return list<array{product: Product, similarity: null, matched_via: string}>
     */
    public function retrieveDeterministically(array $intent): array
    {
        $query = $this->compact((string) ($intent['query'] ?? ''));
        $requestedModel = $this->compact((string) ($intent['model'] ?? ''));
        $requestedSku = $this->compact(
            (string) ($intent['sku_or_part_number'] ?? ''),
        );
        $matches = [];

        Product::query()
            ->active()
            ->whereHas('category', fn ($builder) => $builder->where('is_active', true))
            ->with(['category', 'aliases'])
            ->lazyById(100)
            ->each(function (Product $product) use (
                &$matches,
                $intent,
                $query,
                $requestedModel,
                $requestedSku,
            ): void {
                if (! $this->attributeMatcher->matches($product, $intent)) {
                    return;
                }

                $identity = $this->compact(implode(' ', array_filter([
                    $product->name,
                    $product->brand,
                    $product->model,
                    $product->variant,
                    $product->sku_or_part_number,
                ])));
                $model = $this->compact((string) $product->model);
                $sku = $this->compact((string) $product->sku_or_part_number);
                $rank = 0;
                $matchedVia = 'deterministic_product';

                if ($requestedSku !== '' && $requestedSku === $sku) {
                    $rank = 500 + strlen($sku);
                } elseif ($requestedModel !== ''
                    && ($requestedModel === $model
                        || str_contains($identity, $requestedModel))) {
                    $rank = 400 + strlen($requestedModel);
                } elseif ($model !== '' && strlen($model) >= 3
                    && str_contains($query, $model)) {
                    $rank = 300 + strlen($model);
                }

                foreach ($product->aliases as $alias) {
                    if (! $alias instanceof ProductAlias) {
                        continue;
                    }

                    $aliasText = $this->compact((string) $alias->alias);

                    if ($aliasText === '' || strlen($aliasText) < 3) {
                        continue;
                    }

                    if ($query === $aliasText || str_contains($query, $aliasText)) {
                        $aliasRank = 350 + strlen($aliasText);

                        if ($aliasRank > $rank) {
                            $rank = $aliasRank;
                            $matchedVia = 'deterministic_alias';
                        }
                    }
                }

                if ($rank === 0) {
                    return;
                }

                $matches[] = [
                    'product' => $product,
                    'similarity' => null,
                    'matched_via' => $matchedVia,
                    '_identity_rank' => $rank,
                ];
            });

        usort($matches, static function (array $left, array $right): int {
            $rank = $right['_identity_rank'] <=> $left['_identity_rank'];

            return $rank !== 0
                ? $rank
                : $left['product']->getKey() <=> $right['product']->getKey();
        });

        $candidateK = min(100, max(1, (int) config(
            'neomatra.search.product_candidate_k',
            20,
        )));

        return array_map(static function (array $match): array {
            unset($match['_identity_rank']);

            return $match;
        }, array_slice($matches, 0, $candidateK));
    }

    /**
     * @param  list<array{product: Product, similarity: float|null, matched_via: string}>  $deterministic
     * @param  list<array{product: Product, similarity: float|null, matched_via: string}>  $semantic
     * @return list<array{product: Product, similarity: float|null, matched_via: string}>
     */
    public function merge(array $deterministic, array $semantic): array
    {
        $semanticByProduct = [];

        foreach ($semantic as $candidate) {
            $semanticByProduct[(int) $candidate['product']->getKey()] = $candidate;
        }

        $merged = [];

        foreach ($deterministic as $candidate) {
            $productId = (int) $candidate['product']->getKey();

            if (isset($semanticByProduct[$productId])) {
                $candidate['similarity'] = $semanticByProduct[$productId]['similarity'];
                $candidate['matched_via'] .= '+'.$semanticByProduct[$productId]['matched_via'];
                unset($semanticByProduct[$productId]);
            }

            $merged[] = $candidate;
        }

        return [...$merged, ...array_values($semanticByProduct)];
    }

    private function compact(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Normalizer::text($value))
            ?? '';
    }
}
