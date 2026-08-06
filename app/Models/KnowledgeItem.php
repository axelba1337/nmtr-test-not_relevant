<?php

namespace App\Models;

use App\Helpers\Similarity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeItem extends Model
{
    public const EMBEDDING_FORMAT_VERSION = 'knowledge-v3';

    public const EMBEDDING_STATUS_READY = 'ready';

    public const EMBEDDING_STATUS_READY_WITH_WARNING = 'ready_with_warning';

    public const EMBEDDING_STATUS_FAILED = 'failed';

    public const EMBEDDING_STATUS_MISSING = 'missing';

    public const EMBEDDING_STATUS_STALE = 'stale';

    protected $fillable = [
        'title',
        'content',
        'category_id',
        'keywords',
        'source_name',
        'source_url',
        'embedding',
        'embedding_hash',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
        'embedding_error',
        'is_active',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'embedding' => 'array',
            'embedding_dimensions' => 'integer',
            'embedded_at' => 'datetime',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrderedForSuggestions(Builder $query): Builder
    {
        return $query
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function embeddingText(): string
    {
        return self::formatEmbeddingText(
            (string) $this->title,
            (string) $this->content,
            $this->category?->name,
            is_array($this->keywords) ? $this->keywords : [],
            $this->source_name,
        );
    }

    public function expectedEmbeddingHash(
        ?string $model = null,
        ?int $dimensions = null,
    ): string {
        $model ??= (string) config(
            'ai.embedding.model',
            'gemini-embedding-2',
        );
        $dimensions ??= (int) config('ai.embedding.dimensions', 768);

        return self::makeEmbeddingHash(
            $model,
            $dimensions,
            (string) $this->title,
            (string) $this->content,
            $this->category?->name,
            is_array($this->keywords) ? $this->keywords : [],
            $this->source_name,
        );
    }

    public function hasCurrentEmbedding(
        ?string $model = null,
        ?int $dimensions = null,
    ): bool {
        $model ??= (string) config(
            'ai.embedding.model',
            'gemini-embedding-2',
        );
        $dimensions ??= max(
            1,
            (int) config('ai.embedding.dimensions', 768),
        );

        if ($this->embedding_model !== $model
            || ($this->embedding_dimensions !== null
                && $this->embedding_dimensions !== $dimensions)
            || $this->embedded_at === null
            || ! is_string($this->embedding_hash)
            || ! hash_equals(
                $this->expectedEmbeddingHash($model, $dimensions),
                $this->embedding_hash,
            )
            || ! is_array($this->embedding)) {
            return false;
        }

        return Similarity::validatedVector(
            $this->embedding,
            $dimensions,
        ) !== null;
    }

    public function embeddingStatus(): string
    {
        if ($this->hasCurrentEmbedding()) {
            return filled($this->embedding_error)
                ? self::EMBEDDING_STATUS_READY_WITH_WARNING
                : self::EMBEDDING_STATUS_READY;
        }

        if (filled($this->embedding_error)) {
            return self::EMBEDDING_STATUS_FAILED;
        }

        if ($this->embedding === null
            && $this->embedding_hash === null
            && $this->embedding_model === null
            && $this->embedded_at === null) {
            return self::EMBEDDING_STATUS_MISSING;
        }

        return self::EMBEDDING_STATUS_STALE;
    }

    public static function makeEmbeddingHash(
        string $model,
        int $dimensions,
        string $title,
        string $content,
        ?string $categoryName = null,
        array $keywords = [],
        ?string $sourceName = null,
    ): string {
        $framedValues = array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            [
                self::EMBEDDING_FORMAT_VERSION,
                $model,
                (string) $dimensions,
                self::formatEmbeddingText(
                    $title,
                    $content,
                    $categoryName,
                    $keywords,
                    $sourceName,
                ),
            ],
        );

        return hash('sha256', implode('|', $framedValues));
    }

    public static function formatEmbeddingText(
        string $title,
        string $content,
        ?string $categoryName = null,
        array $keywords = [],
        ?string $sourceName = null,
    ): string {
        $lines = [
            'Judul: '.trim($title),
        ];

        if (filled($categoryName)) {
            $lines[] = 'Kategori: '.trim((string) $categoryName);
        }

        $keywords = array_values(array_filter(array_map(
            static fn (mixed $keyword): string => is_scalar($keyword)
                ? trim((string) $keyword)
                : '',
            $keywords,
        )));
        sort($keywords, SORT_NATURAL | SORT_FLAG_CASE);

        if ($keywords !== []) {
            $lines[] = 'Kata kunci: '.implode(', ', $keywords);
        }

        $lines[] = 'Isi: '.trim($content);

        if (filled($sourceName)) {
            $lines[] = 'Sumber: '.trim((string) $sourceName);
        }

        return implode("\n", $lines);
    }
}
