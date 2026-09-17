<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Models\ListingDiscoveryRunProduct;
use App\Models\Product;
use App\Services\Marketplace\ListingCandidateReviewService;
use App\Services\Marketplace\ListingCoverageService;
use App\Services\Marketplace\ListingDiscoveryService;
use App\Support\AdminIndexSort;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class ListingDiscoveryController extends Controller
{
    public function index(
        Request $request,
        ListingCoverageService $coverage,
    ): View {
        $this->ensureAdmin($request);
        $activeRun = ListingDiscoveryRun::query()
            ->whereIn('status', [
                ListingDiscoveryRun::STATUS_QUEUED,
                ListingDiscoveryRun::STATUS_RUNNING,
            ])
            ->latest('id')
            ->first();
        $activeCurrentProduct = $activeRun?->runProducts()
            ->with('product:id,name')
            ->where('status', ListingDiscoveryRunProduct::STATUS_RUNNING)
            ->latest('id')
            ->first()
            ?->product;
        $runStatuses = [
            ListingDiscoveryRun::STATUS_QUEUED,
            ListingDiscoveryRun::STATUS_RUNNING,
            ListingDiscoveryRun::STATUS_COMPLETED,
            ListingDiscoveryRun::STATUS_PARTIAL,
            ListingDiscoveryRun::STATUS_FAILED,
        ];
        $runProviders = ListingDiscoveryRun::query()
            ->whereNotNull('provider')
            ->distinct()
            ->orderBy('provider')
            ->pluck('provider')
            ->all();
        $runStatus = in_array(
            $request->query('run_status'),
            $runStatuses,
            true,
        ) ? (string) $request->query('run_status') : '';
        $runProvider = in_array(
            $request->query('run_provider'),
            $runProviders,
            true,
        ) ? (string) $request->query('run_provider') : '';
        $runSort = $request->query('run_sort') === 'oldest'
            ? 'oldest'
            : 'newest';
        $runs = ListingDiscoveryRun::query()
            ->withCount([
                'candidates as pending_candidates_count' => static fn ($query) => $query
                    ->where('review_status', ListingCandidate::REVIEW_PENDING),
                'candidates as approved_candidates_count' => static fn ($query) => $query
                    ->where('review_status', ListingCandidate::REVIEW_APPROVED),
            ])
            ->when(
                $runStatus !== '',
                static fn (Builder $query): Builder => $query->where(
                    'status',
                    $runStatus,
                ),
            )
            ->when(
                $runProvider !== '',
                static fn (Builder $query): Builder => $query->where(
                    'provider',
                    $runProvider,
                ),
            )
            ->orderBy('id', $runSort === 'oldest' ? 'asc' : 'desc')
            ->limit(10)
            ->get();

        return view('admin.listings.discovery', [
            'settings' => [
                'enabled' => (bool) config('listing-discovery.enabled', false),
                'provider' => ucfirst((string) config(
                    'listing-discovery.provider',
                    'tavily',
                )),
                'search' => __('ui.admin_pages.listing_discovery.search_value', [
                    'depth' => ucfirst((string) config(
                        'listing-discovery.tavily.search_depth',
                        'basic',
                    )),
                    'count' => (int) config(
                        'listing-discovery.tavily.search_max_results',
                        20,
                    ),
                ]),
                'extraction' => (bool) config(
                    'listing-discovery.tavily.extract_enabled',
                    true,
                ) ? __('ui.admin_pages.listing_discovery.extract_fallback_value', [
                    'count' => (int) config(
                        'listing-discovery.tavily.extract_max_urls_per_product',
                        3,
                    ),
                ]) : __('ui.admin_pages.listing_discovery.disabled'),
                'probe' => (bool) config(
                    'listing-discovery.http_probe.enabled',
                    true,
                ),
                'platform_fallback' => (bool) config(
                    'listing-discovery.platform_fallback.enabled',
                    true,
                ),
                'search_cache_hours' => (int) config(
                    'listing-discovery.search_cache_hours',
                    24,
                ),
                'region' => (string) config('listing-discovery.region', 'Indonesia'),
                'minimum' => (int) config(
                    'listing-discovery.minimum_active_listings',
                    2,
                ),
                'target' => (int) config(
                    'listing-discovery.target_active_listings',
                    3,
                ),
                'max_products' => (int) config(
                    'listing-discovery.max_products_per_run',
                ),
                'retry_cooldown_hours' => (int) config(
                    'listing-discovery.retry_cooldown_hours',
                    24,
                ),
                'max_new' => (int) config(
                    'listing-discovery.max_new_listings_per_product',
                    3,
                ),
                'max_per_platform' => (int) config(
                    'listing-discovery.max_per_platform_per_product',
                    2,
                ),
                'approval' => (bool) config(
                    'listing-discovery.require_approval',
                    true,
                ),
                'auto_import' => (bool) config(
                    'listing-discovery.auto_import',
                    false,
                ),
                'schedule_enabled' => (bool) config(
                    'listing-discovery.schedule.enabled',
                    false,
                ),
                'schedule_cron' => (string) config(
                    'listing-discovery.schedule.cron',
                    '0 2 * * *',
                ),
            ],
            'runs' => $runs,
            'candidates' => ListingCandidate::query()
                ->with([
                    'run:id,status',
                    'targetProduct' => static fn ($query) => $query
                        ->select(['id', 'name'])
                        ->withCount([
                            'listings as available_listings_count' => static fn (Builder $listingQuery): Builder => $listingQuery
                                ->available(),
                        ]),
                ])
                ->where('review_status', ListingCandidate::REVIEW_PENDING)
                ->where('validation_status', ListingCandidate::VALIDATION_VALID)
                ->whereHas('targetProduct')
                ->latest('id')
                ->limit(50)
                ->get(),
            'activeRun' => $activeRun,
            'activeCurrentProduct' => $activeCurrentProduct,
            'catalogMetrics' => $coverage->metrics(),
            'coverageTarget' => $coverage->target(),
            'runFilters' => [
                'status' => $runStatus,
                'provider' => $runProvider,
                'sort' => $runSort,
            ],
            'runStatuses' => $runStatuses,
            'runProviders' => $runProviders,
        ]);
    }

    public function progress(
        Request $request,
        ListingDiscoveryRun $run,
    ): JsonResponse {
        $this->ensureAdmin($request);

        return response()->json($this->progressPayload($run->refresh()));
    }

    public function show(
        Request $request,
        ListingDiscoveryRun $run,
        ListingCoverageService $coverage,
    ): View {
        $this->ensureAdmin($request);
        $search = mb_substr(trim((string) $request->query('search')), 0, 100);
        $resultStatuses = [
            ListingDiscoveryRunProduct::STATUS_QUEUED,
            ListingDiscoveryRunProduct::STATUS_RUNNING,
            ListingDiscoveryRunProduct::STATUS_NO_RESULT,
            ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND,
            ListingDiscoveryRunProduct::STATUS_PARTIAL,
            ListingDiscoveryRunProduct::STATUS_FAILED,
            ListingDiscoveryRunProduct::STATUS_SKIPPED,
            ListingDiscoveryRunProduct::STATUS_COMPLETED,
        ];
        $resultStatus = in_array(
            $request->query('result_status'),
            $resultStatuses,
            true,
        ) ? (string) $request->query('result_status') : '';
        $reviewStatuses = ['pending', 'approved', 'rejected', 'no_candidates'];
        $reviewStatus = in_array(
            $request->query('review_status'),
            $reviewStatuses,
            true,
        ) ? (string) $request->query('review_status') : '';
        $categoryId = max(0, (int) $request->query('category'));
        $sorts = ['newest', 'oldest', 'product_az', 'product_za'];
        $sort = in_array($request->query('sort'), $sorts, true)
            ? (string) $request->query('sort')
            : 'newest';
        $runProductQuery = $run->runProducts()
            ->with([
                'product' => static fn ($query) => $query
                    ->select(['id', 'category_id', 'name', 'brand', 'model'])
                    ->with('category:id,name')
                    ->withCount([
                        'listings as available_listings_count' => static fn (Builder $listingQuery): Builder => $listingQuery
                            ->available(),
                    ]),
            ])
            ->when(
                $search !== '',
                static fn (Builder $query): Builder => $query->whereHas(
                    'product',
                    static fn (Builder $productQuery): Builder => $productQuery
                        ->where(static function (Builder $identityQuery) use ($search): void {
                            $identityQuery
                                ->where('name', 'like', '%'.$search.'%')
                                ->orWhere('brand', 'like', '%'.$search.'%')
                                ->orWhere('model', 'like', '%'.$search.'%');
                        }),
                ),
            )
            ->when(
                $resultStatus !== '',
                static fn (Builder $query): Builder => $query->where(
                    'status',
                    $resultStatus,
                ),
            )
            ->when(
                $categoryId > 0,
                static fn (Builder $query): Builder => $query->whereHas(
                    'product',
                    static fn (Builder $productQuery): Builder => $productQuery
                        ->where('category_id', $categoryId),
                ),
            );

        if ($reviewStatus !== '') {
            $withoutCandidates = $reviewStatus === 'no_candidates';
            $reviewStatusesForQuery = $reviewStatus === 'rejected'
                ? [
                    ListingCandidate::REVIEW_REJECTED,
                    ListingCandidate::REVIEW_INVALID,
                ]
                : [$reviewStatus];
            $candidateConstraint = static function ($candidateQuery) use (
                $run,
                $withoutCandidates,
                $reviewStatusesForQuery,
            ): void {
                $candidateQuery
                    ->selectRaw('1')
                    ->from('listing_candidates')
                    ->whereColumn(
                        'listing_candidates.target_product_id',
                        'listing_discovery_run_products.product_id',
                    )
                    ->where('listing_candidates.listing_discovery_run_id', $run->id);

                if (! $withoutCandidates) {
                    $candidateQuery->whereIn(
                        'listing_candidates.review_status',
                        $reviewStatusesForQuery,
                    );
                }
            };

            if ($reviewStatus === 'no_candidates') {
                $runProductQuery->whereNotExists($candidateConstraint);
            } else {
                $runProductQuery->whereExists($candidateConstraint);
            }
        }

        $productName = Product::query()
            ->select('name')
            ->whereColumn(
                'products.id',
                'listing_discovery_run_products.product_id',
            )
            ->limit(1);

        match ($sort) {
            'oldest' => $runProductQuery
                ->orderByRaw('COALESCE(finished_at, started_at, created_at) ASC')
                ->orderBy('id'),
            'product_az' => $runProductQuery
                ->orderBy($productName)
                ->orderBy('id'),
            'product_za' => $runProductQuery
                ->orderByDesc($productName)
                ->orderByDesc('id'),
            default => $runProductQuery
                ->orderByRaw('COALESCE(finished_at, started_at, created_at) DESC')
                ->orderByDesc('id'),
        };
        $runProducts = $runProductQuery
            ->paginate(AdminIndexSort::PER_PAGE)
            ->withQueryString();
        $run->load([
            'candidates:id,listing_discovery_run_id,target_product_id,review_status,validation_status',
        ]);
        $categories = Category::query()
            ->whereIn(
                'id',
                Product::query()
                    ->select('category_id')
                    ->whereIn(
                        'id',
                        $run->runProducts()->select('product_id'),
                    ),
            )
            ->orderBy('name')
            ->get(['id', 'name']);
        $candidateReviews = $run->candidates
            ->groupBy('target_product_id')
            ->map(static fn ($candidates): array => [
                'pending' => $candidates->where(
                    'review_status',
                    ListingCandidate::REVIEW_PENDING,
                )->count(),
                'approved' => $candidates->where(
                    'review_status',
                    ListingCandidate::REVIEW_APPROVED,
                )->count(),
                'rejected' => $candidates->whereIn('review_status', [
                    ListingCandidate::REVIEW_REJECTED,
                    ListingCandidate::REVIEW_INVALID,
                ])->count(),
            ]);

        return view('admin.listings.discovery-show', [
            'run' => $run,
            'runProducts' => $runProducts,
            'candidateReviews' => $candidateReviews,
            'coverageTarget' => $coverage->target(),
            'categories' => $categories,
            'filters' => [
                'search' => $search,
                'result_status' => $resultStatus,
                'review_status' => $reviewStatus,
                'category' => $categoryId,
                'sort' => $sort,
            ],
            'resultStatuses' => $resultStatuses,
            'hasHistoricalUnresolvedProducts' => ! $run->isActive()
                && $run->runProducts()
                    ->whereIn('status', [
                        ListingDiscoveryRunProduct::STATUS_QUEUED,
                        ListingDiscoveryRunProduct::STATUS_RUNNING,
                    ])
                    ->exists(),
        ]);
    }

    public function start(
        Request $request,
        ListingDiscoveryService $discovery,
    ): RedirectResponse {
        $this->ensureAdmin($request);

        if (! (bool) config('listing-discovery.enabled', false)) {
            return back()->with(
                'warning',
                __('ui.messages.admin.listing_discovery.disabled'),
            );
        }

        $run = $discovery->queue((int) $request->user()->id);

        return back()->with(
            'success',
            __('ui.messages.admin.listing_discovery.queued', ['id' => $run->id]),
        );
    }

    public function approve(
        Request $request,
        ListingCandidate $candidate,
        ListingCandidateReviewService $review,
    ): RedirectResponse {
        $this->ensureAdmin($request);

        try {
            $listing = $review->approve($candidate, $request->user());
        } catch (RuntimeException) {
            return back()->with(
                'error',
                __('ui.messages.admin.listing_discovery.review_failed'),
            );
        }

        return back()->with(
            'success',
            __('ui.messages.admin.listing_discovery.approved', [
                'id' => $listing->id,
            ]),
        );
    }

    public function approveAll(
        Request $request,
        ListingCandidateReviewService $review,
    ): RedirectResponse {
        $this->ensureAdmin($request);
        $validated = $request->validate([
            'candidate_ids' => ['required', 'array', 'min:1', 'max:50'],
            'candidate_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $summary = $review->approveMany(
            array_map('intval', $validated['candidate_ids']),
            $request->user(),
        );
        $message = __('ui.messages.admin.listing_discovery.bulk_approved', $summary);
        $hasIssues = $summary['duplicate'] > 0
            || $summary['validation_failed'] > 0
            || $summary['skipped'] > 0
            || $summary['failed'] > 0;

        return back()->with($hasIssues ? 'warning' : 'success', $message);
    }

    public function reject(
        Request $request,
        ListingCandidate $candidate,
        ListingCandidateReviewService $review,
    ): RedirectResponse {
        $this->ensureAdmin($request);

        try {
            $review->reject($candidate, $request->user());
        } catch (RuntimeException) {
            return back()->with(
                'error',
                __('ui.messages.admin.listing_discovery.review_failed'),
            );
        }

        return back()->with(
            'success',
            __('ui.messages.admin.listing_discovery.rejected'),
        );
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }

    /** @return array<string, mixed> */
    private function progressPayload(ListingDiscoveryRun $run): array
    {
        $current = $run->runProducts()
            ->with('product:id,name')
            ->where('status', ListingDiscoveryRunProduct::STATUS_RUNNING)
            ->latest('id')
            ->first();
        $targeted = max(0, (int) $run->products_targeted);
        $checked = min($targeted, max(0, (int) $run->products_checked));
        $terminal = ! $run->isActive();

        return [
            'id' => (int) $run->id,
            'status' => $run->status,
            'products_targeted' => $targeted,
            'products_checked' => $checked,
            'products_remaining' => max(0, $targeted - $checked),
            'percent' => $targeted === 0
                ? ($terminal ? 100 : 0)
                : (int) floor(($checked / $targeted) * 100),
            'current_product' => $current?->product === null ? null : [
                'id' => (int) $current->product->id,
                'name' => $current->product->name,
            ],
            'candidates_found' => (int) $run->candidates_found,
            'valid_candidates' => (int) $run->valid_candidates,
            'rejected_candidates' => (int) $run->rejected_candidates,
            'provider_failures' => (int) $run->provider_failures,
            'terminal' => $terminal,
            'completion_message' => $terminal
                ? __('ui.admin_pages.listing_discovery.completed_message', [
                    'checked' => $checked,
                    'valid' => (int) $run->valid_candidates,
                ])
                : null,
        ];
    }
}
