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

];
