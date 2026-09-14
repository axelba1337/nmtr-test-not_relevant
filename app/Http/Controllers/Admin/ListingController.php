<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreListingRequest;
use App\Http\Requests\Admin\UpdateListingRequest;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Export\AdminXlsxExporter;
use App\Services\Marketplace\ListingCoverageService;
use App\Services\Neomatra\ListingFingerprintService;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

final class ListingController extends Controller
{
    public function __construct(
        private readonly ListingFingerprintService $fingerprints,
        private readonly ListingCoverageService $coverage,
        private readonly AdminXlsxExporter $xlsx,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $listings = $this->filteredQuery($state)
            ->with(['platform:id,name', 'product:id,name'])
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();

        return view('admin.listings.index', [
            'listings' => $listings,
            'platforms' => $this->platformOptions(),
            ...$state,
            'exportQuery' => $this->exportQuery($state),
            'coverage' => $this->coverage->metrics(),
            'listingTarget' => $this->coverage->target(),
        ]);
    }

    public function export(Request $request): Response
    {
        $this->ensureAdmin($request);
        $state = $this->indexState($request);
        $rows = $this->filteredQuery($state)
            ->with(['platform:id,name', 'product:id,name'])
            ->get()
            ->map(static fn (Listing $listing): array => [
                $listing->id,
                $listing->external_listing_id,
                $listing->title,
                $listing->platform?->name,
                $listing->product?->name,
                $listing->price,
                $listing->currency,
                $listing->condition,
                $listing->seller_name,
                $listing->location,
                $listing->stock_status,
                $listing->is_active ? 'ACTIVE' : 'INACTIVE',
                $listing->product_url,
                $listing->source_published_at,
                $listing->last_synced_at ?? $listing->updated_at,
            ]);

        return $this->xlsx->download(
            'neomatra-listings-'.now()->format('Y-m-d-Hi').'.xlsx',
            'Listings',
            [
                'ID', 'External Listing ID', 'Title', 'Platform',
                'Mapped Product', 'Price', 'Currency', 'Condition', 'Seller',
                'Location', 'Stock Status', 'Status', 'Product URL',
                'Source Published At', 'Last Update',
            ],
            $rows,
            [1 => 9, 2 => 24, 3 => 44, 4 => 22, 5 => 34, 6 => 16, 7 => 12, 8 => 16, 9 => 26, 10 => 24, 11 => 16, 12 => 14, 13 => 58, 14 => 21, 15 => 21],
            [2, 3, 5, 9, 10, 13],
        );
    }

    public function unmatched(Request $request): View
    {
        $request->merge([
            'mapping_status' => 'unmatched',
            'status' => $request->input('status', 'active'),
        ]);

        return $this->index($request);
    }

    public function create(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.listings.create', $this->formOptions());
    }

    public function store(StoreListingRequest $request): RedirectResponse
    {
        $data = $this->prepareData($request->validated());
        $this->assertFingerprintIsUnique($data['source_fingerprint']);
        Listing::query()->create($data);

        return redirect()->route('admin.listings.index')
            ->with('success', __('ui.messages.admin.listing.created'));
    }

    public function edit(Request $request, Listing $listing): View
    {
        $this->ensureAdmin($request);

        return view('admin.listings.edit', [
            ...$this->formOptions(),
            'listing' => $listing,
        ]);
    }

    public function update(
        UpdateListingRequest $request,
        Listing $listing,
    ): RedirectResponse {
        $data = $this->prepareData($request->validated());
        $this->assertFingerprintIsUnique(
            $data['source_fingerprint'],
            $listing,
        );
        $listing->update($data);

        return redirect()->route('admin.listings.index')
            ->with('success', __('ui.messages.admin.listing.updated'));
    }

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $this->ensureAdmin($request);
        $listing->delete();

