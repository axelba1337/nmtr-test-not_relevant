<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('marketplace:sync olx --mode=scheduled')
    ->everySixHours()
    ->withoutOverlapping(360);
