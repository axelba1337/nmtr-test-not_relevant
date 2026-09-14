<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePlatformRequest;
use App\Models\Platform;
use App\Services\Export\AdminXlsxExporter;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class PlatformController extends Controller
{
    public function __construct(
        private readonly AdminXlsxExporter $xlsx,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);

        $unsupportedPlatforms = Platform::query()
            ->whereNotIn('slug', Platform::MVP_SLUGS)
            ->orderBy('name')
            ->pluck('name');
        $platforms = $this->filteredQuery($state)
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.platforms.index', [
            'platforms' => $platforms,
            'unsupportedPlatforms' => $unsupportedPlatforms,
            ...$state,
            'exportQuery' => $state,
        ]);
    }

    public function export(Request $request): Response
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $rows = $this->filteredQuery($state)
            ->get()
            ->map(static fn (Platform $platform): array => [
                $platform->id,
                $platform->name,
                $platform->active_listings_count,
                filled($platform->latest_listing_activity_at)
                    ? new \DateTime((string) $platform->latest_listing_activity_at)
                    : null,
                $platform->is_active ? 'ACTIVE' : 'INACTIVE',
            ]);

        return $this->xlsx->download(
            'neomatra-platforms-'.now()->format('Y-m-d-Hi').'.xlsx',
            'Platforms',
            ['ID', 'Platform', 'Active Listings', 'Last Update', 'Status'],
            $rows,
            [1 => 9, 2 => 28, 3 => 16, 4 => 21, 5 => 14],
        );
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

    /** @return array{search: string, status: string, sort: string} */
    private function indexState(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'status' => (string) ($filters['status'] ?? 'all'),
            'sort' => AdminIndexSort::normalize($request->query('sort')),
        ];
    }

    /** @param array{search: string, status: string, sort: string} $state */
    private function filteredQuery(array $state): Builder
    {
        $query = Platform::query()
            ->whereIn('slug', Platform::MVP_SLUGS)
            ->withCount([
                'listings as active_listings_count' => static fn (Builder $query): Builder => $query
                    ->available(),
            ])
            ->withMax('listings as latest_listing_activity_at', 'updated_at')
            ->when($state['search'] !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$state['search']}%")
                    ->orWhere('slug', 'like', "%{$state['search']}%")))
            ->when($state['status'] === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($state['status'] === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false));

        return AdminIndexSort::apply(
            $query,
            $state['sort'],
            'name',
            static fn (Builder $query): Builder => $query
                ->orderByDesc('is_active')
                ->orderBy('status'),
        );
    }

    private function ensureMvpSource(Platform $platform): void
    {
        abort_unless($platform->isMvpSource(), 404);
    }
}
