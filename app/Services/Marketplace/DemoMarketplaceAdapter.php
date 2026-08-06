<?php

namespace App\Services\Marketplace;

use App\Contracts\Marketplace\MarketplaceConnector;
use App\Data\IntegrationFailure;
use App\Data\Marketplace\MarketplaceFetchPage;
use App\Data\Marketplace\MarketplaceListingData;
use App\Data\Marketplace\MarketplaceSyncRequest;

final class DemoMarketplaceAdapter implements MarketplaceConnector
{
    public function key(): string
    {
        return 'demo';
    }

    public function supports(string $platformSlug): bool
    {
        return in_array(
            strtolower(trim($platformSlug)),
            (array) config('marketplace.targets', []),
            true,
        );
    }

    public function fetch(
        MarketplaceSyncRequest $request,
    ): MarketplaceFetchPage {
        $platform = strtolower(trim($request->platformSlug));

        if (! $this->supports($platform)) {
            return MarketplaceFetchPage::failed(new IntegrationFailure(
                $this->key(),
                'marketplace_fetch',
                'unsupported_platform',
                'Platform tidak didukung oleh connector demo.',
            ));
        }

        $cursor = $request->cursor ?? '0';

        if (preg_match('/^[0-9]+$/', $cursor) !== 1) {
            return MarketplaceFetchPage::failed(new IntegrationFailure(
                $this->key(),
                'marketplace_fetch',
                'invalid_cursor',
                'Cursor sinkronisasi marketplace tidak valid.',
            ));
        }

        $records = config("marketplace.demo.listings.{$platform}", []);

        if (! is_array($records)) {
            return MarketplaceFetchPage::failed(new IntegrationFailure(
                $this->key(),
                'marketplace_fetch',
                'invalid_dataset',
                'Dataset demo marketplace tidak valid.',
            ));
        }

        $offset = (int) $cursor;
        $pageSize = max(1, min(500, $request->pageSize));
        $slice = array_slice(array_values($records), $offset, $pageSize);
        $listings = [];
        $skipped = 0;

        foreach ($slice as $record) {
            $listing = is_array($record)
                ? MarketplaceListingData::fromArray($platform, $record)
                : null;

            if ($listing === null) {
                $skipped++;

                continue;
            }

            $listings[] = $listing;
        }

        $nextOffset = $offset + count($slice);
        $nextCursor = $nextOffset < count($records)
            ? (string) $nextOffset
            : null;

        return MarketplaceFetchPage::success(
            $listings,
            $nextCursor,
            $skipped,
        );
    }
}
