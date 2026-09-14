<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ListingCandidate extends Model
{
    public const VALIDATION_VALID = 'valid';

    public const VALIDATION_INVALID = 'invalid';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    public const REVIEW_INVALID = 'invalid';

    protected $fillable = [
        'listing_discovery_run_id',
        'target_product_id',
        'listing_id',
        'reviewed_by',
        'platform_slug',
        'external_listing_id',
        'title',
        'price',
        'currency',
        'condition',
        'seller_name',
        'location',
        'product_url',
        'normalized_url_hash',
        'grounding_sources',
        'raw_payload',
        'validation_status',
        'review_status',
        'rejection_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'grounding_sources' => 'array',
            'raw_payload' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(
            ListingDiscoveryRun::class,
            'listing_discovery_run_id',
        );
    }

    public function targetProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'target_product_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
