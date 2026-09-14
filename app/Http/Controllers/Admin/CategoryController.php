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
use App\Services\Export\AdminXlsxExporter;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CatalogScope $catalogScope,
        private readonly AdminXlsxExporter $xlsx,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $categories = $this->filteredQuery($state)
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.categories.index', [
            'categories' => $categories,
            ...$state,
            'exportQuery' => $this->exportQuery($state),
        ]);
    }

    public function export(Request $request): Response
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $rows = $this->filteredQuery($state)
            ->get()
            ->map(static fn (Category $category): array => [
                $category->id,
                $category->name,
                $category->slug,
                $category->parent?->name,
                $category->description,
                $category->is_active ? 'ACTIVE' : 'INACTIVE',
                $category->sort_order,
                $category->children_count,
                $category->products_count,
            ]);

        return $this->xlsx->download(
            'neomatra-categories-'.now()->format('Y-m-d-Hi').'.xlsx',
            'Categories',
            [
                'ID', 'Category', 'Slug', 'Parent Category', 'Description',
                'Status', 'Sort Order', 'Subcategories Count', 'Products Count',
            ],
            $rows,
            [1 => 9, 2 => 28, 3 => 25, 4 => 25, 5 => 48, 6 => 14, 7 => 12, 8 => 20, 9 => 16],
            [5],
        );
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
        $query = Category::query()
            ->with('parent:id,name')
            ->withCount(['children', 'products'])
            ->when($state['search'] !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$state['search']}%")
                    ->orWhere('slug', 'like', "%{$state['search']}%")
                    ->orWhere('description', 'like', "%{$state['search']}%")))
            ->when($state['status'] === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($state['status'] === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false));

        return AdminIndexSort::apply(
            $query,
            $state['sort'],
            'name',
            static fn (Builder $query): Builder => $query
                ->orderByDesc('is_active'),
        );
    }

    /** @param array{search: string, status: string, sort: string} $state */
    private function exportQuery(array $state): array
    {
        return $state;
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
