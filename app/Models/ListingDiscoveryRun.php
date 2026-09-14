<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ListingDiscoveryRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'created_by',
        'status',
        'provider',
        'model',
        'region',
        'products_checked',
        'candidates_found',
        'valid_candidates',
        'rejected_candidates',
        'provider_failures',
        'error_code',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'products_checked' => 'integer',
            'candidates_found' => 'integer',
            'valid_candidates' => 'integer',
            'rejected_candidates' => 'integer',
            'provider_failures' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ListingCandidate::class);
    }
}
