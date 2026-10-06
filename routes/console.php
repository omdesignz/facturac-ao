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

Schedule::command('billing:expire-emis-references')
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

// Safety net for any archive job that gave up, run before the backup so
// every issued document's PDF is in that night's copy.
Schedule::command('fiscal:archive-pdfs')
    ->dailyAt('02:30')
    ->timezone('Africa/Luanda')
    ->withoutOverlapping()
    ->onOneServer();

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
