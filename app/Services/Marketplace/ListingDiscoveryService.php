<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\ListingDiscoveryProvider;
use App\Jobs\DiscoverListingsJob;
use App\Models\Listing;
use App\Models\ListingCandidate;
use App\Models\ListingDiscoveryRun;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
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

                $run = ListingDiscoveryRun::query()->create([
                    'created_by' => $userId,
                    'status' => ListingDiscoveryRun::STATUS_QUEUED,
                    'provider' => (string) config(
                        'listing-discovery.provider',
                        'gemini',
                    ),
                    'model' => (string) config(
                        'listing-discovery.model',
                        'gemini-2.5-flash',
                    ),
                    'region' => (string) config(
                        'listing-discovery.region',
                        'Indonesia',
                    ),
                ]);
                DiscoverListingsJob::dispatch($run->id)
                    ->onQueue('listing-discovery');

                return $run;
            },
        );
    }

    public function execute(ListingDiscoveryRun $run): void
    {
        $run->forceFill([
            'status' => ListingDiscoveryRun::STATUS_RUNNING,
            'started_at' => now(),
            'finished_at' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();
        $checked = 0;
        $found = 0;
        $valid = 0;
        $rejected = 0;
        $providerFailures = 0;
        $lastFailureCategory = null;
        $lastFailureMessage = null;
        $target = max(
            1,
            (int) config('listing-discovery.target_active_listings', 5),
        );
        $maximumProducts = min(100, max(
            1,
            (int) config('listing-discovery.max_products_per_run', 10),
        ));
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
            $products = $this->coverage->discoveryTargets(
                $target,
                $maximumProducts,
            );

            foreach ($products as $product) {
                $checked++;
                $result = $this->provider->discover($product);

                if (! $result->succeeded()) {
                    $providerFailures++;
                    $lastFailureCategory = $result->failureCategory;
                    $lastFailureMessage = $result->failureMessage;

                    continue;
                }

                $rawCandidates = array_slice(
                    $result->candidates,
                    0,
                    $maximumPerProduct,
                );
                $found += count($rawCandidates);
                $perPlatform = [];

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
                        $rejected++;

                        continue;
                    }

                    $perPlatform[$platformSlug] = ($perPlatform[$platformSlug] ?? 0) + 1;
                    $values = $this->candidateValues($candidate);
                    $preview = $this->csv->previewRecords([[
                        'row_number' => $index + 1,
                        'values' => $values,
                    ]], 'gemini-grounded-discovery');

                    if ($preview['rows'] === []) {
                        $this->storeInvalidCandidate(
                            $run,
                            $product,
                            $candidate,
                            $result->sources,
                            $preview['issues'][0] ?? 'candidate validation failed',
                        );
                        $rejected++;

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
                        $rejected++;

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
                    $valid++;
                }
            }

            $status = match (true) {
                $checked > 0 && $providerFailures === $checked => ListingDiscoveryRun::STATUS_FAILED,
                $providerFailures > 0 || $rejected > 0 => ListingDiscoveryRun::STATUS_PARTIAL,
                default => ListingDiscoveryRun::STATUS_COMPLETED,
            };
            $run->forceFill([
                'status' => $status,
                'products_checked' => $checked,
                'candidates_found' => $found,
                'valid_candidates' => $valid,
                'rejected_candidates' => $rejected,
                'provider_failures' => $providerFailures,
                'error_code' => $lastFailureCategory,
                'error_message' => $this->nullableText(
                    $lastFailureMessage,
                    500,
                ),
                'finished_at' => now(),
            ])->save();
        } catch (Throwable) {
            $run->forceFill([
                'status' => ListingDiscoveryRun::STATUS_FAILED,
                'products_checked' => $checked,
                'candidates_found' => $found,
                'valid_candidates' => $valid,
                'rejected_candidates' => $rejected,
                'provider_failures' => $providerFailures,
                'error_code' => 'processing_error',
                'error_message' => 'Listing discovery could not be completed.',
                'finished_at' => now(),
            ])->save();
        }
    }

    /** @param array<string, mixed> $candidate */
    private function candidateValues(array $candidate): array
    {
        $values = [];

        foreach (MarketplaceCsvImportService::HEADERS as $header) {
            $value = $candidate[$header] ?? null;
            $values[$header] = is_scalar($value) ? trim((string) $value) : null;
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
}
