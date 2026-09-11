<?php

namespace App\Models;

use App\Helpers\Normalizer;
use App\Helpers\Similarity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Product extends Model
{
    public const EMBEDDING_FORMAT_VERSION = 'product-v2';

    public const EMBEDDING_READY = 'ready';

    public const EMBEDDING_NEEDS_REBUILD = 'needs_rebuild';

    public const EMBEDDING_FAILED = 'failed';

    protected $fillable = [
        'category_id',
        'name',
        'brand',
        'model',
        'variant',
        'sku_or_part_number',
        'normalized_key',
        'attributes',
        'spec_source_name',
        'spec_source_url',
        'spec_source_checked_at',
        'embedding',
        'embedding_hash',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
        'embedding_error',
        'is_active',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            if (! filled($product->normalized_key)
                || $product->isDirty([
                    'name',
                    'brand',
                    'model',
                    'variant',
                    'sku_or_part_number',
                ])) {
                $product->normalized_key = self::makeNormalizedKey(
                    (string) $product->name,
                    $product->brand,
                    $product->model,
                    $product->variant,
                    $product->sku_or_part_number,
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'spec_source_checked_at' => 'datetime',
            'embedding' => 'array',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ProductAlias::class)->chaperone();
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function embeddingText(): string
    {
        $lines = [
            'Produk: '.trim((string) $this->name),
            'Kategori: '.trim((string) ($this->category?->name ?: '-')),
            'Merek: '.trim((string) ($this->brand ?: '-')),
            'Model: '.trim((string) ($this->model ?: '-')),
            'Varian: '.trim((string) ($this->variant ?: '-')),
            'SKU/Part Number: '.trim(
                (string) ($this->sku_or_part_number ?: '-'),
            ),
        ];

        $attributes = $this->getAttribute('attributes');

        if (is_array($attributes) && $attributes !== []) {
            $lines[] = 'Atribut: '.json_encode(
                self::sortRecursively($attributes),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        return implode("\n", $lines);
    }

    public function expectedEmbeddingHash(
        string $model,
        int $dimensions,
    ): string {
        return self::makeEmbeddingHash(
            $model,
            $dimensions,
            $this->embeddingText(),
        );
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

    public function embeddingStatus(string $model, int $dimensions): string
    {
        if ($this->hasCurrentEmbedding($model, $dimensions)) {
            return self::EMBEDDING_READY;
        }

        return filled($this->embedding_error)
            ? self::EMBEDDING_FAILED
            : self::EMBEDDING_NEEDS_REBUILD;
    }

    public static function makeEmbeddingHash(
        string $model,
        int $dimensions,
        string $text,
    ): string {
        $values = [
            self::EMBEDDING_FORMAT_VERSION,
            $model,
            (string) $dimensions,
            $text,
        ];

        return hash('sha256', implode('|', array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            $values,
        )));
    }

    public static function makeNormalizedKey(
        string $name,
        ?string $brand = null,
        ?string $model = null,
        ?string $variant = null,
        ?string $skuOrPartNumber = null,
    ): string {
        return implode('|', array_map(
            static fn (?string $value): string => Normalizer::text(
                (string) $value,
            ),
            [$brand, $model, $variant, $skuOrPartNumber, $name],
        ));
    }

    private static function sortRecursively(array $values): array
    {
        ksort($values);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::sortRecursively($value);
            }
        }

        return $values;
    }
}
