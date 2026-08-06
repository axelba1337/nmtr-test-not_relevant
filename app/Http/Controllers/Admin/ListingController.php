<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreListingRequest;
use App\Http\Requests\Admin\UpdateListingRequest;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Neomatra\ListingFingerprintService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class ListingController extends Controller
{
    public function __construct(
        private readonly ListingFingerprintService $fingerprints,
    ) {}

    public function index(Request $request): View
    {
        $this->ensureAdmin($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'platform_id' => ['nullable', 'integer', 'exists:platforms,id'],
            'condition' => ['nullable', 'in:'.implode(',', Listing::conditions())],
            'stock_status' => ['nullable', 'in:'.implode(',', Listing::stockStatuses())],
            'status' => ['nullable', 'in:all,active,inactive'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));
        $platformId = isset($filters['platform_id'])
            ? (int) $filters['platform_id']
            : null;
        $condition = (string) ($filters['condition'] ?? '');
        $stockStatus = (string) ($filters['stock_status'] ?? '');
        $status = (string) ($filters['status'] ?? 'all');

        $listings = Listing::query()
            ->with(['platform:id,name', 'product:id,name'])
            ->when($search !== '', fn (Builder $query): Builder => $query
                ->where(fn (Builder $nested): Builder => $nested
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('external_listing_id', 'like', "%{$search}%")
                    ->orWhere('seller_name', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")))
            ->when($platformId !== null, fn (Builder $query): Builder => $query
                ->where('platform_id', $platformId))
            ->when($condition !== '', fn (Builder $query): Builder => $query
                ->where('condition', $condition))
            ->when($stockStatus !== '', fn (Builder $query): Builder => $query
                ->where('stock_status', $stockStatus))
            ->when($status === 'active', fn (Builder $query): Builder => $query
                ->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query): Builder => $query
                ->where('is_active', false))
            ->latest('updated_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.listings.index', [
            'listings' => $listings,
            'platforms' => $this->platformOptions(),
            'search' => $search,
            'platformId' => $platformId,
            'condition' => $condition,
            'stockStatus' => $stockStatus,
            'status' => $status,
        ]);
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
            ->with('success', 'Listing berhasil ditambahkan.');
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
            ->with('success', 'Listing berhasil diperbarui.');
    }

    public function destroy(Request $request, Listing $listing): RedirectResponse
    {
        $this->ensureAdmin($request);
        $listing->delete();

        return redirect()->route('admin.listings.index')
            ->with('success', 'Listing berhasil dihapus.');
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
                'external_listing_id' => 'Listing dari sumber yang sama sudah tersedia.',
            ]);
        }
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
        return Platform::query()->orderBy('name')->get([
            'id',
            'name',
            'is_active',
        ]);
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }
}
