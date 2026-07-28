<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKnowledgeItemRequest;
use App\Http\Requests\Admin\UpdateKnowledgeItemRequest;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeEmbeddingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class KnowledgeItemController extends Controller
{
    public function __construct(
        private readonly KnowledgeEmbeddingService $embeddingService,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');

        $items = KnowledgeItem::query()
            ->when(
                $search !== '',
                static function (Builder $query) use ($search): void {
                    $query->where(
                        static function (Builder $query) use ($search): void {
                            $query
                                ->where('title', 'like', "%{$search}%")
                                ->orWhere('content', 'like', "%{$search}%");
                        },
                    );
                },
            )
            ->when(
                $status === 'active',
                static fn (Builder $query): Builder => $query->where(
                    'is_active',
                    true,
                ),
            )
            ->when(
                $status === 'inactive',
                static fn (Builder $query): Builder => $query->where(
                    'is_active',
                    false,
                ),
            )
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.knowledge.index', [
            'items' => $items,
            'search' => $search,
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.knowledge.create');
    }

    public function store(
        StoreKnowledgeItemRequest $request,
    ): RedirectResponse {
        $item = KnowledgeItem::query()->create($request->validated());
        $status = $this->embeddingService->rebuild($item);

        if ($status === KnowledgeEmbeddingService::STATUS_FAILED) {
            return redirect()
                ->route('admin.knowledge.index')
                ->with(
                    'warning',
                    'FAQ tersimpan, tetapi embedding belum berhasil dibuat.',
                );
        }

        return redirect()
            ->route('admin.knowledge.index')
            ->with('success', 'FAQ dan embedding berhasil disimpan.');
    }

    public function edit(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): View {
        $this->ensureAdmin($request);

        return view('admin.knowledge.edit', [
            'item' => $knowledgeItem,
        ]);
    }

    public function update(
        UpdateKnowledgeItemRequest $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $knowledgeItem->fill($request->validated());
        $sourceChanged = $knowledgeItem->isDirty(['title', 'content']);
        $knowledgeItem->save();

        if (! $sourceChanged) {
            return redirect()
                ->route('admin.knowledge.index')
                ->with('success', 'FAQ berhasil diperbarui.');
        }

        $status = $this->embeddingService->rebuild(
            $knowledgeItem,
            true,
        );

        if ($status === KnowledgeEmbeddingService::STATUS_FAILED) {
            return redirect()
                ->route('admin.knowledge.index')
                ->with(
                    'warning',
                    'FAQ diperbarui, tetapi embedding baru gagal dibuat. Embedding lama tetap disimpan.',
                );
        }

        return redirect()
            ->route('admin.knowledge.index')
            ->with('success', 'FAQ dan embedding berhasil diperbarui.');
    }

    public function destroy(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $knowledgeItem->delete();

        return redirect()
            ->route('admin.knowledge.index')
            ->with('success', 'FAQ berhasil dihapus.');
    }

    public function toggleActive(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $knowledgeItem->forceFill([
            'is_active' => ! $knowledgeItem->is_active,
        ])->save();

        return back()->with(
            'success',
            $knowledgeItem->is_active
                ? 'FAQ berhasil diaktifkan.'
                : 'FAQ berhasil dinonaktifkan.',
        );
    }

    public function toggleFeatured(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $knowledgeItem->forceFill([
            'is_featured' => ! $knowledgeItem->is_featured,
        ])->save();

        return back()->with(
            'success',
            $knowledgeItem->is_featured
                ? 'FAQ ditandai sebagai featured.'
                : 'Tanda featured FAQ dihapus.',
        );
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
