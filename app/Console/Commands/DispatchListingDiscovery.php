<?php

namespace App\Console\Commands;

use App\Services\Marketplace\ListingDiscoveryService;
use Illuminate\Console\Command;

final class DispatchListingDiscovery extends Command
{
    protected $signature = 'listing-discovery:dispatch';

    protected $description = 'Queue a bounded listing discovery run';

    public function handle(ListingDiscoveryService $discovery): int
    {
        if (! (bool) config('listing-discovery.enabled', false)) {
            $this->warn('Listing discovery is disabled.');

            return self::SUCCESS;
        }

        $run = $discovery->queue();
        $this->info("Listing discovery run {$run->id} queued.");

        return self::SUCCESS;
    }
}
