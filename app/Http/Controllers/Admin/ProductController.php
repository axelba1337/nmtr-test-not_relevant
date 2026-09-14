<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Normalizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Product;
use App\Services\Catalog\CatalogScope;
use App\Services\Embedding\EntityEmbeddingService;
use App\Services\Embedding\ProductEmbeddingStatus;
use App\Services\Export\AdminXlsxExporter;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class ProductController extends Controller
{
    public function __construct(
        private readonly EntityEmbeddingService $embeddingService,
        private readonly ProductEmbeddingStatus $embeddingStatus,
        private readonly CatalogScope $catalogScope,
        private readonly AdminXlsxExporter $xlsx,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $products = $this->filteredQuery($state)
            ->with('category:id,name')
            ->withCount(['aliases', 'listings'])
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => $this->categoryOptions(),
            ...$state,
            'exportQuery' => $this->exportQuery($state),
        ]);
    }

    public function export(Request $request): Response
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $rows = $this->filteredQuery($state)
            ->with([
                'category:id,name',
                'aliases:id,product_id,alias',
            ])
            ->withCount([
                'listings as active_listings_count' => static fn (Builder $query): Builder => $query
                    ->available(),
            ])
            ->get()
            ->map(fn (Product $product): array => [
                $product->id,
                $product->name,
                $product->category?->name,
                $product->brand,
                $product->model,
                $product->variant,
                $product->sku_or_part_number,
                $product->aliases->pluck('alias')->sort()->implode(', '),
                $product->is_active ? 'ACTIVE' : 'INACTIVE',
                $product->active_listings_count,
                strtoupper(str_replace(
                    '_',
                    ' ',
                    $this->embeddingStatus->status($product),
                )),
                $product->updated_at,
            ]);

        return $this->xlsx->download(
            'neomatra-products-'.now()->format('Y-m-d-Hi').'.xlsx',
            'Products',
            [
                'ID', 'Canonical Name', 'Category', 'Brand', 'Model', 'Variant',
                'SKU / Part Number', 'Aliases', 'Status', 'Active Listings',
                'Embedding Status', 'Updated At',
            ],
            $rows,
            [1 => 9, 2 => 34, 3 => 24, 4 => 18, 5 => 20, 6 => 24, 7 => 22, 8 => 48, 9 => 14, 10 => 15, 11 => 20, 12 => 21],
            [2, 6, 7, 8],
        );
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.products.create', [
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function store(StoreProductRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $aliases = $this->parseAliases($data['aliases'] ?? null);
        unset($data['aliases']);
        $data['attributes'] = $this->decodeJson($data['attributes'] ?? null);
        $this->assertNormalizedKeyIsUnique($data);

        $product = DB::transaction(function () use ($data, $aliases): Product {
            $product = Product::query()->create($data);
            $this->syncAliases($product, $aliases);

            return $product;
        });
        $failed = $this->rebuildEmbeddings($product, true);

        return redirect()->route('admin.products.index')
            ->with(
                $failed ? 'warning' : 'success',
                $failed
                    ? __('ui.messages.admin.product.created_embedding_failed')
                    : __('ui.messages.admin.product.created'),
            );
    }

    public function edit(Request $request, Product $product): View
    {
        $this->ensureAdmin($request);
        $product->load('aliases:id,product_id,alias,normalized_alias');

        return view('admin.products.edit', [
            'product' => $product,
            'categories' => $this->categoryOptions(),
        ]);
    }

    public function update(
        UpdateProductRequest $request,
        Product $product,
    ): RedirectResponse {
        $data = $request->validated();
        $aliases = $this->parseAliases($data['aliases'] ?? null);
        unset($data['aliases']);
        $data['attributes'] = $this->decodeJson($data['attributes'] ?? null);
        $this->assertNormalizedKeyIsUnique($data, $product);
        $semanticChanged = $product->fill($data)->isDirty([
            'category_id',
            'name',
            'brand',
            'model',
            'variant',
            'sku_or_part_number',
            'attributes',
        ]);

        DB::transaction(function () use (
            $data,
            $aliases,
            $product,
            $semanticChanged,
        ): void {
            $product->fill($data);

            if ($semanticChanged) {
                $product->embedding_hash = null;
            }

            $product->save();
            $this->syncAliases($product, $aliases);
        });
        $failed = $this->rebuildEmbeddings($product, $semanticChanged);

        return redirect()->route('admin.products.index')
            ->with(
                $failed ? 'warning' : 'success',
                $failed
                    ? __('ui.messages.admin.product.updated_embedding_failed')
                    : __('ui.messages.admin.product.updated'),
            );
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        $this->ensureAdmin($request);
        $product->delete();

        return redirect()->route('admin.products.index')
            ->with('success', __('ui.messages.admin.product.deleted'));
    }

    /** @param array<string, mixed> $data */
    private function assertNormalizedKeyIsUnique(
        array $data,
        ?Product $except = null,
    ): void {
        $key = Product::makeNormalizedKey(
            (string) $data['name'],
            $data['brand'] ?? null,
            $data['model'] ?? null,
            $data['variant'] ?? null,
            $data['sku_or_part_number'] ?? null,
        );
        $exists = Product::query()
            ->where('normalized_key', $key)
            ->when($except !== null, fn (Builder $query): Builder => $query
                ->where('id', '!=', $except->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => __('ui.messages.admin.product.duplicate_identity'),
            ]);
        }
    }

    /** @return list<string> */
    private function parseAliases(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $aliases = preg_split('/[\r\n,]+/u', $value) ?: [];
        $normalized = [];

        foreach ($aliases as $alias) {
            $alias = trim($alias);
            $key = Normalizer::text($alias);

            if ($key !== '') {
                $normalized[$key] = $alias;
            }
        }

        return array_values($normalized);
    }

    /** @param list<string> $aliases */
    private function syncAliases(Product $product, array $aliases): void
    {
        $wanted = [];

        foreach ($aliases as $alias) {
            $wanted[Normalizer::text($alias)] = $alias;
        }

        $existing = $product->aliases()->get()->keyBy('normalized_alias');
        $product->aliases()
            ->whereNotIn('normalized_alias', array_keys($wanted))
            ->delete();

        foreach ($wanted as $normalizedAlias => $alias) {
            $record = $existing->get($normalizedAlias);

            if ($record === null) {
                $product->aliases()->create([
                    'alias' => $alias,
                    'normalized_alias' => $normalizedAlias,
                ]);
            } elseif ($record->alias !== $alias) {
                $record->update(['alias' => $alias]);
            }
        }
    }

    private function rebuildEmbeddings(Product $product, bool $force): bool
    {
        $counts = $this->embeddingService->rebuildProductTree(
            $product,
            $force,
        );

        return $counts[EntityEmbeddingService::FAILED] > 0;
    }

    private function decodeJson(mixed $value): ?array
    {
        return is_string($value) && $value !== ''
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : null;
    }

    /**
     * @return array{
     *     search: string,
     *     categoryId: ?int,
     *     status: string,
     *     listingStatus: string,
     *     embeddingStatus: string,
     *     sort: string,
     *     sortOptions: list<string>
     * }
     */
    private function indexState(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'in:all,active,inactive'],
            'listing_status' => ['nullable', 'in:all,without_active'],
        ]);
        $sortOptions = [...AdminIndexSort::values(), 'embedding'];

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'categoryId' => isset($filters['category_id'])
                ? (int) $filters['category_id']
                : null,
            'status' => (string) ($filters['status'] ?? 'all'),
            'listingStatus' => (string) ($filters['listing_status'] ?? 'all'),
            'embeddingStatus' => $this->embeddingStatus->normalize(
                $request->query('embedding_status'),
            ),
            'sort' => AdminIndexSort::normalize(
                $request->query('sort'),
                $sortOptions,
            ),
            'sortOptions' => $sortOptions,
        ];
    }

    /** @param array<string, mixed> $state */
    private function filteredQuery(array $state): Builder
    {
        $query = Product::query()
            ->when($state['search'] !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$state['search']}%")
                    ->orWhere('brand', 'like', "%{$state['search']}%")
                    ->orWhere('model', 'like', "%{$state['search']}%")
                    ->orWhere('variant', 'like', "%{$state['search']}%")
                    ->orWhere('sku_or_part_number', 'like', "%{$state['search']}%")))
            ->when($state['categoryId'] !== null, fn (Builder $query): Builder => $query
                ->where('category_id', $state['categoryId']))
            ->when($state['status'] === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($state['status'] === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->when(
                $state['listingStatus'] === 'without_active',
                fn (Builder $query): Builder => $query
                    ->whereDoesntHave(
                        'listings',
                        static fn (Builder $listingQuery): Builder => $listingQuery
                            ->available(),
                    ),
            );

        $this->embeddingStatus->applyFilter($query, $state['embeddingStatus']);

        return $state['sort'] === 'embedding'
            ? $this->embeddingStatus->applyOperationalOrder($query)
            : AdminIndexSort::apply(
                $query,
                $state['sort'],
                'name',
                static fn (Builder $query): Builder => $query
                    ->orderByDesc('is_active'),
            );
    }

    /** @param array<string, mixed> $state */
    private function exportQuery(array $state): array
    {
        return [
            'search' => $state['search'],
            'category_id' => $state['categoryId'],
            'status' => $state['status'],
            'listing_status' => $state['listingStatus'],
            'embedding_status' => $state['embeddingStatus'],
            'sort' => $state['sort'],
        ];
    }

    private function categoryOptions()
    {
        return $this->catalogScope->categoryOptions();
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
