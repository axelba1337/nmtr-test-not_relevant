<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKnowledgeItemRequest;
use App\Http\Requests\Admin\UpdateKnowledgeItemRequest;
use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Services\KnowledgeEmbeddingService;
use App\Support\AdminIndexSort;
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
            'embedding_status' => ['nullable', 'in:all,failed'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $status = (string) ($filters['status'] ?? 'all');
        $embeddingStatus = (string) ($filters['embedding_status'] ?? 'all');
        $sort = AdminIndexSort::normalize($request->query('sort'));

        $query = KnowledgeItem::query()
            ->with('category:id,name')
            ->when(
                $search !== '',
                static function (Builder $query) use ($search): void {
                    $query->where(
                        static function (Builder $query) use ($search): void {
                            $query
                                ->where('title', 'like', "%{$search}%")
                                ->orWhere('content', 'like', "%{$search}%")
                                ->orWhere('keywords', 'like', "%{$search}%")
                                ->orWhere('source_name', 'like', "%{$search}%");
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
            ->when(
                $embeddingStatus === 'failed',
                static fn (Builder $query): Builder => $query
                    ->where('is_active', true)
                    ->whereNotNull('embedding_error'),
            );

        $items = AdminIndexSort::apply(
            $query,
            $sort,
            'title',
            static fn (Builder $query): Builder => $query
                ->orderByDesc('is_active'),
        )
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.knowledge.index', [
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'embeddingStatus' => $embeddingStatus,
            'sort' => $sort,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.knowledge.create', [
            'categories' => $this->categoryOptions(),
        ]);
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
                    __('ui.messages.admin.knowledge.created_embedding_failed'),
                );
        }

        return redirect()
            ->route('admin.knowledge.index')
            ->with(
                'success',
                __('ui.messages.admin.knowledge.created'),
            );
    }

    public function edit(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): View {
        $this->ensureAdmin($request);

        return view('admin.knowledge.edit', [
            'item' => $knowledgeItem,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(
        UpdateKnowledgeItemRequest $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $knowledgeItem->fill($request->validated());
        $sourceChanged = $knowledgeItem->isDirty([
            'title',
            'content',
            'category_id',
            'keywords',
            'source_name',
        ]);
        $knowledgeItem->save();

        if (! $sourceChanged) {
            return redirect()
                ->route('admin.knowledge.index')
                ->with('success', __('ui.messages.admin.knowledge.updated'));
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
                    __('ui.messages.admin.knowledge.updated_embedding_failed'),
                );
        }

        return redirect()
            ->route('admin.knowledge.index')
            ->with(
                'success',
                __('ui.messages.admin.knowledge.updated_with_embedding'),
            );
    }

    public function destroy(
        Request $request,
        KnowledgeItem $knowledgeItem,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $knowledgeItem->delete();

        return redirect()
            ->route('admin.knowledge.index')
            ->with('success', __('ui.messages.admin.knowledge.deleted'));
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
                ? __('ui.messages.admin.knowledge.activated')
                : __('ui.messages.admin.knowledge.deactivated'),
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
                ? __('ui.messages.admin.knowledge.featured')
                : __('ui.messages.admin.knowledge.unfeatured'),
        );
    }

    private function categoryOptions()
    {
        return Category::query()
            ->ordered()
            ->get(['id', 'name', 'parent_id', 'is_active']);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
