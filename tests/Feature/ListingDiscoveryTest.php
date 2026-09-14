<?php

namespace Tests\Feature;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Data\Marketplace\ListingDiscoveryResult;
use App\Jobs\DiscoverListingsJob;
use App\Models\Category;
use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
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
        $missingPrice = $this->candidate(
            'DISCOVERY-MISSING-PRICE',
            'https://www.olx.co.id/item/rtx-3060-iid-551101',
        );
        $missingPrice['price'] = null;
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

        $this->assertSame(ListingDiscoveryRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->products_checked);
        $this->assertSame(4, $run->candidates_found);
        $this->assertSame(1, $run->valid_candidates);
        $this->assertSame(3, $run->rejected_candidates);
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

    public function test_gemini_provider_uses_one_grounded_request_and_structured_output(): void
    {
        config([
            'listing-discovery.api_key' => 'fake-discovery-key',
            'listing-discovery.model' => 'gemini-2.5-flash',
            'listing-discovery.web_grounding_enabled' => true,
            'listing-discovery.http.retries' => 0,
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

    /** @param \Closure(Product): ListingDiscoveryResult $callback */
    public function __construct(private readonly \Closure $callback) {}

    public function discover(Product $product): ListingDiscoveryResult
    {
        $this->productIds[] = (int) $product->id;

        return ($this->callback)($product);
    }
}
