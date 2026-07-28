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
            (int) config('chatbot.embedding_dimensions', 768),
        );
        $queryVector = Similarity::validatedVector(
            $queryVector,
            $dimensions,
        );

        if ($queryVector === null) {
            return [];
        }

        $embeddingModel = (string) config(
            'chatbot.models.embed',
            'gemini-embedding-2',
        );
        $matches = [];

        $items = KnowledgeItem::query()
            ->select([
                'id',
                'title',
                'content',
                'embedding',
                'embedding_hash',
                'embedding_model',
                'embedded_at',
            ])
            ->active()
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
            max(1, (int) config('chatbot.top_k', 3)),
        );

        return array_slice($matches, 0, $topK);
    }
}
