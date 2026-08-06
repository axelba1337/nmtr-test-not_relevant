<?php

namespace App\Data\AI;

use App\Data\IntegrationFailure;
use Illuminate\Http\Client\Response;

final readonly class ProviderHttpResult
{
    private function __construct(
        public ?Response $response,
        public ?IntegrationFailure $failure,
    ) {}

    public static function success(Response $response): self
    {
        return new self($response, null);
    }

    public static function failed(IntegrationFailure $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->response !== null;
    }
}
