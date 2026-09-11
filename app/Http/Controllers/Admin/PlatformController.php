<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformRequest;
use App\Models\Platform;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PlatformController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $sort = AdminIndexSort::normalize($request->query('sort'));

        $unsupportedPlatforms = Platform::query()
            ->whereNotIn('slug', Platform::MVP_SLUGS)
            ->orderBy('name')
            ->pluck('name');
        $query = Platform::query()
            ->whereIn('slug', Platform::MVP_SLUGS)
            ->withCount([
                'listings as active_listings_count' => static fn (Builder $query): Builder => $query
                    ->available(),
            ])
            ->withMax('listings as latest_listing_activity_at', 'updated_at')
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")))
            ->when($status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false));

        $platforms = AdminIndexSort::apply(
            $query,
            $sort,
            'name',
            static fn (Builder $query): Builder => $query
                ->orderByDesc('is_active')
                ->orderBy('status'),
        )
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.platforms.index', compact(
            'platforms',
            'search',
            'status',
            'sort',
            'unsupportedPlatforms',
        ));
    }

    public function edit(Request $request, Platform $platform): View
    {
        $this->ensureAdmin($request);
        $this->ensureMvpSource($platform);

        return view('admin.platforms.edit', compact('platform'));
    }

    public function update(
        UpdatePlatformRequest $request,
        Platform $platform,
    ): RedirectResponse {
        $this->ensureMvpSource($platform);
        $validated = $request->validated();
        $isActive = (bool) $validated['is_active'];
        $platform->forceFill([
            'base_url' => $validated['base_url'],
            'is_active' => $isActive,
            'sync_enabled' => false,
            'status' => $isActive
                ? Platform::STATUS_READY
                : Platform::STATUS_DISABLED,
        ]);
        $platform->save();

        return redirect()->route('admin.platforms.index')
            ->with('success', __('ui.messages.admin.platform.updated'));
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }

    private function ensureMvpSource(Platform $platform): void
    {
        abort_unless($platform->isMvpSource(), 404);
    }
}
