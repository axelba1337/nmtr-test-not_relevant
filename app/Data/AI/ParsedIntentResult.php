<?php

namespace App\Data\AI;

use App\Data\IntegrationFailure;

final readonly class ParsedIntentResult
{
    private function __construct(
        public ?ParsedIntent $intent,
        public ?IntegrationFailure $failure,
    ) {}

    public static function success(ParsedIntent $intent): self
    {
        return new self($intent, null);
    }

    public static function failed(IntegrationFailure $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->intent !== null;
    }
}
