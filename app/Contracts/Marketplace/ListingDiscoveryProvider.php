<?php

namespace App\Contracts\Marketplace;

use App\Data\Marketplace\ListingDiscoveryResult;
use App\Models\Product;

interface ListingDiscoveryProvider
{
    public function discover(
        Product $product,
        ?int $maximumCandidates = null,
    ): ListingDiscoveryResult;
}
