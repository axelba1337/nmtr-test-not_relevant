<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class MarketplaceScheduleTest extends TestCase
{
    public function test_only_olx_is_scheduled_every_six_hours_without_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());
        $marketplaceEvents = $events->filter(
            static fn ($event): bool => str_contains(
                (string) $event->command,
                'marketplace:sync',
            ),
        )->values();

        $this->assertCount(1, $marketplaceEvents);
        $event = $marketplaceEvents->first();
        $this->assertStringContainsString(
            'marketplace:sync olx --mode=scheduled',
            (string) $event->command,
        );
        $this->assertSame('0 */6 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(360, $event->expiresAt);

        foreach (['tokopedia', 'shopee', 'facebook', 'carousell'] as $slug) {
            $this->assertStringNotContainsString(
                "marketplace:sync {$slug}",
                $marketplaceEvents->pluck('command')->implode('\n'),
            );
        }
    }
}
