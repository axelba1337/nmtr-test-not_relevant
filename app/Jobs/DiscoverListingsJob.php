<?php

namespace App\Jobs;

use App\Models\ListingDiscoveryRun;
use App\Services\Marketplace\ListingDiscoveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class DiscoverListingsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(public readonly int $runId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('listing-discovery-run'))
                ->expireAfter(420)
                ->dontRelease(),
        ];
    }

    public function handle(ListingDiscoveryService $discovery): void
    {
        $run = ListingDiscoveryRun::query()->find($this->runId);

        if (! $run instanceof ListingDiscoveryRun
            || $run->status !== ListingDiscoveryRun::STATUS_QUEUED) {
            return;
        }

        $discovery->execute($run);
    }
}
