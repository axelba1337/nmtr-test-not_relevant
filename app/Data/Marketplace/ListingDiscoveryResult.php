<?php

namespace App\Data\Marketplace;

final readonly class ListingDiscoveryResult
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array{title:string,url:string}>  $sources
     */
    private function __construct(
        public array $candidates,
        public array $sources,
        public ?string $failureCategory,
        public ?string $failureMessage,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array{title:string,url:string}>  $sources
     */
    public static function success(array $candidates, array $sources): self
    {
        return new self($candidates, $sources, null, null);
    }

    public static function failed(string $category, string $message): self
    {
        return new self([], [], $category, $message);
    }

    public function succeeded(): bool
    {
        return $this->failureCategory === null;
    }
}
