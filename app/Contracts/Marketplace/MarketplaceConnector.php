<?php

namespace App\Contracts\Marketplace;

use App\Data\Marketplace\MarketplaceFetchPage;
use App\Data\Marketplace\MarketplaceSyncRequest;

interface MarketplaceConnector
{
    public function key(): string;

    public function supports(string $platformSlug): bool;

    public function providesFullSnapshot(): bool;

    public function fetch(MarketplaceSyncRequest $request): MarketplaceFetchPage;
}
