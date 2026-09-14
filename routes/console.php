<?php

use Illuminate\Support\Facades\Schedule;

if ((bool) config('listing-discovery.schedule.enabled', false)) {
    Schedule::command('listing-discovery:dispatch')
        ->cron((string) config('listing-discovery.schedule.cron', '0 2 * * *'))
        ->timezone((string) config('app.timezone', 'Asia/Jakarta'))
        ->withoutOverlapping(180);
}
