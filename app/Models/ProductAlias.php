<?php

namespace App\Models;

use App\Helpers\Normalizer;
use App\Helpers\Similarity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProductAlias extends Model
{
    public const EMBEDDING_FORMAT_VERSION = 'product-alias-v2';

    protected $fillable = [
        'product_id',
        'alias',
        'normalized_alias',
        'embedding',
        'embedding_hash',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
        'embedding_error',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $alias): void {
            if (! filled($alias->normalized_alias)
                || $alias->isDirty('alias')) {
                $alias->normalized_alias = Normalizer::text(
                    (string) $alias->alias,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function embeddingText(): string
    {
        $product = $this->product;

        return implode("\n", [
            'Alias: '.trim((string) $this->alias),
            'Produk kanonik: '.trim((string) $product?->name),
            'Kategori: '.trim(
                (string) ($product?->category?->name ?: '-'),
            ),
            'Merek: '.trim((string) ($product?->brand ?: '-')),
            'Model: '.trim((string) ($product?->model ?: '-')),
            'Varian: '.trim((string) ($product?->variant ?: '-')),
            'SKU/Part Number: '.trim(
                (string) ($product?->sku_or_part_number ?: '-'),
            ),
        ]);
    }

    public function expectedEmbeddingHash(
        string $model,
        int $dimensions,
    ): string {
        $values = [
            self::EMBEDDING_FORMAT_VERSION,
            $model,
            (string) $dimensions,
            $this->embeddingText(),
        ];

        return hash('sha256', implode('|', array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            $values,
        )));
    }

    public function hasCurrentEmbedding(
        string $model,
        int $dimensions,
    ): bool {
        return $dimensions > 0
            && $this->embedding_model === $model
            && $this->embedding_dimensions === $dimensions
            && $this->embedded_at !== null
            && is_string($this->embedding_hash)
            && hash_equals(
                $this->expectedEmbeddingHash($model, $dimensions),
                $this->embedding_hash,
            )
            && is_array($this->embedding)
            && Similarity::validatedVector(
                $this->embedding,
                $dimensions,
            ) !== null;
    }
}
