<?php

namespace Tests\Feature;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Data\Marketplace\ListingDiscoveryResult;
use App\Jobs\DiscoverListingsJob;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Models\ListingDiscoveryRunProduct;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Marketplace\GeminiListingDiscoveryProvider;
use App\Services\Marketplace\ListingCandidateReviewService;
use App\Services\Marketplace\ListingDiscoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ListingDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->platforms();
    }

    public function test_disabled_discovery_page_load_makes_no_request(): void
    {
        config(['listing-discovery.enabled' => false]);
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertSeeText('Automatic Listing');
        $this->actingAs($admin)
            ->post(route('admin.listings.discovery.start'))
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('listing_discovery_runs', 0);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_enabled_admin_action_only_queues_a_background_job(): void
    {
        config(['listing-discovery.enabled' => true]);
        Queue::fake();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.listings.discovery.start'))
            ->assertSessionHas('success');

        $run = ListingDiscoveryRun::query()->sole();
        $this->assertSame(ListingDiscoveryRun::STATUS_QUEUED, $run->status);
        $this->assertSame($admin->id, $run->created_by);
        Queue::assertPushed(DiscoverListingsJob::class, static fn ($job): bool => $job->runId === $run->id && $job->queue === 'listing-discovery');
        Http::assertNothingSent();
    }

    public function test_queue_freezes_exactly_one_hundred_targets_and_all_children_finish(): void
    {
        config([
            'listing-discovery.enabled' => true,
            'listing-discovery.max_products_per_run' => 100,
        ]);
        Queue::fake();
        $category = $this->category();

        foreach (range(1, 102) as $index) {
            $this->product($category, 'Batch Product '.str_pad((string) $index, 3, '0', STR_PAD_LEFT), null);
        }

        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success([], []),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $service = app(ListingDiscoveryService::class);
        $first = $service->queue($this->admin()->id);
        $second = $service->queue($this->admin()->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(100, $first->products_targeted);
        $this->assertSame(100, $first->runProducts()->count());
        Queue::assertPushed(DiscoverListingsJob::class, 1);

        $service->execute($first);
        $first->refresh();

        $this->assertSame(ListingDiscoveryRun::STATUS_COMPLETED, $first->status);
        $this->assertSame(100, $first->products_checked);
        $this->assertCount(100, $provider->productIds);
        $this->assertSame([3], array_values(array_unique($provider->maximumCandidates)));
        $this->assertSame(
            0,
            $first->runProducts()
                ->whereIn('status', [
                    ListingDiscoveryRunProduct::STATUS_QUEUED,
                    ListingDiscoveryRunProduct::STATUS_RUNNING,
                ])
                ->count(),
        );
        Http::assertNothingSent();
    }

    public function test_queue_uses_actual_target_count_when_fewer_products_are_eligible(): void
    {
        config([
            'listing-discovery.enabled' => true,
            'listing-discovery.max_products_per_run' => 100,
        ]);
        Queue::fake();
        $category = $this->category();
        $this->product($category, 'Only Eligible A', null);
        $this->product($category, 'Only Eligible B', null);

        $run = app(ListingDiscoveryService::class)->queue($this->admin()->id);

        $this->assertSame(2, $run->products_targeted);
        $this->assertSame(2, $run->runProducts()->count());
        Http::assertNothingSent();
    }

    public function test_target_cap_is_respected_with_one_provider_call_per_product(): void
    {
        config([
            'listing-discovery.target_active_listings' => 5,
            'listing-discovery.max_products_per_run' => 2,
        ]);
        $category = $this->category();
        $first = $this->product($category, 'Target Zero A', null);
        $second = $this->product($category, 'Target Zero B', null);
        $third = $this->product($category, 'Target Zero C', null);
        $inactive = $this->product($category, 'Inactive Target', null, false);
        $sufficient = $this->product($category, 'Covered Product', null);
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();

        for ($index = 1; $index <= 5; $index++) {
            $this->listing($platform, $sufficient, 'covered-'.$index);
        }

        $provider = new RecordingDiscoveryProvider(
            static fn (Product $product): ListingDiscoveryResult => ListingDiscoveryResult::success([], [[
                'title' => 'Grounded source',
                'url' => 'https://www.google.com/search?q='.rawurlencode($product->name),
            ]]),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();

        app(ListingDiscoveryService::class)->execute($run);
        $run->refresh();

        $this->assertSame(ListingDiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->products_checked);
        $this->assertSame(2, $run->products_targeted);
        $this->assertSame([3, 3], $provider->maximumCandidates);
        $this->assertSame(
            2,
            $run->runProducts()
                ->where('status', ListingDiscoveryRunProduct::STATUS_NO_RESULT)
                ->count(),
        );
        $this->assertSame(
            0,
            $run->runProducts()
                ->whereIn('status', [
                    ListingDiscoveryRunProduct::STATUS_QUEUED,
                    ListingDiscoveryRunProduct::STATUS_RUNNING,
                ])
                ->count(),
        );
        $this->assertTrue(
            $run->runProducts()->get()->every(
                static fn (ListingDiscoveryRunProduct $runProduct): bool => $runProduct->started_at !== null
                    && $runProduct->finished_at !== null,
            ),
        );
        $this->assertSame([$first->id, $second->id], $provider->productIds);
        $this->assertNotContains($third->id, $provider->productIds);
        $this->assertNotContains($inactive->id, $provider->productIds);
        $this->assertNotContains($sufficient->id, $provider->productIds);
        Http::assertNothingSent();
    }

    public function test_candidates_are_staged_and_invalid_missing_or_duplicate_urls_are_rejected(): void
    {
        config([
            'listing-discovery.target_active_listings' => 5,
            'listing-discovery.max_products_per_run' => 1,
            'listing-discovery.max_new_listings_per_product' => 5,
            'listing-discovery.max_per_platform_per_product' => 5,
        ]);
        $product = $this->product($this->category(), 'NVIDIA GeForce RTX 3060', 'RTX 3060');
        $valid = $this->candidate(
            'DISCOVERY-VALID-1',
            'https://www.olx.co.id/item/rtx-3060-iid-551100',
        );
        $duplicate = $valid;
        $duplicate['external_listing_id'] = 'DISCOVERY-DUPLICATE';
        $duplicate['product_url'] .= '?ref=duplicate';
        $unsupported = $this->candidate(
            'DISCOVERY-UNSUPPORTED',
            'https://example.test/listing/1',
        );
        $unsupported['title'] = 'Invalid Unsupported Candidate';
        $missingPrice = $this->candidate(
            'DISCOVERY-MISSING-PRICE',
            'https://www.olx.co.id/item/rtx-3060-iid-551101',
        );
        $missingPrice['price'] = null;
        $missingPrice['title'] = 'Invalid Missing Price Candidate';
        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success(
                [$valid, $duplicate, $unsupported, $missingPrice],
                [['title' => 'Google grounded result', 'url' => 'https://www.google.com/search?q=rtx+3060']],
            ),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();
        $productsBefore = Product::query()->count();

        app(ListingDiscoveryService::class)->execute($run);
        $run->refresh();

        $this->assertSame(ListingDiscoveryRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, $run->products_checked);
        $this->assertSame(4, $run->candidates_found);
        $this->assertSame(1, $run->valid_candidates);
        $this->assertSame(3, $run->rejected_candidates);
        $this->assertDatabaseHas('listing_discovery_run_products', [
            'listing_discovery_run_id' => $run->id,
            'product_id' => $product->id,
            'status' => ListingDiscoveryRunProduct::STATUS_PARTIAL,
            'candidates_found' => 4,
            'valid_candidates' => 1,
            'rejected_candidates' => 3,
        ]);
        $this->assertDatabaseCount('listing_candidates', 4);
        $this->assertDatabaseHas('listing_candidates', [
            'target_product_id' => $product->id,
            'validation_status' => ListingCandidate::VALIDATION_VALID,
            'review_status' => ListingCandidate::REVIEW_PENDING,
        ]);
        $this->assertSame(
            3,
            ListingCandidate::query()
                ->where('validation_status', ListingCandidate::VALIDATION_INVALID)
                ->where('review_status', ListingCandidate::REVIEW_INVALID)
                ->count(),
        );
        $this->assertDatabaseCount('listings', 0);
        $this->assertSame($productsBefore, Product::query()->count());
        $this->assertDatabaseCount('product_aliases', 0);
        $this->actingAs($this->admin())
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertSeeText('VGA NVIDIA RTX 3060 12GB')
            ->assertDontSeeText('Invalid Unsupported Candidate')
            ->assertDontSeeText('Invalid Missing Price Candidate');
    }

    public function test_provider_failure_is_reported_without_creating_candidates(): void
    {
        config(['listing-discovery.max_products_per_run' => 1]);
        $this->product($this->category(), 'Failure Target', null);
        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::failed(
                'quota_or_rate_limit',
                'Provider quota is unavailable.',
            ),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();

        app(ListingDiscoveryService::class)->execute($run);
        $run->refresh();

        $this->assertSame(ListingDiscoveryRun::STATUS_FAILED, $run->status);
        $this->assertSame(1, $run->provider_failures);
        $this->assertSame('quota_or_rate_limit', $run->error_code);
        $this->assertSame('Provider quota is unavailable.', $run->error_message);
        $this->assertDatabaseHas('listing_discovery_run_products', [
            'listing_discovery_run_id' => $run->id,
            'status' => ListingDiscoveryRunProduct::STATUS_FAILED,
            'provider_failed' => true,
            'provider_error_code' => 'quota_or_rate_limit',
        ]);
        $this->assertDatabaseCount('listing_candidates', 0);
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_candidate_approval_uses_ingestion_and_rejection_creates_no_listing_or_catalog_data(): void
    {
        config([
            'listing-discovery.target_active_listings' => 5,
            'listing-discovery.max_products_per_run' => 1,
            'listing-discovery.max_new_listings_per_product' => 2,
            'listing-discovery.max_per_platform_per_product' => 2,
        ]);
        $product = $this->product($this->category(), 'NVIDIA GeForce RTX 3060', 'RTX 3060');
        $provider = new RecordingDiscoveryProvider(
            fn (): ListingDiscoveryResult => ListingDiscoveryResult::success([
                $this->candidate('APPROVE-1', 'https://www.olx.co.id/item/rtx-3060-iid-661100'),
                $this->candidate('REJECT-1', 'https://www.olx.co.id/item/rtx-3060-iid-661101'),
            ], [['title' => 'Grounding', 'url' => 'https://www.google.com/search?q=rtx+3060']]),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();
        app(ListingDiscoveryService::class)->execute($run);
        $candidates = ListingCandidate::query()->orderBy('id')->get();
        $admin = $this->admin();
        $productsBefore = Product::query()->count();
        $aliasesBefore = $product->aliases()->count();

        $listing = app(ListingCandidateReviewService::class)
            ->approve($candidates[0], $admin);
        app(ListingCandidateReviewService::class)
            ->reject($candidates[1], $admin);

        $this->assertSame(ListingCandidate::REVIEW_APPROVED, $candidates[0]->fresh()?->review_status);
        $this->assertSame($listing->id, $candidates[0]->fresh()?->listing_id);
        $this->assertSame(ListingCandidate::REVIEW_REJECTED, $candidates[1]->fresh()?->review_status);
        $this->assertDatabaseCount('listings', 1);
        $this->assertDatabaseHas('listings', [
            'id' => $listing->id,
            'product_id' => $product->id,
            'external_listing_id' => 'APPROVE-1',
        ]);
        $this->assertSame('manual-import', $listing->raw_payload['connector']);
        $this->assertSame('csv', $listing->raw_payload['import_source']);
        $this->assertFalse($listing->raw_payload['demo']);
        $this->assertSame($productsBefore, Product::query()->count());
        $this->assertSame($aliasesBefore, $product->aliases()->count());
    }

    public function test_uncertain_approved_candidate_enters_existing_unmatched_review_flow(): void
    {
        config([
            'listing-discovery.max_products_per_run' => 1,
            'listing-discovery.max_new_listings_per_product' => 1,
        ]);
        $this->product($this->category(), 'NVIDIA GeForce RTX 3060', 'RTX 3060');
        $candidate = $this->candidate(
            'UNMATCHED-DISCOVERY-1',
            'https://www.olx.co.id/item/unknown-gpu-iid-881100',
        );
        $candidate['title'] = 'Unknown standalone graphics hardware';
        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success(
                [$candidate],
                [['title' => 'Grounding', 'url' => 'https://www.google.com/search?q=unknown+gpu']],
            ),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();
        app(ListingDiscoveryService::class)->execute($run);
        $candidateModel = ListingCandidate::query()->sole();
        $admin = $this->admin();

        $listing = app(ListingCandidateReviewService::class)
            ->approve($candidateModel, $admin);

        $this->assertNull($listing->product_id);
        $this->actingAs($admin)
            ->get(route('admin.listings.unmatched'))
            ->assertOk()
            ->assertSeeText('Unknown standalone graphics hardware')
            ->assertSee(e(route('admin.listings.candidate.review', $listing)), false);
    }

    public function test_candidate_review_mutations_require_an_admin(): void
    {
        $product = $this->product($this->category(), 'NVIDIA GeForce RTX 3060', 'RTX 3060');
        $candidate = ListingCandidate::query()->create([
            'listing_discovery_run_id' => $this->discoveryRun()->id,
            'target_product_id' => $product->id,
            'platform_slug' => 'olx',
            'title' => 'VGA NVIDIA RTX 3060 12GB',
            'price' => 3_100_000,
            'currency' => 'IDR',
            'condition' => 'used',
            'product_url' => 'https://www.olx.co.id/item/rtx-3060-iid-991122',
            'normalized_url_hash' => hash('sha256', 'https://www.olx.co.id/item/rtx-3060-iid-991122'),
            'grounding_sources' => [],
            'raw_payload' => [],
            'validation_status' => ListingCandidate::VALIDATION_VALID,
            'review_status' => ListingCandidate::REVIEW_PENDING,
        ]);

        foreach (['approve', 'reject'] as $action) {
            $this->app['auth']->guard()->logout();
            $route = route('admin.listings.discovery.candidates.'.$action, $candidate);
            $this->post($route)->assertRedirect(route('admin.login'));
            $this->actingAs(User::query()->create([
                'name' => 'User '.$action,
                'email' => 'user-'.$action.'@example.test',
                'password' => 'password',
                'is_admin' => false,
            ]))->post($route)->assertForbidden();
        }

        $this->assertSame(ListingCandidate::REVIEW_PENDING, $candidate->fresh()?->review_status);
        $this->assertDatabaseCount('listings', 0);
    }

    public function test_bulk_approval_control_only_renders_for_displayed_valid_pending_candidates(): void
    {
        $product = $this->product(
            $this->category(),
            'NVIDIA GeForce RTX 3060',
            'RTX 3060',
        );
        $candidate = $this->pendingCandidate(
            $product,
            'BULK-VISIBLE-1',
            'https://www.olx.co.id/item/rtx-3060-bulk-visible-iid-810001',
        );
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertSeeText('Approve Semua (1)')
            ->assertSeeText('Menyetujui semua kandidat valid yang sedang ditampilkan.')
            ->assertSee('data-dialog-open="bulk-candidate-approval-dialog"', false)
            ->assertSee(route('admin.listings.discovery.candidates.approve-all'))
            ->assertSee('name="candidate_ids[]" value="'.$candidate->id.'"', false);

        $candidate->update([
            'review_status' => ListingCandidate::REVIEW_REJECTED,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertDontSee('data-dialog-open="bulk-candidate-approval-dialog"', false)
            ->assertDontSeeText('Approve Semua (1)');
        Http::assertNothingSent();
    }

    public function test_bulk_approval_reuses_candidate_ingestion_and_reports_partial_results(): void
    {
        config(['listing-discovery.target_active_listings' => 10]);
        $product = $this->product(
            $this->category(),
            'NVIDIA GeForce RTX 3060',
            'RTX 3060',
        );
        $approved = $this->pendingCandidate(
            $product,
            'BULK-APPROVED-1',
            'https://www.olx.co.id/item/rtx-3060-bulk-approved-iid-820001',
        );
        $outOfStock = $this->pendingCandidate(
            $product,
            'BULK-OUT-OF-STOCK',
            'https://www.olx.co.id/item/rtx-3060-bulk-out-iid-820002',
            ['stock_status' => Listing::STOCK_OUT_OF_STOCK],
        );
        $duplicate = $this->pendingCandidate(
            $product,
            'BULK-DUPLICATE',
            'https://www.olx.co.id/item/rtx-3060-bulk-duplicate-iid-820003',
        );
        $invalid = $this->pendingCandidate(
            $product,
            'BULK-INVALID',
            'https://www.olx.co.id/item/rtx-3060-bulk-invalid-iid-820004',
        );
        $invalid->update([
            'validation_status' => ListingCandidate::VALIDATION_INVALID,
        ]);
        $rejected = $this->pendingCandidate(
            $product,
            'BULK-REJECTED',
            'https://www.olx.co.id/item/rtx-3060-bulk-rejected-iid-820005',
        );
        $rejected->update([
            'review_status' => ListingCandidate::REVIEW_REJECTED,
        ]);
        $alreadyApproved = $this->pendingCandidate(
            $product,
            'BULK-ALREADY-APPROVED',
            'https://www.olx.co.id/item/rtx-3060-bulk-old-iid-820006',
        );
        $alreadyApproved->update([
            'review_status' => ListingCandidate::REVIEW_APPROVED,
        ]);
        $missingProduct = $this->pendingCandidate(
            $product,
            'BULK-MISSING-PRODUCT',
            'https://www.olx.co.id/item/rtx-3060-bulk-missing-iid-820007',
        );
        $missingProduct->update(['target_product_id' => null]);
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => 'BULK-DUPLICATE',
            'source_fingerprint' => hash('sha256', 'bulk-existing-duplicate'),
            'title' => 'Existing RTX 3060 duplicate',
            'price' => 2_500_000,
            'condition' => Listing::CONDITION_USED,
            'product_url' => $duplicate->product_url,
            'stock_status' => Listing::STOCK_OUT_OF_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
        $candidateIds = [
            $approved->id,
            $outOfStock->id,
            $duplicate->id,
            $invalid->id,
            $rejected->id,
            $alreadyApproved->id,
            $missingProduct->id,
        ];

        $discoveryUrl = route('admin.listings.discovery');
        $this->actingAs($this->admin())
            ->from($discoveryUrl)
            ->post(route('admin.listings.discovery.candidates.approve-all'), [
                'candidate_ids' => $candidateIds,
            ])
            ->assertRedirect($discoveryUrl)
            ->assertSessionHas(
                'warning',
                '2 Listing disetujui. 1 dilewati karena duplikat. 2 gagal validasi. 2 dilewati karena status atau Product berubah. 0 gagal diproses.',
            );

        $this->assertSame(ListingCandidate::REVIEW_APPROVED, $approved->fresh()?->review_status);
        $this->assertSame(ListingCandidate::REVIEW_APPROVED, $outOfStock->fresh()?->review_status);
        $this->assertSame(ListingCandidate::REVIEW_INVALID, $duplicate->fresh()?->review_status);
        $this->assertSame(ListingCandidate::VALIDATION_INVALID, $duplicate->fresh()?->validation_status);
        $this->assertSame(
            2_500_000,
            Listing::query()
                ->where('external_listing_id', 'BULK-DUPLICATE')
                ->value('price'),
        );
        $this->assertSame(ListingCandidate::REVIEW_PENDING, $invalid->fresh()?->review_status);
        $this->assertSame(ListingCandidate::REVIEW_REJECTED, $rejected->fresh()?->review_status);
        $this->assertSame(ListingCandidate::REVIEW_APPROVED, $alreadyApproved->fresh()?->review_status);
        $this->assertNull($missingProduct->fresh()?->listing_id);
        $this->assertDatabaseCount('listings', 3);
        $this->assertSame(1, $product->listings()->available()->count());
        $this->get($discoveryUrl)
            ->assertOk()
            ->assertDontSee('data-dialog-open="bulk-candidate-approval-dialog"', false);
        Http::assertNothingSent();
    }

    public function test_bulk_candidate_approval_is_admin_only_and_post_only(): void
    {
        $route = route('admin.listings.discovery.candidates.approve-all');
        $routeDefinition = app('router')->getRoutes()
            ->getByName('admin.listings.discovery.candidates.approve-all');

        $this->assertNotNull($routeDefinition);
        $this->assertSame(['POST'], $routeDefinition->methods());
        $this->get($route)->assertMethodNotAllowed();
        $this->post($route, ['candidate_ids' => [1]])
            ->assertRedirect(route('admin.login'));
        $this->actingAs(User::query()->create([
            'name' => 'Bulk Regular User',
            'email' => 'bulk-regular@example.test',
            'password' => 'password',
            'is_admin' => false,
        ]))->post($route, ['candidate_ids' => [1]])
            ->assertForbidden();
        $this->assertDatabaseCount('listings', 0);
        Http::assertNothingSent();
    }

    public function test_still_needed_caps_provider_candidates_and_covered_product_is_excluded(): void
    {
        config([
            'listing-discovery.target_active_listings' => 3,
            'listing-discovery.max_products_per_run' => 2,
            'listing-discovery.max_new_listings_per_product' => 3,
        ]);
        $category = $this->category();
        $needsOne = $this->product($category, 'Needs One Listing', null);
        $covered = $this->product($category, 'Already Covered', null);
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();

        foreach (range(1, 2) as $index) {
            $this->listing($platform, $needsOne, 'needs-one-'.$index);
        }

        foreach (range(1, 3) as $index) {
            $this->listing($platform, $covered, 'covered-excluded-'.$index);
        }

        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success([], []),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();

        app(ListingDiscoveryService::class)->execute($run);

        $this->assertSame([$needsOne->id], $provider->productIds);
        $this->assertSame([1], $provider->maximumCandidates);
        $this->assertNotContains($covered->id, $provider->productIds);
    }

    public function test_successful_search_without_candidates_is_no_result_and_not_a_partial_run(): void
    {
        config([
            'listing-discovery.target_active_listings' => 3,
            'listing-discovery.max_products_per_run' => 1,
        ]);
        $product = $this->product($this->category(), 'No Result Product', null);
        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success(
                [],
                [],
                [
                    'search_results' => 10,
                    'supported_domain_results' => 8,
                    'direct_listing_urls' => 4,
                    'product_matches' => 2,
                    'http_probe_attempts' => 2,
                    'http_probe_success' => 0,
                    'extract_attempts' => 2,
                    'extract_success' => 0,
                    'valid_candidates' => 0,
                    'rejected_candidates' => 4,
                    'rejection_reasons' => ['price_missing' => 2],
                ],
            ),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();

        app(ListingDiscoveryService::class)->execute($run);

        $runProduct = $run->runProducts()->where('product_id', $product->id)->firstOrFail();
        $this->assertSame(ListingDiscoveryRunProduct::STATUS_NO_RESULT, $runProduct->status);
        $this->assertSame(3, $runProduct->still_needed);
        $this->assertSame(10, $runProduct->diagnostics['search_results']);
        $this->assertSame(2, $runProduct->diagnostics['rejection_reasons']['price_missing']);
        $this->assertSame(ListingDiscoveryRun::STATUS_COMPLETED, $run->fresh()?->status);
        $this->assertFalse($runProduct->provider_failed);
    }

    public function test_parent_run_is_partial_only_when_a_product_has_a_technical_failure(): void
    {
        config([
            'listing-discovery.max_products_per_run' => 2,
            'listing-discovery.target_active_listings' => 3,
        ]);
        $this->product($this->category(), 'Technical A', null);
        $this->product($this->category(), 'Technical B', null);
        $calls = 0;
        $provider = new RecordingDiscoveryProvider(
            static function () use (&$calls): ListingDiscoveryResult {
                $calls++;

                return $calls === 1
                    ? ListingDiscoveryResult::success([], [])
                    : ListingDiscoveryResult::failed(
                        'connection',
                        'Provider could not be reached.',
                    );
            },
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $run = $this->discoveryRun();

        app(ListingDiscoveryService::class)->execute($run);

        $this->assertSame(ListingDiscoveryRun::STATUS_PARTIAL, $run->fresh()?->status);
        $this->assertSame(1, $run->runProducts()->where('status', ListingDiscoveryRunProduct::STATUS_NO_RESULT)->count());
        $this->assertSame(1, $run->runProducts()->where('status', ListingDiscoveryRunProduct::STATUS_FAILED)->count());
    }

    public function test_recently_checked_products_are_skipped_by_the_next_batch(): void
    {
        config([
            'listing-discovery.enabled' => true,
            'listing-discovery.max_products_per_run' => 2,
            'listing-discovery.retry_cooldown_hours' => 24,
        ]);
        Queue::fake();
        $category = $this->category();
        $products = collect(range(1, 4))->map(
            fn (int $index): Product => $this->product(
                $category,
                'Cooldown Product '.$index,
                null,
            ),
        );
        $provider = new RecordingDiscoveryProvider(
            static fn (): ListingDiscoveryResult => ListingDiscoveryResult::success([], []),
        );
        $this->app->instance(ListingDiscoveryProvider::class, $provider);
        $service = app(ListingDiscoveryService::class);
        $first = $service->queue($this->admin()->id);
        $firstIds = $first->runProducts()->pluck('product_id')->all();
        $service->execute($first);

        $second = $service->queue($this->admin()->id);
        $secondIds = $second->runProducts()->pluck('product_id')->all();

        $this->assertSame($products->take(2)->pluck('id')->all(), $firstIds);
        $this->assertSame($products->skip(2)->pluck('id')->all(), $secondIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds));
    }

    public function test_admin_progress_and_run_detail_use_persisted_state_without_provider_request(): void
    {
        config(['listing-discovery.tavily.api_key' => 'must-not-be-exposed']);
        $category = $this->category();
        $run = $this->discoveryRun();

        foreach (range(1, 100) as $index) {
            $status = match (true) {
                $index <= 37 => ListingDiscoveryRunProduct::STATUS_NO_RESULT,
                $index === 38 => ListingDiscoveryRunProduct::STATUS_RUNNING,
                default => ListingDiscoveryRunProduct::STATUS_QUEUED,
            };
            $product = $this->product(
                $category,
                'Progress Product '.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                null,
            );
            $run->runProducts()->create([
                'product_id' => $product->id,
                'available_listings_before' => 0,
                'status' => $status,
                'started_at' => $index <= 38 ? now() : null,
                'finished_at' => $index <= 37 ? now() : null,
            ]);
        }

        $run->forceFill([
            'status' => ListingDiscoveryRun::STATUS_RUNNING,
            'products_targeted' => 100,
            'products_checked' => 37,
            'candidates_found' => 12,
            'valid_candidates' => 8,
            'rejected_candidates' => 4,
            'provider_failures' => 2,
        ])->save();
        $admin = $this->admin();

        $this->getJson(route('admin.listings.discovery.runs.progress', $run))
            ->assertUnauthorized();
        $this->actingAs($admin)
            ->getJson(route('admin.listings.discovery.runs.progress', $run))
            ->assertOk()
            ->assertJsonPath('products_targeted', 100)
            ->assertJsonPath('products_checked', 37)
            ->assertJsonPath('products_remaining', 63)
            ->assertJsonPath('percent', 37)
            ->assertJsonPath('current_product.name', 'Progress Product 038')
            ->assertJsonPath('candidates_found', 12)
            ->assertJsonPath('valid_candidates', 8)
            ->assertJsonPath('rejected_candidates', 4)
            ->assertJsonPath('provider_failures', 2)
            ->assertJsonPath('terminal', false)
            ->assertDontSee('must-not-be-exposed');
        $this->actingAs($admin)
            ->get(route('admin.listings.discovery.runs.show', $run))
            ->assertOk()
            ->assertSeeText('1–50 dari 100');
        $this->actingAs($admin)
            ->get(route('admin.listings.discovery.runs.show', $run).'?search=Progress%20Product%20038')
            ->assertOk()
            ->assertSeeText('Progress Product 038');
        $this->actingAs($admin)
            ->get(route('admin.listings.discovery.runs.show', $run).'?sort=product_az')
            ->assertOk()
            ->assertSee('sort=product_az&amp;page=2', false);
        $this->actingAs($admin)
            ->get(route('admin.listings.discovery'))
            ->assertOk()
            ->assertSeeText('Discovery Berjalan');
        Http::assertNothingSent();
    }

    public function test_run_detail_supports_product_filters_review_filters_and_all_sort_modes(): void
    {
        $gpu = $this->category();
        $laptop = Category::query()->create([
            'name' => 'Laptop',
            'slug' => 'laptop',
            'is_active' => true,
        ]);
        $alpha = $this->product($gpu, 'Alpha RTX Product', 'RTX 3060');
        $beta = $this->product($gpu, 'Beta Radeon Product', 'RX 6600');
        $beta->forceFill(['brand' => 'AMD'])->save();
        $gamma = $this->product($laptop, 'Gamma Laptop Product', 'ThinkBook 14');
        $gamma->forceFill(['brand' => 'Lenovo'])->save();
        $delta = $this->product($gpu, 'Delta Empty Product', null);
        $run = $this->discoveryRun();
        $rows = [
            [$alpha, ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND, 4],
            [$beta, ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND, 3],
            [$gamma, ListingDiscoveryRunProduct::STATUS_FAILED, 2],
            [$delta, ListingDiscoveryRunProduct::STATUS_NO_RESULT, 1],
        ];

        foreach ($rows as [$product, $status, $minutesAgo]) {
            $run->runProducts()->create([
                'product_id' => $product->id,
                'available_listings_before' => 0,
                'candidates_found' => $status === ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND ? 1 : 0,
                'valid_candidates' => $status === ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND ? 1 : 0,
                'status' => $status,
                'started_at' => now()->subMinutes($minutesAgo)->subSecond(),
                'finished_at' => now()->subMinutes($minutesAgo),
                'diagnostics' => $product->is($alpha) ? [
                    'search_results' => 10,
                    'supported_domain_results' => 8,
                    'direct_listing_urls' => 5,
                    'product_matches' => 4,
                    'search_evidence_candidates' => 1,
                    'http_probe_attempts' => 4,
                    'http_probe_success' => 2,
                    'extract_attempts' => 1,
                    'extract_success' => 0,
                    'valid_candidates' => 1,
                    'rejected_candidates' => 3,
                    'rejection_reasons' => ['price_missing' => 2],
                ] : null,
            ]);
        }

        foreach ([
            [$alpha, ListingCandidate::REVIEW_PENDING, 'FILTER-PENDING'],
            [$beta, ListingCandidate::REVIEW_APPROVED, 'FILTER-APPROVED'],
            [$gamma, ListingCandidate::REVIEW_REJECTED, 'FILTER-REJECTED'],
        ] as [$product, $reviewStatus, $externalId]) {
            ListingCandidate::query()->create([
                'listing_discovery_run_id' => $run->id,
                'target_product_id' => $product->id,
                'platform_slug' => 'olx',
                'external_listing_id' => $externalId,
                'title' => $product->name,
                'price' => 1_000_000,
                'currency' => 'IDR',
                'condition' => Listing::CONDITION_USED,
                'product_url' => 'https://www.olx.co.id/item/'.strtolower($externalId),
                'normalized_url_hash' => hash('sha256', $externalId),
                'validation_status' => ListingCandidate::VALIDATION_VALID,
                'review_status' => $reviewStatus,
            ]);
        }
        $run->forceFill([
            'status' => ListingDiscoveryRun::STATUS_COMPLETED,
            'products_targeted' => 4,
            'products_checked' => 4,
            'finished_at' => now(),
        ])->save();
        $admin = $this->admin();
        $route = route('admin.listings.discovery.runs.show', $run);

        $this->actingAs($admin)->get($route)
            ->assertOk()
            ->assertSeeText('Waktu Diperiksa')
            ->assertSeeText('Tidak Ada Kandidat')
            ->assertSeeText('Status review menghitung kandidat Listing')
            ->assertSeeText('Lihat Diagnostik')
            ->assertSeeText('Search 10');
        $this->actingAs($admin)->get($route.'?search=AMD')
            ->assertOk()
            ->assertSeeText('Beta Radeon Product')
            ->assertDontSeeText('Alpha RTX Product');
        $this->actingAs($admin)->get($route.'?result_status=failed')
            ->assertOk()
            ->assertSeeText('Gamma Laptop Product')
            ->assertDontSeeText('Beta Radeon Product');
        $this->actingAs($admin)->get($route.'?review_status=approved')
            ->assertOk()
            ->assertSeeText('Beta Radeon Product')
            ->assertDontSeeText('Alpha RTX Product');
        $this->actingAs($admin)->get($route.'?review_status=rejected')
            ->assertOk()
            ->assertSeeText('Gamma Laptop Product')
            ->assertDontSeeText('Beta Radeon Product');
        $this->actingAs($admin)->get($route.'?review_status=no_candidates')
            ->assertOk()
            ->assertSeeText('Delta Empty Product')
            ->assertDontSeeText('Beta Radeon Product');
        $this->actingAs($admin)->get($route.'?category='.$laptop->id)
            ->assertOk()
            ->assertSeeText('Gamma Laptop Product')
            ->assertDontSeeText('Alpha RTX Product');
        $this->actingAs($admin)->get($route.'?sort=product_az')
            ->assertOk()
            ->assertSeeTextInOrder([
                'Alpha RTX Product',
                'Beta Radeon Product',
                'Delta Empty Product',
                'Gamma Laptop Product',
            ]);
        $this->actingAs($admin)->get($route.'?sort=product_za')
            ->assertOk()
            ->assertSeeTextInOrder([
                'Gamma Laptop Product',
                'Delta Empty Product',
                'Beta Radeon Product',
                'Alpha RTX Product',
            ]);
        $this->actingAs($admin)->get($route.'?sort=newest')
            ->assertOk()
            ->assertSeeTextInOrder([
                'Delta Empty Product',
                'Gamma Laptop Product',
                'Beta Radeon Product',
                'Alpha RTX Product',
            ]);
        $this->actingAs($admin)->get($route.'?sort=oldest')
            ->assertOk()
            ->assertSeeTextInOrder([
                'Alpha RTX Product',
                'Beta Radeon Product',
                'Gamma Laptop Product',
                'Delta Empty Product',
            ]);
        Http::assertNothingSent();
    }

    public function test_run_history_defaults_newest_and_filters_status_provider_and_sort(): void
    {
        $old = ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_COMPLETED,
            'provider' => 'tavily',
            'model' => 'search:basic+extract:basic',
            'region' => 'Indonesia',
            'error_message' => 'Older Tavily marker',
        ]);
        $new = ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_FAILED,
            'provider' => 'gemini',
            'model' => 'gemini-test',
            'region' => 'Indonesia',
            'error_message' => 'Newer Gemini marker',
        ]);
        $admin = $this->admin();
        $route = route('admin.listings.discovery');

        $this->actingAs($admin)->get($route)
            ->assertOk()
            ->assertSeeTextInOrder(['#'.$new->id, '#'.$old->id]);
        $this->actingAs($admin)->get($route.'?run_provider=tavily')
            ->assertOk()
            ->assertSeeText('Older Tavily marker')
            ->assertDontSeeText('Newer Gemini marker');
        $this->actingAs($admin)->get($route.'?run_status=failed')
            ->assertOk()
            ->assertSeeText('Newer Gemini marker')
            ->assertDontSeeText('Older Tavily marker');
        $this->actingAs($admin)->get($route.'?run_sort=oldest')
            ->assertOk()
            ->assertSeeTextInOrder(['#'.$old->id, '#'.$new->id]);
        Http::assertNothingSent();
    }

    public function test_historical_unresolved_children_are_labeled_without_rewriting_status(): void
    {
        $product = $this->product($this->category(), 'Historical Queued Product', null);
        $run = $this->discoveryRun();
        $run->forceFill([
            'status' => ListingDiscoveryRun::STATUS_COMPLETED,
            'products_targeted' => 1,
            'products_checked' => 0,
            'finished_at' => now(),
        ])->save();
        $runProduct = $run->runProducts()->create([
            'product_id' => $product->id,
            'available_listings_before' => 0,
            'status' => ListingDiscoveryRunProduct::STATUS_QUEUED,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.listings.discovery.runs.show', $run))
            ->assertOk()
            ->assertSeeText('Run historis ini memiliki Product')
            ->assertSeeText('QUEUED');

        $this->assertSame(
            ListingDiscoveryRunProduct::STATUS_QUEUED,
            $runProduct->fresh()?->status,
        );
        Http::assertNothingSent();
    }

    public function test_gemini_provider_uses_one_grounded_request_and_structured_output(): void
    {
        config([
            'listing-discovery.gemini.api_key' => 'fake-discovery-key',
            'listing-discovery.gemini.model' => 'gemini-2.5-flash',
            'listing-discovery.gemini.grounding_enabled' => true,
            'listing-discovery.gemini.http.retries' => 0,
        ]);
        $product = $this->product($this->category(), 'NVIDIA GeForce RTX 3060', 'RTX 3060');
        $candidate = $this->candidate(
            'GEMINI-1',
            'https://www.olx.co.id/item/rtx-3060-iid-771100',
        );
        Http::fake([
            '*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode(['candidates' => [$candidate]], JSON_THROW_ON_ERROR),
                    ]]],
                    'groundingMetadata' => ['groundingChunks' => [[
                        'web' => ['title' => 'OLX result', 'uri' => 'https://vertexaisearch.cloud.google.com/grounding-api-redirect/example'],
                    ]]],
                ]],
            ]),
        ]);

        $result = app(GeminiListingDiscoveryProvider::class)->discover($product);

        $this->assertTrue($result->succeeded());
        $this->assertCount(1, $result->candidates);
        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => data_get($request->data(), 'tools.0.googleSearch') !== null
            && str_contains($request->url(), 'gemini-2.5-flash:generateContent')
            && $request->hasHeader('x-goog-api-key', 'fake-discovery-key'));
    }

    /** @return array<string, mixed> */
    private function candidate(string $externalId, string $url): array
    {
        return [
            'platform' => 'olx',
            'external_listing_id' => $externalId,
            'title' => 'VGA NVIDIA RTX 3060 12GB',
            'description' => 'Standalone graphics card.',
            'price' => 3_100_000,
            'currency' => 'IDR',
            'condition' => 'used',
            'seller_name' => 'Seller Jakarta',
            'seller_type' => 'unknown',
            'location' => 'Jakarta',
            'product_url' => $url,
            'image_url' => null,
            'stock_status' => 'in_stock',
            'price_type' => 'full_price',
            'warranty' => null,
            'source_published_at' => null,
        ];
    }

    private function discoveryRun(): ListingDiscoveryRun
    {
        return ListingDiscoveryRun::query()->create([
            'status' => ListingDiscoveryRun::STATUS_QUEUED,
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
            'region' => 'Indonesia',
        ]);
    }

    private function category(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'gpu'],
            ['name' => 'GPU', 'is_active' => true],
        );
    }

    private function product(
        Category $category,
        string $name,
        ?string $model,
        bool $active = true,
    ): Product {
        return Product::query()->create([
            'category_id' => $category->id,
            'name' => $name,
            'brand' => $model !== null ? 'NVIDIA' : null,
            'model' => $model,
            'variant' => $model !== null ? '12 GB Desktop' : null,
            'normalized_key' => Product::makeNormalizedKey(
                $name,
                $model !== null ? 'NVIDIA' : null,
                $model,
                $model !== null ? '12 GB Desktop' : null,
            ),
            'is_active' => $active,
        ]);
    }

    private function listing(Platform $platform, Product $product, string $key): void
    {
        Listing::query()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'external_listing_id' => $key,
            'source_fingerprint' => hash('sha256', $key),
            'title' => $key,
            'price' => 1_000_000,
            'condition' => Listing::CONDITION_USED,
            'product_url' => 'https://www.olx.co.id/item/'.$key,
            'stock_status' => Listing::STOCK_IN_STOCK,
            'price_type' => Listing::PRICE_FULL,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $candidateOverrides */
    private function pendingCandidate(
        Product $product,
        string $externalId,
        string $url,
        array $candidateOverrides = [],
    ): ListingCandidate {
        $platform = Platform::query()->where('slug', 'olx')->firstOrFail();
        $values = array_merge(
            $this->candidate($externalId, $url),
            $candidateOverrides,
        );
        $normalizedUrl = strtok($url, '?') ?: $url;
        $values['product_url'] = $normalizedUrl;

        return ListingCandidate::query()->create([
            'listing_discovery_run_id' => $this->discoveryRun()->id,
            'target_product_id' => $product->id,
            'platform_slug' => 'olx',
            'external_listing_id' => $externalId,
            'title' => $values['title'],
            'price' => $values['price'],
            'currency' => 'IDR',
            'condition' => $values['condition'],
            'seller_name' => $values['seller_name'],
            'location' => $values['location'],
            'product_url' => $normalizedUrl,
            'normalized_url_hash' => hash('sha256', $normalizedUrl),
            'grounding_sources' => [],
            'raw_payload' => [
                'validated_row' => [
                    'row_number' => 1,
                    'platform_id' => $platform->id,
                    'platform_slug' => 'olx',
                    'values' => $values,
                    'source_name' => 'automatic-listing-test',
                    'matched' => true,
                ],
            ],
            'validation_status' => ListingCandidate::VALIDATION_VALID,
            'review_status' => ListingCandidate::REVIEW_PENDING,
        ]);
    }

    private function platforms(): void
    {
        foreach (Platform::MVP_SLUGS as $slug) {
            Platform::query()->create([
                'name' => ucfirst($slug),
                'slug' => $slug,
                'source_type' => Platform::SOURCE_MANUAL,
                'access_type' => Platform::ACCESS_CSV_IMPORT,
                'connector' => 'manual-import',
                'status' => Platform::STATUS_READY,
                'is_active' => true,
                'sync_enabled' => false,
            ]);
        }
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.uniqid().'@example.test',
            'password' => 'password',
            'is_admin' => true,
        ]);
    }
}

final class RecordingDiscoveryProvider implements ListingDiscoveryProvider
{
    /** @var list<int> */
    public array $productIds = [];

    /** @var list<int|null> */
    public array $maximumCandidates = [];

    /** @param \Closure(Product): ListingDiscoveryResult $callback */
    public function __construct(private readonly \Closure $callback) {}

    public function discover(
        Product $product,
        ?int $maximumCandidates = null,
    ): ListingDiscoveryResult {
        $this->productIds[] = (int) $product->id;
        $this->maximumCandidates[] = $maximumCandidates;

        return ($this->callback)($product);
    }
}
