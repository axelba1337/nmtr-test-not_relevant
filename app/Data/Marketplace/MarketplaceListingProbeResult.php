<?php

namespace App\Data\Marketplace;

final readonly class MarketplaceListingProbeResult
{
    public function __construct(
        public bool $attempted,
        public bool $succeeded,
        public ?string $title = null,
        public ?string $description = null,
        public ?int $price = null,
        public ?string $currency = null,
        public ?string $availability = null,
        public ?string $imageUrl = null,
    ) {}

    public static function skipped(): self
    {
        return new self(false, false);
    }

    public static function failed(): self
    {
        return new self(true, false);
    }
}
