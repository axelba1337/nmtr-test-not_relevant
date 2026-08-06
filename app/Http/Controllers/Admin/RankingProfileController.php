<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRankingProfileRequest;
use App\Models\RankingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class RankingProfileController extends Controller
{
    private const DEFAULTS = [
        'name' => 'Default Neomatra',
        'relevance_weight' => 0.40,
        'price_weight' => 0.30,
        'condition_weight' => 0.15,
        'seller_weight' => 0.10,
        'freshness_weight' => 0.05,
    ];

    public function edit(Request $request): View
    {
        $this->ensureAdmin($request);
        $profile = RankingProfile::query()->active()->first()
            ?? RankingProfile::query()->first()
            ?? new RankingProfile(self::DEFAULTS);

        return view('admin.ranking.edit', compact('profile'));
    }

    public function update(
        UpdateRankingProfileRequest $request,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request): void {
            RankingProfile::query()->lockForUpdate()->get(['id']);
            $profile = RankingProfile::query()->firstOrNew([
                'name' => $data['name'],
            ]);
            $profile->fill([
                ...$data,
                'is_active' => true,
                'updated_by' => $request->user()->id,
            ])->save();
            RankingProfile::query()
                ->where('id', '!=', $profile->getKey())
                ->update(['is_active' => false]);
        });

        return redirect()->route('admin.ranking.edit')
            ->with('success', 'Bobot ranking berhasil diperbarui.');
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
