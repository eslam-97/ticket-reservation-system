<?php

use App\Console\Commands\ExpireReservations;
use App\Console\Commands\ReconcilePayments;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled housekeeping
|--------------------------------------------------------------------------
|
| Both commands are idempotent and both use withoutOverlapping(), so a run
| that takes longer than its interval never doubles up. Neither is load-
| bearing for correctness: reads and writes already apply the effective-status
| rule, and the reserve path reclaims stale holds inline, so a scheduler that
| is down costs tidiness rather than accuracy.
|
*/

Schedule::command(ExpireReservations::class)
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command(ReconcilePayments::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();
