<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MarketplaceSyncRun extends Model
{
    public const MODE_MANUAL = 'manual';

    public const MODE_SCHEDULED = 'scheduled';

    public const MODE_REQUEST = 'request';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'platform_id',
        'initiated_by',
        'mode',
        'status',
        'records_seen',
        'records_created',
        'records_updated',
        'records_skipped',
        'records_failed',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'records_seen' => 'integer',
            'records_created' => 'integer',
            'records_updated' => 'integer',
            'records_skipped' => 'integer',
            'records_failed' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->latest('id');
    }

    /** @return list<string> */
    public static function modes(): array
    {
        return [
            self::MODE_MANUAL,
            self::MODE_SCHEDULED,
            self::MODE_REQUEST,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
            self::STATUS_COMPLETED,
            self::STATUS_PARTIAL,
            self::STATUS_FAILED,
        ];
    }
}
