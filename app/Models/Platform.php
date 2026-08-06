<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Platform extends Model
{
    public const SOURCE_API = 'api';

    public const SOURCE_FEED = 'feed';

    public const SOURCE_DEMO = 'demo';

    public const SOURCE_MANUAL = 'manual';

    public const ACCESS_OFFICIAL_API = 'official_api';

    public const ACCESS_PARTNER_FEED = 'partner_feed';

    public const ACCESS_DEMO_DATASET = 'demo_dataset';

    public const ACCESS_MANUAL_URL = 'manual_url';

    public const ACCESS_FILE_IMPORT = 'file_import';

    public const STATUS_READY = 'ready';

    public const STATUS_DISABLED = 'disabled';

    public const STATUS_SYNCING = 'syncing';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'name',
        'slug',
        'source_type',
        'access_type',
        'connector',
        'base_url',
        'is_active',
        'sync_enabled',
        'status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sync_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(MarketplaceSyncRun::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePubliclyAvailable(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('status', '!=', self::STATUS_DISABLED);
    }

    public function scopeSyncable(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('sync_enabled', true)
            ->where('status', '!=', self::STATUS_DISABLED);
    }

    /** @return list<string> */
    public static function sourceTypes(): array
    {
        return [
            self::SOURCE_API,
            self::SOURCE_FEED,
            self::SOURCE_DEMO,
            self::SOURCE_MANUAL,
        ];
    }

    /** @return list<string> */
    public static function accessTypes(): array
    {
        return [
            self::ACCESS_OFFICIAL_API,
            self::ACCESS_PARTNER_FEED,
            self::ACCESS_DEMO_DATASET,
            self::ACCESS_MANUAL_URL,
            self::ACCESS_FILE_IMPORT,
        ];
    }

    /** @return list<string> */
    public static function statuses(): array
    {
        return [
            self::STATUS_READY,
            self::STATUS_DISABLED,
            self::STATUS_SYNCING,
            self::STATUS_ERROR,
        ];
    }
}
