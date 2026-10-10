<?php

$assistantBudget = env('ASSISTANT_PROVIDER_BUDGET_ID');

return [
    'deployment_id' => env('TENANT_AI_DEPLOYMENT_ID'),
    'kek_version' => null,
    'kek_root' => null,
    'kek_files' => [],
    // On PostgreSQL the assistant's own budget also counts attempts and output in one shared usage budget.
    'legacy_accounting' => is_string($assistantBudget) && $assistantBudget !== '' ? [$assistantBudget => [
        'deployment_id' => env('TENANT_AI_DEPLOYMENT_ID'),
        'usage_budget_id' => env('ASSISTANT_PROVIDER_USAGE_BUDGET_ID'),
        'reconciled' => true,
        'limits' => [
            'deployment_month' => ['reserved_attempt_units' => 100000, 'reserved_output_units' => 102400000],
            'deployment_day' => ['reserved_attempt_units' => 6000, 'reserved_output_units' => 6144000],
            'workspace_month' => ['reserved_attempt_units' => 10000, 'reserved_output_units' => 10240000],
            'workspace_day' => ['reserved_attempt_units' => 600, 'reserved_output_units' => 614400],
            'user_day' => ['reserved_attempt_units' => 120, 'reserved_output_units' => 122880],
        ],
    ]] : [],
    'verification' => [
        'enabled' => false,
        'anthropic_egress_enabled' => false,
        'profiles' => [],
        'accounts' => [],
        'receipt_key_root' => null,
        'signing_key_id' => null,
        'receipt_keys' => [],
    ],
];
