<?php

namespace App\Services;

use App\Helpers\Similarity;
use App\Models\KnowledgeItem;

final class KnowledgeRetriever
{
    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     content: string,
     *     similarity: float
     * }>
     */
    public function retrieve(array $queryVector): array
    {
        $dimensions = max(
            1,
            (int) config('ai.embedding.dimensions', 768),
        );
        $queryVector = Similarity::validatedVector(
            $queryVector,
            $dimensions,
        );

        if ($queryVector === null) {
            return [];
        }

        $embeddingModel = (string) config(
            'ai.embedding.model',
            'gemini-embedding-2',
        );
        $matches = [];

        $items = KnowledgeItem::query()
            ->with('category:id,name')
            ->select([
                'id',
                'title',
                'content',
                'category_id',
                'keywords',
                'source_name',
                'embedding',
                'embedding_hash',
                'embedding_model',
                'embedding_dimensions',
                'embedded_at',
            ])
            ->active()
            ->where(function ($query): void {
                $query->whereNull('category_id')
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery
                        ->where('is_active', true));
            })
            ->where('embedding_model', $embeddingModel)
            ->whereNotNull('embedded_at')
            ->whereNotNull('embedding')
            ->lazyById(100);

        foreach ($items as $item) {
            if (! $item->hasCurrentEmbedding(
                $embeddingModel,
                $dimensions,
            )) {
                continue;
            }

            $vector = Similarity::validatedVector(
                $item->embedding,
                $dimensions,
            );

            if ($vector === null) {
                continue;
            }

            $similarity = Similarity::cosine($queryVector, $vector);

            if ($similarity === null) {
                continue;
            }

            $matches[] = [
                'id' => (int) $item->id,
                'title' => (string) $item->title,
                'content' => (string) $item->content,
                'similarity' => $similarity,
            ];
        }

        usort(
            $matches,
            static function (array $left, array $right): int {
                $bySimilarity = $right['similarity']
                    <=> $left['similarity'];

                return $bySimilarity !== 0
                    ? $bySimilarity
                    : $left['id'] <=> $right['id'];
            },
        );

        $topK = min(
            10,
            max(1, (int) config(
                'neomatra.search.knowledge_top_k',
                3,
            )),
        );

        return array_slice($matches, 0, $topK);
    }
}
