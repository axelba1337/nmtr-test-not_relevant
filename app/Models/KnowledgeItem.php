<?php

namespace App\Models;

use App\Helpers\Similarity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KnowledgeItem extends Model
{
    public const EMBEDDING_FORMAT_VERSION = 'faq-v1';

    public const EMBEDDING_STATUS_READY = 'ready';

    public const EMBEDDING_STATUS_READY_WITH_WARNING = 'ready_with_warning';

    public const EMBEDDING_STATUS_FAILED = 'failed';

    public const EMBEDDING_STATUS_MISSING = 'missing';

    public const EMBEDDING_STATUS_STALE = 'stale';

    protected $fillable = [
        'title',
        'content',
        'embedding',
        'embedding_hash',
        'embedding_model',
        'embedded_at',
        'embedding_error',
        'is_active',
        'is_featured',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'embedded_at' => 'datetime',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
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
        );
    }

    public function expectedEmbeddingHash(
        ?string $model = null,
        ?int $dimensions = null,
    ): string {
        $model ??= (string) config('chatbot.models.embed', 'gemini-embedding-2');
        $dimensions ??= (int) config('chatbot.embedding_dimensions', 768);

        return self::makeEmbeddingHash(
            $model,
            $dimensions,
            (string) $this->title,
            (string) $this->content,
        );
    }

    public function hasCurrentEmbedding(
        ?string $model = null,
        ?int $dimensions = null,
    ): bool {
        $model ??= (string) config(
            'chatbot.models.embed',
            'gemini-embedding-2',
        );
        $dimensions ??= max(
            1,
            (int) config('chatbot.embedding_dimensions', 768),
        );

        if ($this->embedding_model !== $model
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
    ): string {
        $framedValues = array_map(
            static fn (string $value): string => strlen($value).':'.$value,
            [
                self::EMBEDDING_FORMAT_VERSION,
                $model,
                (string) $dimensions,
                self::formatEmbeddingText($title, $content),
            ],
        );

        return hash('sha256', implode('|', $framedValues));
    }

    public static function formatEmbeddingText(
        string $title,
        string $content,
    ): string {
        return "Pertanyaan: {$title}\nJawaban: {$content}";
    }
}
