<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('agt:dispatch-due-submissions')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('payments:recover')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Once a day is enough: profiles are dated to a day, not a moment, and the
// generator catches up on anything it missed.
Schedule::command('billing:generate-recurring-invoices')
    ->dailyAt('06:00')
    ->timezone('Africa/Luanda')
    ->withoutOverlapping();

// The evening before, so whoever runs an avença can skip it this once or raise
// it now; ignored, the morning run above goes ahead as always.
Schedule::command('recurring:remind-due')
    ->dailyAt('18:00')
    ->timezone('Africa/Luanda')
    ->withoutOverlapping();

// Before the day's work starts, so the copy is of a quiet database.
Schedule::command('backup:run')
    ->dailyAt('03:00')
    ->timezone('Africa/Luanda')
    ->withoutOverlapping();

// After the avenças have run, so a document raised this morning is already
// there when the sweep looks at what is owed.
Schedule::command('notifications:scan')
    ->dailyAt('07:00')
    ->timezone('Africa/Luanda')
    ->withoutOverlapping();

// Hourly, because each company closes on its own clock; the command only
// speaks for those whose closing hour it is, once a day.
Schedule::command('notifications:daily-close')
    ->hourlyAt(0)
    ->withoutOverlapping();

Schedule::command('reports:prune-exports')->hourly()->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')
    ->daily()
    ->withoutOverlapping();
