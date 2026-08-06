<?php

namespace App\Data\Marketplace;

final readonly class MarketplaceSyncRequest
{
    public function __construct(
        public string $platformSlug,
        public ?string $cursor = null,
        public int $pageSize = 100,
    ) {}
}
