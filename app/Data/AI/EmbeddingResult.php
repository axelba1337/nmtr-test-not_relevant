<?php

namespace App\Data\AI;

use App\Data\IntegrationFailure;

final readonly class EmbeddingResult
{
    /**
     * @param  list<float>|null  $vector
     */
    private function __construct(
        public ?array $vector,
        public string $model,
        public int $dimensions,
        public ?IntegrationFailure $failure,
    ) {}

    /** @param list<float> $vector */
    public static function success(
        array $vector,
        string $model,
        int $dimensions,
    ): self {
        return new self($vector, $model, $dimensions, null);
    }

    public static function failed(
        string $model,
        int $dimensions,
        IntegrationFailure $failure,
    ): self {
        return new self(null, $model, $dimensions, $failure);
    }

    public function succeeded(): bool
    {
        return $this->vector !== null;
    }
}
