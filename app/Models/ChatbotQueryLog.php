<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ChatbotQueryLog extends Model
{
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_EMBEDDING_FAILED = 'embedding_failed';

    public const STATUS_KNOWLEDGE_UNAVAILABLE = 'knowledge_unavailable';

    public const STATUS_GENERATION_FAILED = 'generation_failed';

    public const STATUS_NO_RESULTS = 'no_results';

    public const STATUS_SEARCH_FAILED = 'search_failed';

    public const MODE_MARKETPLACE_RESULTS = 'marketplace_results';

    public const MODE_KNOWLEDGE_ONLY = 'knowledge_only';

    public const MODE_WEB_GROUNDED = 'web_grounded';

    public const MODE_GENERAL_MODEL = 'general_model';

    public const MODE_DETERMINISTIC_FALLBACK = 'deterministic_fallback';

    protected $fillable = [
        'status',
        'top1_similarity',
        'response_mode',
        'prompt_tokens',
        'output_tokens',
        'total_tokens',
        'failure_code',
        'session_key',
        'query_text',
        'normalized_query',
        'intent',
        'filters',
        'result_count',
        'is_unanswered',
        'unanswered_reason',
        'reviewed_at',
        'reviewed_by',
        'review_notes',
    ];

    protected function casts(): array
    {
        return [
            'top1_similarity' => 'float',
            'prompt_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'intent' => 'array',
            'filters' => 'array',
            'result_count' => 'integer',
            'is_unanswered' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->where('is_unanswered', true);
    }

    public function scopePendingReview(Builder $query): Builder
    {
        return $query->unanswered()->whereNull('reviewed_at');
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_ANSWERED,
            self::STATUS_EMBEDDING_FAILED,
            self::STATUS_KNOWLEDGE_UNAVAILABLE,
            self::STATUS_GENERATION_FAILED,
            self::STATUS_NO_RESULTS,
            self::STATUS_SEARCH_FAILED,
        ];
    }

    /**
     * @return list<string>
     */
    public static function responseModes(): array
    {
        return [
            self::MODE_MARKETPLACE_RESULTS,
            self::MODE_KNOWLEDGE_ONLY,
            self::MODE_WEB_GROUNDED,
            self::MODE_GENERAL_MODEL,
            self::MODE_DETERMINISTIC_FALLBACK,
        ];
    }
}
