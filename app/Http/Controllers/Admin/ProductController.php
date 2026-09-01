<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\Normalizer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Services\Embedding\EntityEmbeddingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ProductController extends Controller
{
    public function __construct(
        private readonly EntityEmbeddingService $embeddingService,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $categoryId = isset($filters['category_id'])
            ? (int) $filters['category_id']
            : null;
        $status = (string) ($filters['status'] ?? 'all');

        $products = Product::query()
            ->with('category:id,name')
            ->withCount(['aliases', 'listings'])
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('variant', 'like', "%{$search}%")
                    ->orWhere('sku_or_part_number', 'like', "%{$search}%")))
            ->when($categoryId !== null, fn (Builder $query): Builder => $query
                ->where('category_id', $categoryId))
            ->when($status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return view('admin.products.index', [
            'products' => $products,
            'categories' => $this->categoryOptions(),
            'search' => $search,
            'categoryId' => $categoryId,
            'status' => $status,
        ]);
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
        $product->refresh()->load('aliases.product');
        $statuses = [
            $this->embeddingService->rebuildProduct($product, $force),
        ];

        foreach ($product->aliases as $alias) {
            $statuses[] = $this->embeddingService->rebuildAlias(
                $alias,
                $force,
            );
        }

        return in_array(EntityEmbeddingService::FAILED, $statuses, true);
    }

    private function decodeJson(mixed $value): ?array
    {
        return is_string($value) && $value !== ''
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : null;
    }

    private function categoryOptions()
    {
        return Category::query()->ordered()->get(['id', 'name', 'is_active']);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
