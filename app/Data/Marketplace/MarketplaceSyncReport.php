<?php

namespace App\Data\Marketplace;

use DateTimeImmutable;

final readonly class MarketplaceSyncReport
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        public string $platform,
        public string $provider,
        public string $status,
        public int $seen,
        public int $created,
        public int $updated,
        public int $unchanged,
        public int $skipped,
        public int $failed,
        public ?string $errorCode,
        public ?string $errorMessage,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $finishedAt,
    ) {}

    public function succeeded(): bool
    {
        return $this->status !== self::STATUS_FAILED;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'provider' => $this->provider,
            'status' => $this->status,
            'seen' => $this->seen,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'started_at' => $this->startedAt->format(DATE_ATOM),
            'finished_at' => $this->finishedAt->format(DATE_ATOM),
        ];
    }
}
