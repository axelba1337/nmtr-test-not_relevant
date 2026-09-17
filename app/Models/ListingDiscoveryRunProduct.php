<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ListingDiscoveryRunProduct extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_NO_RESULT = 'no_result';

    public const STATUS_CANDIDATE_FOUND = 'candidate_found';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'listing_discovery_run_id',
        'product_id',
        'available_listings_before',
        'still_needed',
        'candidates_found',
        'valid_candidates',
        'rejected_candidates',
        'diagnostics',
        'provider_failed',
        'provider_error_code',
        'status',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'available_listings_before' => 'integer',
            'still_needed' => 'integer',
            'candidates_found' => 'integer',
            'valid_candidates' => 'integer',
            'rejected_candidates' => 'integer',
            'diagnostics' => 'array',
            'provider_failed' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            ListingDiscoveryRun::class,
            'listing_discovery_run_id',
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
