<?php

namespace App\Models;

use App\Helpers\Normalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CategoryAlias extends Model
{
    protected $fillable = [
        'category_id',
        'alias',
        'normalized_alias',
        'language',
        'source',
        'is_active',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $alias): void {
            if (! filled($alias->normalized_alias)
                || $alias->isDirty('alias')) {
                $alias->normalized_alias = Normalizer::text(
                    (string) $alias->alias,
                );
            }
        });
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
