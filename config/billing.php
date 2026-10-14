<?php

return [
    'wipay' => [
        'environment' => env('WIPAY_ENVIRONMENT', 'sandbox'),
        'production_enabled' => (bool) env('WIPAY_PRODUCTION_ENABLED', false),
        'client_id' => env('WIPAY_CLIENT_ID', ''),
        'client_secret' => env('WIPAY_CLIENT_SECRET', ''),
        'callback_url' => env('WIPAY_CALLBACK_URL'),
        'connect_timeout_seconds' => (int) env('WIPAY_CONNECT_TIMEOUT', 3),
        'timeout_seconds' => (int) env('WIPAY_REQUEST_TIMEOUT', 15),
        'review_after_minutes' => (int) env('WIPAY_REVIEW_AFTER_MINUTES', 1440),
        'maximum_amount_minor' => (int) env('WIPAY_MAXIMUM_AMOUNT_MINOR', 3_000_000_000),
    ],
];
