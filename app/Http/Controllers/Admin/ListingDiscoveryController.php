<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Services\Marketplace\ListingCandidateReviewService;
use App\Services\Marketplace\ListingDiscoveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

final class ListingDiscoveryController extends Controller
{
    public function index(Request $request): View
    {
        $this->ensureAdmin($request);

        return view('admin.listings.discovery', [
            'settings' => [
                'enabled' => (bool) config('listing-discovery.enabled', false),
                'provider' => (string) config('listing-discovery.provider', 'gemini'),
                'model' => (string) config('listing-discovery.model', ''),
                'grounding' => (bool) config(
                    'listing-discovery.web_grounding_enabled',
                    true,
                ),
                'region' => (string) config('listing-discovery.region', 'Indonesia'),
                'minimum' => (int) config(
                    'listing-discovery.minimum_active_listings',
                    2,
                ),
                'target' => (int) config(
                    'listing-discovery.target_active_listings',
                    5,
                ),
                'max_products' => (int) config(
                    'listing-discovery.max_products_per_run',
                    10,
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
            'runs' => ListingDiscoveryRun::query()
                ->withCount([
                    'candidates as pending_candidates_count' => static fn ($query) => $query
                        ->where('review_status', ListingCandidate::REVIEW_PENDING),
                    'candidates as approved_candidates_count' => static fn ($query) => $query
                        ->where('review_status', ListingCandidate::REVIEW_APPROVED),
                ])
                ->latest('id')
                ->limit(10)
                ->get(),
            'candidates' => ListingCandidate::query()
                ->with(['run:id,status', 'targetProduct:id,name'])
                ->where('review_status', ListingCandidate::REVIEW_PENDING)
                ->latest('id')
                ->limit(50)
                ->get(),
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
}
