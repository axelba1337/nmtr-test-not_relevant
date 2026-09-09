<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCategoryRequest;
use App\Http\Requests\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\KnowledgeItem;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Catalog\CatalogScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CatalogScope $catalogScope,
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

        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount(['children', 'products'])
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")))
            ->when($status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->ordered()
            ->paginate(15)
            ->withQueryString();

        return view('admin.categories.index', compact(
            'categories',
            'search',
            'status',
        ));
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.categories.create', [
            'parents' => $this->catalogScope->validParentOptions(),
        ]);
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        Category::query()->create($request->validated());

        return redirect()->route('admin.categories.index')
            ->with('success', __('ui.messages.admin.category.created'));
    }

    public function edit(Request $request, Category $category): View
    {
        $this->ensureAdmin($request);

        return view('admin.categories.edit', [
            'category' => $category,
            'parents' => $this->catalogScope->validParentOptions($category),
        ]);
    }

    public function update(
        UpdateCategoryRequest $request,
        Category $category,
    ): RedirectResponse {
        $data = $request->validated();
        $semanticChanged = $category->name !== $data['name'];

        DB::transaction(function () use (
            $category,
            $data,
            $semanticChanged,
        ): void {
            $category->update($data);

            if ($semanticChanged) {
                $this->markRelatedEmbeddingsStale($category);
            }
        });

        return redirect()->route('admin.categories.index')
            ->with(
                $semanticChanged ? 'warning' : 'success',
                $semanticChanged
                    ? __('ui.messages.admin.category.updated_embeddings_stale')
                    : __('ui.messages.admin.category.updated'),
            );
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        $this->ensureAdmin($request);

        if ($category->products()->exists()) {
            return back()->with(
                'warning',
                __('ui.messages.admin.category.in_use'),
            );
        }

        DB::transaction(function () use ($category): void {
            KnowledgeItem::query()
                ->where('category_id', $category->getKey())
                ->update(['embedding_hash' => null]);
            $category->delete();
        });

        return redirect()->route('admin.categories.index')
            ->with('success', __('ui.messages.admin.category.deleted'));
    }

    private function markRelatedEmbeddingsStale(Category $category): void
    {
        $productIds = Product::query()
            ->where('category_id', $category->getKey())
            ->pluck('id');

        Product::query()
            ->whereIn('id', $productIds)
            ->update(['embedding_hash' => null]);
        ProductAlias::query()
            ->whereIn('product_id', $productIds)
            ->update(['embedding_hash' => null]);
        KnowledgeItem::query()
            ->where('category_id', $category->getKey())
            ->update(['embedding_hash' => null]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
