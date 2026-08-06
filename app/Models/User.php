<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    public function marketplaceSyncRuns(): HasMany
    {
        return $this->hasMany(MarketplaceSyncRun::class, 'initiated_by');
    }

    public function reviewedChatbotQueries(): HasMany
    {
        return $this->hasMany(ChatbotQueryLog::class, 'reviewed_by');
    }

    public function updatedRankingProfiles(): HasMany
    {
        return $this->hasMany(RankingProfile::class, 'updated_by');
    }
}
