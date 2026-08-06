<?php

namespace App\Models;

use App\Services\Neomatra\RankingCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RankingProfile extends Model
{
    protected $fillable = [
        'name',
        'relevance_weight',
        'price_weight',
        'condition_weight',
        'seller_weight',
        'freshness_weight',
        'is_active',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'relevance_weight' => 'float',
            'price_weight' => 'float',
            'condition_weight' => 'float',
            'seller_weight' => 'float',
            'freshness_weight' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return array<string, float> */
    public function weights(): array
    {
        return [
            RankingCalculator::SCORE_RELEVANCE => $this->relevance_weight,
            RankingCalculator::SCORE_PRICE => $this->price_weight,
            RankingCalculator::SCORE_CONDITION => $this->condition_weight,
            RankingCalculator::SCORE_SELLER => $this->seller_weight,
            RankingCalculator::SCORE_FRESHNESS => $this->freshness_weight,
        ];
    }
}
