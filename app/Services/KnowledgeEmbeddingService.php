<?php

namespace App\Services;

use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\EmbeddingPurpose;
use App\Models\KnowledgeItem;

final class KnowledgeEmbeddingService
{
    public const LOCK_KEY = 'neomatra:embedding-rebuild';

    public const LOCK_SECONDS = 1800;

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    private const SAFE_FAILURE_MESSAGE = 'Embedding baru gagal dibuat.';

    public function __construct(
        private readonly EmbeddingClient $embedding,
    ) {}

    public function rebuild(
        KnowledgeItem $item,
        bool $force = false,
    ): string {
        $item = $item->fresh();

        if ($item === null) {
            return self::STATUS_SKIPPED;
        }

        $model = $this->embedding->model();
        $dimensions = $this->embedding->dimensions();

        if (! $force && $item->hasCurrentEmbedding($model, $dimensions)) {
            return self::STATUS_SKIPPED;
        }

        $expectedHash = $item->expectedEmbeddingHash($model, $dimensions);
        $result = $this->embedding->embed(
            $item->embeddingText(),
            EmbeddingPurpose::Knowledge,
            (string) $item->title,
        );

        if (! $result->succeeded()) {
            $this->recordFailure($item, $expectedHash, $model, $dimensions);

            return self::STATUS_FAILED;
        }

        $currentItem = $item->fresh();

        if ($currentItem === null
            || ! hash_equals(
                $expectedHash,
                $currentItem->expectedEmbeddingHash($model, $dimensions),
            )) {
            return self::STATUS_SKIPPED;
        }

        $currentItem->forceFill([
            'embedding' => $result->vector,
            'embedding_hash' => $expectedHash,
            'embedding_model' => $result->model,
            'embedding_dimensions' => $result->dimensions,
            'embedded_at' => now(),
            'embedding_error' => null,
        ])->save();

        return self::STATUS_PROCESSED;
    }

    /**
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function rebuildActive(
        bool $force = true,
        int $chunkSize = 100,
    ): array {
        $counts = [
            self::STATUS_PROCESSED => 0,
            self::STATUS_SKIPPED => 0,
            self::STATUS_FAILED => 0,
        ];
        $chunkSize = max(1, $chunkSize);

        $items = KnowledgeItem::query()
            ->active()
            ->orderBy('id')
            ->lazyById($chunkSize);

        foreach ($items as $item) {
            $status = $this->rebuild($item, $force);
            $counts[$status]++;
        }

        return $counts;
    }

    private function recordFailure(
        KnowledgeItem $item,
        string $expectedHash,
        string $model,
        int $dimensions,
    ): void {
        $currentItem = $item->fresh();

        if ($currentItem === null
            || ! hash_equals(
                $expectedHash,
                $currentItem->expectedEmbeddingHash($model, $dimensions),
            )) {
            return;
        }

        $currentItem->forceFill([
            'embedding_error' => self::SAFE_FAILURE_MESSAGE,
        ])->save();
    }
}
