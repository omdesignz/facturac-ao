<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cooling off
    |--------------------------------------------------------------------------
    |
    | How long the daily sweep stays quiet about something it has already
    | raised. Long enough that an overdue invoice is not mentioned every
    | morning, short enough that one still owing after a fortnight comes back
    | around rather than being silently forgotten.
    |
    */

    'cooling_off_days' => (int) env('NOTIFICATION_COOLING_OFF_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | Daily close
    |--------------------------------------------------------------------------
    |
    | The hour, on each company's own clock, when owners and administrators
    | who asked for it get the day's sales, money received and what is still
    | owed. Late enough that the shop has shut, early enough to read at dinner.
    |
    */

    'daily_close_hour' => (int) env('NOTIFICATION_DAILY_CLOSE_HOUR', 19),

];
