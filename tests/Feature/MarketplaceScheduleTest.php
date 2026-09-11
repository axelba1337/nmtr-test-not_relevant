<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class MarketplaceScheduleTest extends TestCase
{
    public function test_no_marketplace_sync_is_scheduled_for_the_manual_mvp_sources(): void
    {
        $events = collect(app(Schedule::class)->events());
        $marketplaceEvents = $events->filter(
            static fn ($event): bool => str_contains(
                (string) $event->command,
                'marketplace:sync',
            ),
        )->values();

        $this->assertCount(0, $marketplaceEvents);
    }
}
