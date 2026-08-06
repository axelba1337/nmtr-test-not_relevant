<?php

namespace App\Data;

final readonly class IntegrationFailure
{
    public function __construct(
        public string $provider,
        public string $operation,
        public string $category,
        public string $safeMessage,
        public ?string $model = null,
        public ?int $status = null,
        public ?string $upstreamCode = null,
    ) {}

    /**
     * @return array{
     *     provider: string,
     *     operation: string,
     *     category: string,
     *     message: string,
     *     model: string|null,
     *     status: int|null,
     *     upstream_code: string|null
     * }
     */
    public function toSafeArray(): array
    {
        return [
            'provider' => $this->provider,
            'operation' => $this->operation,
            'category' => $this->category,
            'message' => $this->safeMessage,
            'model' => $this->model,
            'status' => $this->status,
            'upstream_code' => $this->upstreamCode,
        ];
    }
}