        return redirect()->route('admin.listings.index')
            ->with('success', __('ui.messages.admin.listing.deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareData(array $data): array
    {
        $data['condition_source'] = Listing::CONDITION_SOURCE_SELLER;
        $data['raw_payload'] = is_string($data['raw_payload'] ?? null)
            ? json_decode($data['raw_payload'], true, 512, JSON_THROW_ON_ERROR)
            : null;
        $platformKey = Platform::query()
            ->whereKey((int) $data['platform_id'])
            ->value('slug') ?? (string) $data['platform_id'];
        $data['source_fingerprint'] = $this->fingerprints->make(
            $platformKey,
            $data['external_listing_id'] ?? null,
            (string) $data['product_url'],
            (string) $data['title'],
            (int) $data['price'],
            $data['seller_name'] ?? null,
        );

        return $data;
    }

    private function assertFingerprintIsUnique(
        string $fingerprint,
        ?Listing $except = null,
    ): void {
        $exists = Listing::query()
            ->where('source_fingerprint', $fingerprint)
            ->when($except !== null, fn (Builder $query): Builder => $query
                ->where('id', '!=', $except->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'external_listing_id' => __('ui.messages.admin.listing.duplicate'),
            ]);
        }
    }

    /**
     * @return array{
     *     search: string,
     *     platformId: ?int,
     *     condition: string,
     *     stockStatus: string,
     *     status: string,
     *     mappingStatus: string,
     *     sort: string
     * }
     */
    private function indexState(Request $request): array
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'platform_id' => ['nullable', 'integer', 'exists:platforms,id'],
            'condition' => ['nullable', 'in:'.implode(',', Listing::publicConditionFilters())],
            'stock_status' => ['nullable', 'in:'.implode(',', Listing::stockStatuses())],
            'status' => ['nullable', 'in:all,active,inactive'],
            'mapping_status' => ['nullable', 'in:all,matched,unmatched'],
        ]);

        return [
            'search' => trim((string) ($filters['search'] ?? '')),
            'platformId' => isset($filters['platform_id'])
                ? (int) $filters['platform_id']
                : null,
            'condition' => (string) ($filters['condition'] ?? ''),
            'stockStatus' => (string) ($filters['stock_status'] ?? ''),
            'status' => (string) ($filters['status'] ?? 'all'),
            'mappingStatus' => (string) ($filters['mapping_status'] ?? 'all'),
            'sort' => AdminIndexSort::normalize($request->query('sort')),
        ];
    }

    /** @param array<string, mixed> $state */
    private function filteredQuery(array $state): Builder
    {
        $query = Listing::query()
            ->when($state['search'] !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('title', 'like', "%{$state['search']}%")
                    ->orWhere('external_listing_id', 'like', "%{$state['search']}%")
                    ->orWhere('seller_name', 'like', "%{$state['search']}%")
                    ->orWhere('location', 'like', "%{$state['search']}%")))
            ->when($state['platformId'] !== null, fn (Builder $query): Builder => $query
                ->where('platform_id', $state['platformId']))
            ->when($state['condition'] !== '', fn (Builder $query): Builder => $query
                ->whereIn(
                    'condition',
                    Listing::conditionValuesForSearch($state['condition']),
                ))
            ->when($state['stockStatus'] !== '', fn (Builder $query): Builder => $query
                ->where('stock_status', $state['stockStatus']))
            ->when($state['status'] === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($state['status'] === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->when($state['mappingStatus'] === 'matched', fn (Builder $query): Builder => $query
                ->whereNotNull('product_id'))
            ->when($state['mappingStatus'] === 'unmatched', fn (Builder $query): Builder => $query
                ->whereNull('product_id'));

        return AdminIndexSort::apply(
            $query,
            $state['sort'],
            'title',
            static fn (Builder $query): Builder => $query
                ->orderByDesc('is_active')
                ->orderBy('stock_status'),
        );
    }

    /** @param array<string, mixed> $state */
    private function exportQuery(array $state): array
    {
        return [
            'search' => $state['search'],
            'platform_id' => $state['platformId'],
            'condition' => $state['condition'],
            'stock_status' => $state['stockStatus'],
            'status' => $state['status'],
            'mapping_status' => $state['mappingStatus'],
            'sort' => $state['sort'],
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        return [
            'platforms' => $this->platformOptions(),
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name', 'brand', 'model', 'variant', 'is_active']),
        ];
    }

    private function platformOptions()
    {
        return Platform::query()
            ->whereIn('slug', Platform::MVP_SLUGS)
            ->orderBy('name')
            ->get(['id', 'name', 'is_active']);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
