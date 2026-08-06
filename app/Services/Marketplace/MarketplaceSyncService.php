<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\MarketplaceConnector;
use App\Data\Marketplace\MarketplaceListingData;
use App\Data\Marketplace\MarketplaceSyncReport;
use App\Data\Marketplace\MarketplaceSyncRequest;
use App\Helpers\Normalizer;
use App\Models\Listing;
use App\Models\MarketplaceSyncRun;
use App\Models\Platform;
use App\Models\Product;
use App\Services\Neomatra\ListingFingerprintService;
use DateTimeImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class MarketplaceSyncService
{
    public function __construct(
        private readonly MarketplaceConnectorRegistry $connectors,
        private readonly ListingFingerprintService $fingerprints,
    ) {}

    public function sync(
        Platform $platform,
        ?int $maxPages = null,
        ?int $initiatedBy = null,
    ): MarketplaceSyncReport {
        $startedAt = new DateTimeImmutable;
        $provider = trim((string) ($platform->connector
            ?: config('marketplace.provider', 'demo')));

        if (! $platform->is_active
            || ! $platform->sync_enabled
            || $platform->status === Platform::STATUS_DISABLED) {
            return $this->failedReport(
                $platform,
                $provider,
                $startedAt,
                'platform_not_syncable',
                'Platform tidak aktif atau sinkronisasi dinonaktifkan.',
            );
        }

        $connector = $this->connectors->find($provider, $platform->slug);

        if ($connector === null) {
            return $this->failedReport(
                $platform,
                $provider,
                $startedAt,
                'connector_unavailable',
                'Connector marketplace tidak tersedia untuk platform ini.',
            );
        }

        $lock = Cache::lock(
            'marketplace-sync:'.$platform->getKey(),
            max(10, (int) config('marketplace.lock_seconds', 300)),
        );

        try {
            $report = $lock->get(fn (): MarketplaceSyncReport => $this->run(
                $platform,
                $connector,
                $startedAt,
                $maxPages,
                $initiatedBy,
            ));
        } catch (LockTimeoutException) {
            $report = false;
        } catch (Throwable) {
            $this->markUnexpectedFailure($platform);

            return $this->failedReport(
                $platform,
                $provider,
                $startedAt,
                'sync_failure',
                'Sinkronisasi marketplace gagal diproses.',
            );
        }

        return $report instanceof MarketplaceSyncReport
            ? $report
            : $this->failedReport(
                $platform,
                $provider,
                $startedAt,
                'already_running',
                'Sinkronisasi platform ini sedang berjalan.',
            );
    }

    private function run(
        Platform $platform,
        MarketplaceConnector $connector,
        DateTimeImmutable $startedAt,
        ?int $maxPages,
        ?int $initiatedBy,
    ): MarketplaceSyncReport {
        $mode = (string) config('marketplace.sync_mode', 'manual');
        $mode = in_array($mode, MarketplaceSyncRun::modes(), true)
            ? $mode
            : MarketplaceSyncRun::MODE_MANUAL;
        $run = MarketplaceSyncRun::query()->create([
            'platform_id' => $platform->getKey(),
            'initiated_by' => $initiatedBy,
            'mode' => $mode,
            'status' => MarketplaceSyncRun::STATUS_RUNNING,
            'started_at' => $startedAt,
        ]);

        $platform->forceFill(['status' => Platform::STATUS_SYNCING])->save();
        $pageLimit = max(
            1,
            min(
                100,
                $maxPages ?? (int) config('marketplace.max_pages', 25),
            ),
        );
        $pageSize = max(
            1,
            min(500, (int) config('marketplace.page_size', 100)),
        );
        $cursor = null;
        $seen = 0;
        $created = 0;
        $updated = 0;
        $unchanged = 0;
        $skipped = 0;
        $failed = 0;
        $errorCode = null;
        $errorMessage = null;

        for ($page = 1; $page <= $pageLimit; $page++) {
            $result = $connector->fetch(new MarketplaceSyncRequest(
                $platform->slug,
                $cursor,
                $pageSize,
            ));

            if (! $result->succeeded()) {
                $failed++;
                $errorCode = $result->failure?->category
                    ?? 'connector_failure';
                $errorMessage = $result->failure?->safeMessage
                    ?? 'Connector marketplace gagal memproses data.';

                break;
            }

            $seen += count($result->listings) + $result->skipped;
            $skipped += $result->skipped;

            foreach ($result->listings as $listing) {
                try {
                    $outcome = $this->upsert(
                        $platform,
                        $listing,
                        $connector->key(),
                    );

                    match ($outcome) {
                        'created' => $created++,
                        'updated' => $updated++,
                        default => $unchanged++,
                    };
                } catch (Throwable) {
                    $failed++;
                }
            }

            $cursor = $result->nextCursor;

            if ($cursor === null) {
                break;
            }
        }

        if ($cursor !== null && $errorCode === null) {
            $errorCode = 'page_limit_reached';
            $errorMessage = 'Sinkronisasi dihentikan pada batas halaman aman.';
        }

        $finishedAt = new DateTimeImmutable;
        $status = match (true) {
            $failed > 0 && ($created + $updated + $unchanged) === 0 => MarketplaceSyncReport::STATUS_FAILED,
            $failed > 0 || $errorCode !== null => MarketplaceSyncReport::STATUS_PARTIAL,
            default => MarketplaceSyncReport::STATUS_COMPLETED,
        };

        $run->forceFill([
            'status' => $status,
            'records_seen' => $seen,
            'records_created' => $created,
            'records_updated' => $updated,
            'records_skipped' => $skipped + $unchanged,
            'records_failed' => $failed,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'finished_at' => $finishedAt,
        ])->save();

        $platformValues = [
            'status' => $status === MarketplaceSyncReport::STATUS_FAILED
                ? Platform::STATUS_ERROR
                : Platform::STATUS_READY,
        ];

        if ($status !== MarketplaceSyncReport::STATUS_FAILED) {
            $platformValues['last_synced_at'] = $finishedAt;
        }

        $platform->forceFill($platformValues)->save();

        return new MarketplaceSyncReport(
            $platform->slug,
            $connector->key(),
            $status,
            $seen,
            $created,
            $updated,
            $unchanged,
            $skipped,
            $failed,
            $errorCode,
            $errorMessage,
            $startedAt,
            $finishedAt,
        );
    }

    private function upsert(
        Platform $platform,
        MarketplaceListingData $incoming,
        string $connectorKey,
    ): string {
        $fingerprint = $this->fingerprints->make(
            $platform->slug,
            $incoming->externalListingId,
            $incoming->productUrl,
            $incoming->title,
            $incoming->price,
            $incoming->sellerName,
        );
        $query = Listing::query()->where('platform_id', $platform->getKey());

        if ($incoming->externalListingId !== null) {
            $listing = (clone $query)
                ->where('external_listing_id', $incoming->externalListingId)
                ->first();
            $listing ??= (clone $query)
                ->where('source_fingerprint', $fingerprint)
                ->first();
        } else {
            $listing = $query
                ->where('source_fingerprint', $fingerprint)
                ->first();
        }

        $listing ??= new Listing;
        $created = ! $listing->exists;
        $values = $incoming->toStorageArray();
        $values['platform_id'] = $platform->getKey();
        $productId = $this->resolveProductId($incoming);

        if ($created || $productId !== null || $incoming->hasProductHints()) {
            $values['product_id'] = $productId;
        }

        $values['source_fingerprint'] = $fingerprint;
        $values['raw_payload'] = [
            ...$incoming->toStorageArray(),
            'connector' => $connectorKey,
            'demo' => $connectorKey === 'demo',
            'product_reference' => $incoming->productReference,
            'product_brand' => $incoming->productBrand,
            'product_model' => $incoming->productModel,
            'product_variant' => $incoming->productVariant,
            'product_sku' => $incoming->productSku,
        ];
        $values['is_active'] = true;
        $listing->fill($values);
        $changed = $listing->isDirty();
        $listing->last_synced_at = now();
        $listing->save();

        return match (true) {
            $created => 'created',
            $changed => 'updated',
            default => 'unchanged',
        };
    }

    private function resolveProductId(
        MarketplaceListingData $listing,
    ): ?int {
        if ($listing->productReference !== null) {
            $productId = Product::query()
                ->active()
                ->where('normalized_key', $listing->productReference)
                ->value('id');

            if ($productId !== null) {
                return (int) $productId;
            }
        }

        if ($listing->productSku === null
            && ($listing->productModel === null
                || $listing->productVariant === null)) {
            return null;
        }

        $matches = [];

        foreach (Product::query()
            ->active()
            ->select([
                'id',
                'brand',
                'model',
                'variant',
                'sku_or_part_number',
            ])
            ->lazyById(100) as $product) {
            if (! $this->strictProductMatch($product, $listing)) {
                continue;
            }

            $matches[] = (int) $product->getKey();

            if (count($matches) === 2) {
                break;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function strictProductMatch(
        Product $product,
        MarketplaceListingData $listing,
    ): bool {
        foreach ([
            'brand' => $listing->productBrand,
            'model' => $listing->productModel,
            'variant' => $listing->productVariant,
            'sku_or_part_number' => $listing->productSku,
        ] as $field => $expected) {
            if ($expected !== null
                && Normalizer::text((string) $product->{$field})
                    !== Normalizer::text($expected)) {
                return false;
            }
        }

        return true;
    }

    private function failedReport(
        Platform $platform,
        string $provider,
        DateTimeImmutable $startedAt,
        string $errorCode,
        string $errorMessage,
    ): MarketplaceSyncReport {
        return new MarketplaceSyncReport(
            $platform->slug,
            $provider,
            MarketplaceSyncReport::STATUS_FAILED,
            0,
            0,
            0,
            0,
            0,
            0,
            $errorCode,
            $errorMessage,
            $startedAt,
            new DateTimeImmutable,
        );
    }

    private function markUnexpectedFailure(Platform $platform): void
    {
        try {
            $platform->forceFill(['status' => Platform::STATUS_ERROR])->save();
            $run = MarketplaceSyncRun::query()
                ->where('platform_id', $platform->getKey())
                ->where('status', MarketplaceSyncRun::STATUS_RUNNING)
                ->latest('id')
                ->first();

            $run?->forceFill([
                'status' => MarketplaceSyncRun::STATUS_FAILED,
                'records_failed' => 1,
                'error_code' => 'sync_failure',
                'error_message' => 'Sinkronisasi marketplace gagal diproses.',
                'finished_at' => now(),
            ])->save();
        } catch (Throwable) {
            // The safe report remains available even if status persistence fails.
        }
    }
}
