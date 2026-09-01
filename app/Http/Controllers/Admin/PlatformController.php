<?php

namespace App\Http\Controllers\Admin;

use App\Data\Marketplace\MarketplaceSyncReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePlatformRequest;
use App\Http\Requests\Admin\UpdatePlatformRequest;
use App\Models\Platform;
use App\Services\Marketplace\MarketplaceSyncService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlatformController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'source_type' => ['nullable', 'in:'.implode(',', Platform::sourceTypes())],
            'status' => ['nullable', 'in:all,active,inactive,syncable'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $sourceType = (string) ($filters['source_type'] ?? '');
        $status = (string) ($filters['status'] ?? 'all');

        $platforms = Platform::query()
            ->withCount('listings')
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('connector', 'like', "%{$search}%")))
            ->when($sourceType !== '', fn (Builder $query): Builder => $query
                ->where('source_type', $sourceType))
            ->when($status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->when($status === 'syncable', fn (Builder $query): Builder => $query
                ->syncable())
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('admin.platforms.index', compact(
            'platforms',
            'search',
            'sourceType',
            'status',
        ));
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.platforms.create');
    }

    public function store(StorePlatformRequest $request): RedirectResponse
    {
        Platform::query()->create($request->validated());

        return redirect()->route('admin.platforms.index')
            ->with('success', __('ui.messages.admin.platform.created'));
    }

    public function edit(Request $request, Platform $platform): View
    {
        $this->ensureAdmin($request);

        return view('admin.platforms.edit', compact('platform'));
    }

    public function update(
        UpdatePlatformRequest $request,
        Platform $platform,
    ): RedirectResponse {
        $platform->fill($request->validated());

        if ($platform->isDirty('slug') && $platform->listings()->exists()) {
            throw ValidationException::withMessages([
                'slug' => __('ui.messages.admin.platform.slug_locked'),
            ]);
        }

        $platform->save();

        return redirect()->route('admin.platforms.index')
            ->with('success', __('ui.messages.admin.platform.updated'));
    }

    public function destroy(Request $request, Platform $platform): RedirectResponse
    {
        $this->ensureAdmin($request);

        if ($platform->listings()->exists()) {
            return back()->with(
                'warning',
                __('ui.messages.admin.platform.in_use'),
            );
        }

        $platform->delete();

        return redirect()->route('admin.platforms.index')
            ->with('success', __('ui.messages.admin.platform.deleted'));
    }

    public function sync(
        Request $request,
        Platform $platform,
        MarketplaceSyncService $syncService,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'max_pages' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $report = $syncService->sync(
            $platform,
            isset($validated['max_pages'])
                ? (int) $validated['max_pages']
                : null,
            (int) $request->user()->id,
        );
        $message = __('ui.messages.admin.platform.sync_completed', [
            'status' => $report->status,
            'seen' => $report->seen,
            'created' => $report->created,
            'updated' => $report->updated,
            'unchanged' => $report->unchanged,
            'skipped' => $report->skipped,
            'failed' => $report->failed,
        ]);

        if (filled($report->errorMessage)) {
            $message .= ' '.$report->errorMessage;
        }

        return back()->with(
            $report->succeeded()
                ? ($report->status === MarketplaceSyncReport::STATUS_PARTIAL
                    || $report->failed > 0 ? 'warning' : 'success')
                : 'error',
            $message,
        );
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
