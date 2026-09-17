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
        public array $diagnostics,
        public ?string $partialFailureCategory,
        public ?string $partialFailureMessage,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  list<array{title:string,url:string}>  $sources
     */
    public static function success(
        array $candidates,
        array $sources,
        array $diagnostics = [],
        ?string $partialFailureCategory = null,
        ?string $partialFailureMessage = null,
    ): self {
        return new self(
            $candidates,
            $sources,
            null,
            null,
            $diagnostics,
            $partialFailureCategory,
            $partialFailureMessage,
        );
    }

    public static function failed(
        string $category,
        string $message,
        array $diagnostics = [],
    ): self {
        return new self(
            [],
            [],
            $category,
            $message,
            $diagnostics,
            null,
            null,
        );
    }

    public function succeeded(): bool
    {
        return $this->failureCategory === null;
    }

    public function hasTechnicalFailure(): bool
    {
        return $this->failureCategory !== null
            || $this->partialFailureCategory !== null;
    }
}
