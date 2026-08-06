<?php

namespace App\Services\Search;

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
     * @return list<array{product: Product, similarity: float, matched_via: string}>
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
}
