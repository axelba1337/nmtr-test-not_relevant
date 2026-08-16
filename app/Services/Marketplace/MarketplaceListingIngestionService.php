<?php

namespace App\Services\Marketplace;

use App\Data\Marketplace\MarketplaceListingData;
use App\Helpers\Normalizer;
use App\Models\Listing;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\Neomatra\ListingFingerprintService;
use DateTimeInterface;
use InvalidArgumentException;

final class MarketplaceListingIngestionService
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_UPDATED = 'updated';

    public const OUTCOME_UNCHANGED = 'unchanged';

    public function __construct(
        private readonly ListingFingerprintService $fingerprints,
    ) {}

    public function ingest(
        Platform $platform,
        MarketplaceListingData $incoming,
        string $connectorKey,
        ?DateTimeInterface $observedAt = null,
    ): string {
        if ($platform->slug !== $incoming->platformSlug) {
            throw new InvalidArgumentException(
                'Platform listing tidak sesuai dengan target sinkronisasi.',
            );
        }

        $observedAt ??= now();
        $fingerprint = $this->fingerprints->make(
            $platform->slug,
            $incoming->externalListingId,
            $incoming->productUrl,
            $incoming->title,
            $incoming->price,
            $incoming->sellerName,
        );
        $urlFingerprint = $this->fingerprints->make(
            $platform->slug,
            null,
            $incoming->productUrl,
            $incoming->title,
            $incoming->price,
            $incoming->sellerName,
        );
        $query = Listing::query()->where(
            'platform_id',
            $platform->getKey(),
        );
        $listing = (clone $query)
            ->where('source_fingerprint', $fingerprint)
            ->first();

        if ($listing === null && $incoming->externalListingId !== null) {
            $listing = (clone $query)
                ->where('external_listing_id', $incoming->externalListingId)
                ->first();
        }

        if ($listing === null && $urlFingerprint !== $fingerprint) {
            $listing = (clone $query)
                ->where('source_fingerprint', $urlFingerprint)
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
            'source_payload' => $incoming->rawPayload,
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
        if ($listing->getAttribute('first_seen_at') === null) {
            $listing->setAttribute('first_seen_at', $observedAt);
        }

        $listing->last_seen_at = $observedAt;
        $listing->last_synced_at = $observedAt;
        $listing->save();

        return match (true) {
            $created => self::OUTCOME_CREATED,
            $changed => self::OUTCOME_UPDATED,
            default => self::OUTCOME_UNCHANGED,
        };
    }

    public function deactivateMissing(
        Platform $platform,
        string $connectorKey,
        DateTimeInterface $syncStartedAt,
    ): int {
        return Listing::query()
            ->where('platform_id', $platform->getKey())
            ->where('is_active', true)
            ->where('raw_payload->connector', $connectorKey)
            ->where(function ($query) use ($syncStartedAt): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', $syncStartedAt);
            })
            ->update(['is_active' => false]);
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

            $productId = $this->uniqueAliasProductId(
                $listing->productReference,
            );

            if ($productId !== null) {
                return $productId;
            }
        }

        if ($listing->productSku !== null
            || ($listing->productModel !== null
                && $listing->productVariant !== null)) {
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

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return $listing->hasProductHints()
            ? null
            : $this->uniqueAliasProductId($listing->title);
    }

    private function uniqueAliasProductId(string $alias): ?int
    {
        $productIds = ProductAlias::query()
            ->where('normalized_alias', Normalizer::text($alias))
            ->whereHas(
                'product',
                static fn ($query) => $query->active(),
            )
            ->distinct()
            ->limit(2)
            ->pluck('product_id');

        return $productIds->count() === 1
            ? (int) $productIds->first()
            : null;
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
}
