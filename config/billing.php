<?php

return [
    'gateway' => env('BILLING_GATEWAY', 'wipay'),

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

    'pay4all' => [
        'environment' => env('PAY4ALL_ENVIRONMENT', 'simulation'),
        'production_enabled' => (bool) env('PAY4ALL_PRODUCTION_ENABLED', false),
        'merchant_id' => env('PAY4ALL_MERCHANT_ID'),
        'api_key' => env('PAY4ALL_API_KEY'),
        'webhook_secret' => env('PAY4ALL_WEBHOOK_SECRET'),
        'simulation_entity' => env('PAY4ALL_SIMULATION_ENTITY', '00000'),
        'reference_lifetime_hours' => (int) env('PAY4ALL_REFERENCE_LIFETIME_HOURS', 24),
        'transaction_fee_basis_points' => (int) env('PAY4ALL_TRANSACTION_FEE_BASIS_POINTS', 100),
        'maximum_reference_amount_minor' => (int) env('PAY4ALL_MAXIMUM_REFERENCE_AMOUNT_MINOR', 3_000_000_000),
    ],
];
