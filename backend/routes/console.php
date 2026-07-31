<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Drive the persistent world forward. In production run `php artisan schedule:work`
// (or a system cron calling `schedule:run`) so the economy, events and shipments
// keep advancing even while no player is online.
Schedule::command('world:tick --quiet-summary')
    ->everyMinute()
    ->withoutOverlapping();
