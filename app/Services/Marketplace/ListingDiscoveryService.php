<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Jobs\DiscoverListingsJob;
use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Models\ListingDiscoveryRunProduct;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ListingDiscoveryService
{
    public function __construct(
        private readonly ListingCoverageService $coverage,
        private readonly ListingDiscoveryProvider $provider,
        private readonly MarketplaceCsvImportService $csv,
    ) {}

    public function queue(?int $userId = null): ListingDiscoveryRun
    {
        return Cache::lock('listing-discovery:queue', 10)->block(
            3,
            function () use ($userId): ListingDiscoveryRun {
                $existing = ListingDiscoveryRun::query()
                    ->whereIn('status', [
                        ListingDiscoveryRun::STATUS_QUEUED,
                        ListingDiscoveryRun::STATUS_RUNNING,
                    ])
                    ->latest('id')
                    ->first();

                if ($existing instanceof ListingDiscoveryRun) {
                    return $existing;
                }

                $run = DB::transaction(function () use ($userId): ListingDiscoveryRun {
                    $products = $this->coverage->discoveryTargets(
                        $this->coverage->target(),
                        $this->maximumProducts(),
                    );
                    $run = ListingDiscoveryRun::query()->create([
                        'created_by' => $userId,
                        'status' => ListingDiscoveryRun::STATUS_QUEUED,
                        'provider' => (string) config(
                            'listing-discovery.provider',
                            'tavily',
                        ),
                        'model' => $this->providerDescriptor(),
                        'region' => (string) config(
                            'listing-discovery.region',
                            'Indonesia',
                        ),
                        'products_targeted' => $products->count(),
                    ]);

                    foreach ($products as $product) {
                        $available = (int) $product
                            ->getAttribute('active_listings_count');
                        $run->runProducts()->create([
                            'product_id' => $product->id,
                            'available_listings_before' => $available,
                            'still_needed' => $this->coverage->stillNeeded(
                                $available,
                            ),
                            'status' => ListingDiscoveryRunProduct::STATUS_QUEUED,
                        ]);
                    }

                    return $run;
                });
                DiscoverListingsJob::dispatch($run->id)
                    ->onQueue('listing-discovery');

                return $run;
            },
        );
    }

    public function execute(ListingDiscoveryRun $run): void
    {
        $run->refresh();

        if (! $run->isActive()) {
            return;
        }

        $runProducts = $this->prepareRunProducts($run);
        $run->forceFill([
            'status' => ListingDiscoveryRun::STATUS_RUNNING,
            'products_targeted' => $runProducts->count(),
            'started_at' => now(),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();
        $lastFailureCategory = null;
        $lastFailureMessage = null;
        $maximumPerProduct = min(25, max(
            1,
            (int) config(
                'listing-discovery.max_new_listings_per_product',
                3,
            ),
        ));
        $maximumPerPlatform = min($maximumPerProduct, max(
            1,
            (int) config(
                'listing-discovery.max_per_platform_per_product',
                2,
            ),
        ));

        try {
            foreach ($runProducts as $runProduct) {
                $runProduct->forceFill([
                    'status' => ListingDiscoveryRunProduct::STATUS_RUNNING,
                    'started_at' => now(),
                    'finished_at' => null,
                ])->save();
                $product = Product::query()
                    ->with(['category:id,name,slug', 'aliases:id,product_id,alias'])
                    ->find($runProduct->product_id);

                if (! $product instanceof Product) {
                    $this->finishRunProduct(
                        $runProduct,
                        ListingDiscoveryRunProduct::STATUS_FAILED,
                        providerErrorCode: 'product_unavailable',
                    );
                    $lastFailureCategory = 'product_unavailable';
                    $lastFailureMessage = 'A target Product is no longer available.';
                    $this->syncRunProgress($run);

                    continue;
                }

                $availableNow = $product->listings()->available()->count();
                $usefulMaximum = min(
                    $maximumPerProduct,
                    $this->coverage->stillNeeded($availableNow),
                );
                $runProduct->forceFill([
                    'still_needed' => $usefulMaximum,
                ])->save();

                if ($usefulMaximum === 0) {
                    $this->finishRunProduct(
                        $runProduct,
                        ListingDiscoveryRunProduct::STATUS_SKIPPED,
                    );
                    $this->syncRunProgress($run);

                    continue;
                }

                try {
                    $result = $this->provider->discover(
                        $product,
                        $usefulMaximum,
                    );
                } catch (Throwable) {
                    $this->finishRunProduct(
                        $runProduct,
                        ListingDiscoveryRunProduct::STATUS_FAILED,
                        true,
                        'provider_exception',
                    );
                    $lastFailureCategory = 'provider_exception';
                    $lastFailureMessage = 'The discovery provider could not be completed.';
                    $this->syncRunProgress($run);

                    continue;
                }

                if (! $result->succeeded()) {
                    $lastFailureCategory = $result->failureCategory;
                    $lastFailureMessage = $result->failureMessage;
                    $this->finishRunProduct(
                        $runProduct,
                        ListingDiscoveryRunProduct::STATUS_FAILED,
                        true,
                        $result->failureCategory,
                        diagnostics: $result->diagnostics,
                    );
                    $this->syncRunProgress($run);

                    continue;
                }

                $rawCandidates = array_slice(
                    $result->candidates,
                    0,
                    $usefulMaximum,
                );
                $productFound = count($rawCandidates);
                $productValid = 0;
                $productRejected = 0;
                $perPlatform = [];

                try {
                    foreach ($rawCandidates as $index => $candidate) {
                        $platformSlug = strtolower(trim(
                            (string) ($candidate['platform'] ?? ''),
                        ));

                        if (($perPlatform[$platformSlug] ?? 0) >= $maximumPerPlatform) {
                            $this->storeInvalidCandidate(
                                $run,
                                $product,
                                $candidate,
                                $result->sources,
                                'platform candidate limit exceeded',
                            );
                            $productRejected++;

                            continue;
                        }

                        $perPlatform[$platformSlug] = ($perPlatform[$platformSlug] ?? 0) + 1;
                        $values = $this->candidateValues($candidate);
                        $preview = $this->csv->previewRecords([[
                            'row_number' => $index + 1,
                            'values' => $values,
                        ]], $run->provider.'-listing-discovery');

                        if ($preview['rows'] === []) {
                            $this->storeInvalidCandidate(
                                $run,
                                $product,
                                $candidate,
                                $result->sources,
                                $preview['issues'][0] ?? 'candidate validation failed',
                            );
                            $productRejected++;

                            continue;
                        }

                        $row = $preview['rows'][0];
                        $normalizedUrl = (string) $row['values']['product_url'];

                        if ($run->candidates()
                            ->where('normalized_url_hash', hash('sha256', $normalizedUrl))
                            ->exists()) {
                            $this->storeInvalidCandidate(
                                $run,
                                $product,
                                $candidate,
                                $result->sources,
                                'duplicate URL',
                            );
                            $productRejected++;

                            continue;
                        }

                        $run->candidates()->create([
                            'target_product_id' => $product->id,
                            'platform_slug' => $row['platform_slug'],
                            'external_listing_id' => $this->nullableText(
                                $row['values']['external_listing_id'] ?? null,
                                255,
                            ),
                            'title' => $this->nullableText(
                                $row['values']['title'] ?? null,
                                255,
                            ),
                            'price' => (int) $row['values']['price'],
                            'currency' => 'IDR',
                            'condition' => $this->condition(
                                $row['values']['condition'] ?? null,
                            ),
                            'seller_name' => $this->nullableText(
                                $row['values']['seller_name'] ?? null,
                                255,
                            ),
                            'location' => $this->nullableText(
                                $row['values']['location'] ?? null,
                                255,
                            ),
                            'product_url' => $normalizedUrl,
                            'normalized_url_hash' => hash('sha256', $normalizedUrl),
                            'grounding_sources' => $result->sources,
                            'raw_payload' => [
                                'provider_candidate' => $candidate,
                                'validated_row' => $row,
                            ],
                            'validation_status' => ListingCandidate::VALIDATION_VALID,
                            'review_status' => ListingCandidate::REVIEW_PENDING,
                        ]);
                        $productValid++;
                    }
                } catch (Throwable) {
                    $this->finishRunProduct(
                        $runProduct,
                        ListingDiscoveryRunProduct::STATUS_FAILED,
                        false,
                        'processing_error',
                        $productFound,
                        $productValid,
                        $productRejected,
                    );
                    $lastFailureCategory = 'processing_error';
                    $lastFailureMessage = 'A discovered candidate could not be processed.';
                    $this->syncRunProgress($run);

                    continue;
                }

                $providerFailed = $result->hasTechnicalFailure();
                $diagnostics = $result->diagnostics;
                $diagnostics['provider_candidates'] = $productFound;
                $diagnostics['shared_validation_valid'] = $productValid;
                $diagnostics['shared_validation_rejected'] = $productRejected;
                $diagnostics['valid_candidates'] = $productValid;
                $diagnostics['rejected_candidates'] = (
                    (int) ($diagnostics['rejected_candidates'] ?? 0)
                ) + $productRejected;
                $productStatus = match (true) {
                    $providerFailed && $productValid === 0 => ListingDiscoveryRunProduct::STATUS_FAILED,
                    $providerFailed => ListingDiscoveryRunProduct::STATUS_PARTIAL,
                    $productValid > 0 && $productRejected > 0 => ListingDiscoveryRunProduct::STATUS_PARTIAL,
                    $productValid > 0 => ListingDiscoveryRunProduct::STATUS_CANDIDATE_FOUND,
                    default => ListingDiscoveryRunProduct::STATUS_NO_RESULT,
                };
                if ($providerFailed) {
                    $lastFailureCategory = $result->partialFailureCategory;
                    $lastFailureMessage = $result->partialFailureMessage;
                }
                $this->finishRunProduct(
                    $runProduct,
                    $productStatus,
                    $providerFailed,
                    $result->partialFailureCategory,
                    $productFound,
                    $productValid,
                    $productRejected,
                    $diagnostics,
                );
                $this->syncRunProgress($run);
            }

            $this->syncRunProgress($run);
            $failedProducts = $run->runProducts()
                ->where('status', ListingDiscoveryRunProduct::STATUS_FAILED)
                ->count();
            $technicalFailures = $run->runProducts()
                ->where('provider_failed', true)
                ->count();
            $unresolvedProducts = $run->runProducts()
                ->whereIn('status', [
                    ListingDiscoveryRunProduct::STATUS_QUEUED,
                    ListingDiscoveryRunProduct::STATUS_RUNNING,
                ])
                ->count();

            if ($unresolvedProducts > 0) {
                $lastFailureCategory = 'incomplete_processing';
                $lastFailureMessage = 'Some target Products did not reach a terminal state.';
            }

            $status = match (true) {
                $run->products_targeted > 0
                    && $failedProducts === $run->products_targeted => ListingDiscoveryRun::STATUS_FAILED,
                $unresolvedProducts > 0 => ListingDiscoveryRun::STATUS_PARTIAL,
                $technicalFailures > 0 => ListingDiscoveryRun::STATUS_PARTIAL,
                default => ListingDiscoveryRun::STATUS_COMPLETED,
            };
            $run->forceFill([
                'status' => $status,
                'error_code' => $lastFailureCategory,
                'error_message' => $this->nullableText(
                    $lastFailureMessage,
                    500,
                ),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable) {
            $run->runProducts()
                ->whereIn('status', [
                    ListingDiscoveryRunProduct::STATUS_QUEUED,
                    ListingDiscoveryRunProduct::STATUS_RUNNING,
                ])
                ->update([
                    'status' => ListingDiscoveryRunProduct::STATUS_FAILED,
                    'provider_error_code' => 'processing_error',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            $this->syncRunProgress($run);
            $run->forceFill([
                'status' => ListingDiscoveryRun::STATUS_FAILED,
                'error_code' => 'processing_error',
                'error_message' => 'Listing discovery could not be completed.',
                'finished_at' => now(),
            ])->save();
        }
    }

    private function prepareRunProducts(
        ListingDiscoveryRun $run,
    ): Collection {
        if ($run->runProducts()->exists()) {
            return $run->runProducts()->orderBy('id')->get();
        }

        $products = $this->coverage->discoveryTargets(
            $this->coverage->target(),
            $this->maximumProducts(),
        );

        foreach ($products as $product) {
            $available = (int) $product
                ->getAttribute('active_listings_count');
            $run->runProducts()->create([
                'product_id' => $product->id,
                'available_listings_before' => $available,
                'still_needed' => $this->coverage->stillNeeded($available),
                'status' => ListingDiscoveryRunProduct::STATUS_QUEUED,
            ]);
        }

        return $run->runProducts()->orderBy('id')->get();
    }

    private function finishRunProduct(
        ListingDiscoveryRunProduct $runProduct,
        string $status,
        bool $providerFailed = false,
        ?string $providerErrorCode = null,
        int $found = 0,
        int $valid = 0,
        int $rejected = 0,
        array $diagnostics = [],
    ): void {
        $runProduct->forceFill([
            'status' => $status,
            'provider_failed' => $providerFailed,
            'provider_error_code' => $this->nullableText(
                $providerErrorCode,
                100,
            ),
            'candidates_found' => $found,
            'valid_candidates' => $valid,
            'rejected_candidates' => $rejected,
            'diagnostics' => $diagnostics !== [] ? $diagnostics : null,
            'finished_at' => now(),
        ])->save();
    }

    private function syncRunProgress(ListingDiscoveryRun $run): void
    {
        $run->forceFill([
            'products_targeted' => $run->runProducts()->count(),
            'products_checked' => $run->runProducts()
                ->whereNotIn('status', [
                    ListingDiscoveryRunProduct::STATUS_QUEUED,
                    ListingDiscoveryRunProduct::STATUS_RUNNING,
                ])
                ->count(),
            'candidates_found' => (int) $run->runProducts()
                ->sum('candidates_found'),
            'valid_candidates' => (int) $run->runProducts()
                ->sum('valid_candidates'),
            'rejected_candidates' => (int) $run->runProducts()
                ->sum('rejected_candidates'),
            'provider_failures' => $run->runProducts()
                ->where('provider_failed', true)
                ->count(),
        ])->save();
    }

    private function maximumProducts(): int
    {
        return max(
            1,
            (int) config('listing-discovery.max_products_per_run'),
        );
    }

    /** @param array<string, mixed> $candidate */
    private function candidateValues(array $candidate): array
    {
        $values = [];

        foreach (MarketplaceCsvImportService::HEADERS as $header) {
            $value = $candidate[$header] ?? null;
            $values[$header] = is_scalar($value) ? trim((string) $value) : null;
        }

        foreach ([
            'product_reference',
            'product_brand',
            'product_model',
            'product_variant',
            'product_sku',
        ] as $hint) {
            $value = $candidate[$hint] ?? null;
            $values[$hint] = is_scalar($value)
                ? trim((string) $value)
                : null;
        }

        $values['currency'] = $values['currency'] ?: 'IDR';
        $values['condition'] = $this->condition($values['condition']);
        $values['seller_type'] = 'unknown';
        $values['stock_status'] = $values['stock_status'] ?: 'unknown';
        $values['price_type'] = 'full_price';

        return $values;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array{title:string,url:string}>  $sources
     */
    private function storeInvalidCandidate(
        ListingDiscoveryRun $run,
        Product $product,
        array $candidate,
        array $sources,
        string $reason,
    ): void {
        $url = $this->nullableText($candidate['product_url'] ?? null, 2048);
        $urlHash = $url !== null ? hash('sha256', $url) : null;

        if ($urlHash !== null && $run->candidates()
            ->where('normalized_url_hash', $urlHash)
            ->exists()) {
            $urlHash = null;
        }

        $run->candidates()->create([
            'target_product_id' => $product->id,
            'platform_slug' => $this->nullableText(
                $candidate['platform'] ?? null,
                64,
            ),
            'external_listing_id' => $this->nullableText(
                $candidate['external_listing_id'] ?? null,
                255,
            ),
            'title' => $this->nullableText($candidate['title'] ?? null, 255),
            'price' => is_numeric($candidate['price'] ?? null)
                ? max(0, (int) $candidate['price'])
                : null,
            'currency' => 'IDR',
            'condition' => $this->condition($candidate['condition'] ?? null),
            'seller_name' => $this->nullableText(
                $candidate['seller_name'] ?? null,
                255,
            ),
            'location' => $this->nullableText(
                $candidate['location'] ?? null,
                255,
            ),
            'product_url' => $url,
            'normalized_url_hash' => $urlHash,
            'grounding_sources' => $sources,
            'raw_payload' => ['provider_candidate' => $candidate],
            'validation_status' => ListingCandidate::VALIDATION_INVALID,
            'review_status' => ListingCandidate::REVIEW_INVALID,
            'rejection_reason' => $this->nullableText($reason, 500),
        ]);
    }

    private function condition(mixed $condition): string
    {
        $condition = strtolower(trim((string) $condition));

        return in_array($condition, Listing::conditions(), true)
            ? $condition
            : Listing::CONDITION_UNKNOWN;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === ''
            ? null
            : mb_substr($value, 0, $maximum, 'UTF-8');
    }

    private function providerDescriptor(): string
    {
        $provider = strtolower(trim((string) config(
            'listing-discovery.provider',
            'tavily',
        )));

        if ($provider === 'tavily') {
            return 'search:'.(string) config(
                'listing-discovery.tavily.search_depth',
                'basic',
            ).'+probe:'.((bool) config(
                'listing-discovery.http_probe.enabled',
                true,
            ) ? 'enabled' : 'disabled').'+extract:'.((bool) config(
                'listing-discovery.tavily.extract_fallback_only',
                true,
            ) ? 'fallback-' : '').(string) config(
                'listing-discovery.tavily.extract_depth',
                'basic',
            );
        }

        return (string) config(
            'listing-discovery.gemini.model',
            'gemini-2.5-flash',
        );
    }
}
