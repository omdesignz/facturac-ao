<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Work Session
    |--------------------------------------------------------------------------
    |
    | A work session is a fixed block of time that begins when a user signs in
    | and runs down regardless of what they are doing. It is not an idle timer:
    | refreshing the page, or leaving and coming back, does not extend it,
    | because the deadline lives in the server session rather than the browser.
    |
    | Each user chooses their own length; these values bound that choice and
    | supply the default.
    |
    */

    'enabled' => (bool) env('WORK_SESSION_ENABLED', true),

    'default_minutes' => (int) env('WORK_SESSION_DEFAULT_MINUTES', 25),

    'min_minutes' => (int) env('WORK_SESSION_MIN_MINUTES', 5),

    'max_minutes' => (int) env('WORK_SESSION_MAX_MINUTES', 480),

    /*
    | How long before the deadline the user is warned and offered a new block.
    */
    'warning_seconds' => (int) env('WORK_SESSION_WARNING_SECONDS', 60),

    /*
    | Session key holding the moment the current block started.
    */
    'started_at_key' => 'work_session_started_at',

];
