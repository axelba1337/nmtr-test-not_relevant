<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ChatbotQueryLog extends Model
{
    public const STATUS_ANSWERED = 'answered';

    public const STATUS_EMBEDDING_FAILED = 'embedding_failed';

    public const STATUS_KNOWLEDGE_UNAVAILABLE = 'knowledge_unavailable';

    public const STATUS_GENERATION_FAILED = 'generation_failed';

    protected $fillable = [
        'status',
        'top1_similarity',
    ];

    protected function casts(): array
    {
        return [
            'top1_similarity' => 'float',
        ];
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
        ];
    }
}
