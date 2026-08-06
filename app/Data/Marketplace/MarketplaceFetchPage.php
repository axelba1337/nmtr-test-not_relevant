<?php

namespace App\Data\Marketplace;

use App\Data\IntegrationFailure;

final readonly class MarketplaceFetchPage
{
    /** @param list<MarketplaceListingData> $listings */
    private function __construct(
        public array $listings,
        public ?string $nextCursor,
        public int $skipped,
        public ?IntegrationFailure $failure,
    ) {}

    /** @param list<MarketplaceListingData> $listings */
    public static function success(
        array $listings,
        ?string $nextCursor,
        int $skipped = 0,
    ): self {
        return new self(
            array_values($listings),
            $nextCursor,
            max(0, $skipped),
            null,
        );
    }

    public static function failed(IntegrationFailure $failure): self
    {
        return new self([], null, 0, $failure);
    }

    public function succeeded(): bool
    {
        return $this->failure === null;
    }
}
