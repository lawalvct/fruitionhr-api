<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Laravel\Telescope\Telescope;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

if (class_exists(Telescope::class)) {
    Schedule::command('telescope:prune --hours=48')->daily();
}

// Payments get missed: customers close the browser before the callback fires
// and webhooks occasionally never land. Anything money-related needs a sweep.
Schedule::command('billing:reconcile')->everyFifteenMinutes()->withoutOverlapping();
