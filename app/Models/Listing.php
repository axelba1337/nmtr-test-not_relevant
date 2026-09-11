<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Listing extends Model
{
    public const MAX_MONEY = 999_999_999_999_999;

    public const CONDITION_NEW = 'new';

    public const CONDITION_OPEN_BOX = 'open_box';

    public const CONDITION_USED = 'used';

    public const CONDITION_REFURBISHED = 'refurbished';

    public const CONDITION_EX_DISPLAY = 'ex_display';

    public const CONDITION_UNKNOWN = 'unknown';

    public const CONDITION_SOURCE_SELLER = 'seller_description';

    public const SELLER_OFFICIAL = 'official';

    public const SELLER_STORE = 'store';

    public const SELLER_RESELLER = 'reseller';

    public const SELLER_INDIVIDUAL = 'individual';

    public const SELLER_UNKNOWN = 'unknown';

    public const STOCK_IN_STOCK = 'in_stock';

    public const STOCK_OUT_OF_STOCK = 'out_of_stock';

    public const STOCK_UNKNOWN = 'unknown';

    public const PRICE_FULL = 'full_price';

    public const PRICE_INSTALLMENT = 'installment';

    public const PRICE_DOWN_PAYMENT = 'down_payment';

    public const PRICE_UNKNOWN = 'unknown';

    protected $fillable = [
        'platform_id',
        'product_id',
        'external_listing_id',
        'source_fingerprint',
        'title',
        'description',
        'price',
        'currency',
        'condition',
        'condition_source',
        'seller_name',
        'seller_type',
        'location',
        'product_url',
        'image_url',
        'stock_status',
        'price_type',
        'shipping_cost',
        'service_fee',
        'inspection_cost',
        'repair_estimate',
        'warranty',
        'raw_payload',
        'source_published_at',
        'first_seen_at',
        'last_seen_at',
        'last_synced_at',
        'is_active',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $listing): void {
            $observedAt = $listing->getAttribute('last_seen_at')
                ?? $listing->getAttribute('last_synced_at')
                ?? now();

            if ($listing->getAttribute('first_seen_at') === null) {
                $listing->setAttribute('first_seen_at', $observedAt);
            }

            if ($listing->getAttribute('last_seen_at') === null) {
                $listing->setAttribute('last_seen_at', $observedAt);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'shipping_cost' => 'integer',
            'service_fee' => 'integer',
            'inspection_cost' => 'integer',
            'repair_estimate' => 'integer',
            'raw_payload' => 'array',
            'source_published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(Platform::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query
            ->active()
            ->where('stock_status', '!=', self::STOCK_OUT_OF_STOCK)
            ->where('price_type', self::PRICE_FULL)
            ->where('price', '>', 0);
    }

    /** @return list<string> */
    public static function conditions(): array
    {
        return [
            self::CONDITION_NEW,
            self::CONDITION_OPEN_BOX,
            self::CONDITION_USED,
            self::CONDITION_REFURBISHED,
            self::CONDITION_EX_DISPLAY,
            self::CONDITION_UNKNOWN,
        ];
    }

    /** @return list<string> */
    public static function publicConditionFilters(): array
    {
        return [
            self::CONDITION_NEW,
            self::CONDITION_USED,
            self::CONDITION_UNKNOWN,
        ];
    }

    /** @return list<string> */
    public static function conditionValuesForSearch(string $condition): array
    {
        return match ($condition) {
            self::CONDITION_USED => [
                self::CONDITION_USED,
                self::CONDITION_OPEN_BOX,
                self::CONDITION_REFURBISHED,
                self::CONDITION_EX_DISPLAY,
            ],
            self::CONDITION_NEW,
            self::CONDITION_OPEN_BOX,
            self::CONDITION_REFURBISHED,
            self::CONDITION_EX_DISPLAY,
            self::CONDITION_UNKNOWN => [$condition],
            default => [],
        };
    }

    /** @return list<string> */
    public static function sellerTypes(): array
    {
        return [
            self::SELLER_OFFICIAL,
            self::SELLER_STORE,
            self::SELLER_RESELLER,
            self::SELLER_INDIVIDUAL,
            self::SELLER_UNKNOWN,
        ];
    }

    /** @return list<string> */
    public static function stockStatuses(): array
    {
        return [
            self::STOCK_IN_STOCK,
            self::STOCK_OUT_OF_STOCK,
            self::STOCK_UNKNOWN,
        ];
    }

    /** @return list<string> */
    public static function priceTypes(): array
    {
        return [
            self::PRICE_FULL,
            self::PRICE_INSTALLMENT,
            self::PRICE_DOWN_PAYMENT,
            self::PRICE_UNKNOWN,
        ];
    }
}
