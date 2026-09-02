<?php

namespace App\Services\Search;

use Closure;

final class SearchExecutionBudget
{
    private ?float $startedAt = null;

    public function __construct(
        private readonly ?Closure $clock = null,
    ) {}

    public function start(): void
    {
        $this->startedAt = $this->now();
    }

    public function active(): bool
    {
        return $this->startedAt !== null;
    }

    public function elapsedMilliseconds(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        return max(0, (int) round(($this->now() - $this->startedAt) * 1000));
    }

    public function softExceeded(): bool
    {
        return $this->active()
            && $this->elapsedMilliseconds() >= $this->softMilliseconds();
    }

    public function hardExceeded(): bool
    {
        return $this->active()
            && $this->elapsedMilliseconds() >= $this->hardMilliseconds();
    }

    public function remainingTimeoutSeconds(int $configuredTimeout): int
    {
        if (! $this->active()) {
            return max(1, $configuredTimeout);
        }

        $remainingMilliseconds = max(
            1,
            $this->hardMilliseconds() - $this->elapsedMilliseconds(),
        );

        return max(1, min(
            $configuredTimeout,
            (int) ceil($remainingMilliseconds / 1000),
        ));
    }

    private function softMilliseconds(): int
    {
        return max(1000, (int) config(
            'chatbot.search.soft_timeout_seconds',
            20,
        ) * 1000);
    }

    private function hardMilliseconds(): int
    {
        $soft = $this->softMilliseconds();

        return max($soft, (int) config(
            'chatbot.search.hard_timeout_seconds',
            30,
        ) * 1000);
    }

    private function now(): float
    {
        return $this->clock === null
            ? microtime(true)
            : (float) ($this->clock)();
    }
}
