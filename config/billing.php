<?php

return [
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
