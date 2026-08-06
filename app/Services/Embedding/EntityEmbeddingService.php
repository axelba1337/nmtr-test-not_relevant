<?php

namespace App\Services\Embedding;

use App\Contracts\AI\EmbeddingClient;
use App\Data\AI\EmbeddingPurpose;
use App\Models\Product;
use App\Models\ProductAlias;

final class EntityEmbeddingService
{
    public const PROCESSED = 'processed';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    private const SAFE_FAILURE = 'Embedding baru gagal dibuat.';

    public function __construct(
        private readonly EmbeddingClient $embedding,
    ) {}

    public function rebuildProduct(Product $product, bool $force = false): string
    {
        $product = $product->fresh();

        if ($product === null) {
            return self::SKIPPED;
        }

        return $this->rebuild(
            $product,
            EmbeddingPurpose::Product,
            $product->embeddingText(),
            (string) $product->name,
            $force,
        );
    }

    public function rebuildAlias(
        ProductAlias $alias,
        bool $force = false,
    ): string {
        $alias = $alias->fresh(['product']);

        if ($alias === null || $alias->product === null) {
            return self::SKIPPED;
        }

        return $this->rebuild(
            $alias,
            EmbeddingPurpose::Alias,
            $alias->embeddingText(),
            (string) $alias->alias,
            $force,
        );
    }

    /** @return array{processed: int, skipped: int, failed: int} */
    public function rebuildActiveProducts(
        bool $force = true,
        int $chunkSize = 100,
    ): array {
        $counts = $this->emptyCounts();

        Product::query()
            ->active()
            ->with('aliases.product')
            ->lazyById(max(1, $chunkSize))
            ->each(function (Product $product) use (&$counts, $force): void {
                $counts[$this->rebuildProduct($product, $force)]++;

                foreach ($product->aliases as $alias) {
                    $counts[$this->rebuildAlias($alias, $force)]++;
                }
            });

        return $counts;
    }

    private function rebuild(
        Product|ProductAlias $entity,
        EmbeddingPurpose $purpose,
        string $text,
        string $title,
        bool $force,
    ): string {
        $model = $this->embedding->model();
        $dimensions = $this->embedding->dimensions();

        if (! $force && $entity->hasCurrentEmbedding($model, $dimensions)) {
            return self::SKIPPED;
        }

        $expectedHash = $entity->expectedEmbeddingHash($model, $dimensions);
        $result = $this->embedding->embed($text, $purpose, $title);
        $current = $entity instanceof ProductAlias
            ? $entity->fresh(['product'])
            : $entity->fresh();

        if ($current === null
            || ! hash_equals(
                $expectedHash,
                $current->expectedEmbeddingHash($model, $dimensions),
            )) {
            return self::SKIPPED;
        }

        if (! $result->succeeded()) {
            $current->forceFill([
                'embedding_error' => self::SAFE_FAILURE,
            ])->save();

            return self::FAILED;
        }

        $current->forceFill([
            'embedding' => $result->vector,
            'embedding_hash' => $expectedHash,
            'embedding_model' => $result->model,
            'embedding_dimensions' => $result->dimensions,
            'embedded_at' => now(),
            'embedding_error' => null,
        ])->save();

        return self::PROCESSED;
    }

    /** @return array{processed: int, skipped: int, failed: int} */
    private function emptyCounts(): array
    {
        return [
            self::PROCESSED => 0,
            self::SKIPPED => 0,
            self::FAILED => 0,
        ];
    }
}
