<?php

use App\Fiscal\AssistantProviderProfile;

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'provider' => [
        'enabled' => (bool) env('ASSISTANT_PROVIDER_ENABLED', false),
        'egress_enabled' => (bool) env('ASSISTANT_PROVIDER_EGRESS_ENABLED', false),
        // Protected deployment override: absolute CLI path matching the running PHP version; never PATH lookup.
        'dns_php_cli' => env('ASSISTANT_PROVIDER_DNS_PHP_CLI'),
        'profile' => AssistantProviderProfile::ID,
        'price_profile' => AssistantProviderProfile::PRICE_ID,
        'budget_id' => env('ASSISTANT_PROVIDER_BUDGET_ID'),
        'approval_reference' => env('ASSISTANT_PROVIDER_APPROVAL_REFERENCE'),
        // Absolute path of a file readable only by the application user that holds the provider key.
        'secret_reference' => env('ASSISTANT_PROVIDER_SECRET_FILE'),
        'notice_url' => env('ASSISTANT_PROVIDER_NOTICE_URL'),
        'notice_version' => env('ASSISTANT_PROVIDER_NOTICE_VERSION'),
        // Micro-USD reservation ceilings per window; zero keeps the window closed.
        'budgets' => [
            'attempt' => (int) env('ASSISTANT_PROVIDER_BUDGET_ATTEMPT', 0),
            'user_day' => (int) env('ASSISTANT_PROVIDER_BUDGET_USER_DAY', 0),
            'workspace_day' => (int) env('ASSISTANT_PROVIDER_BUDGET_WORKSPACE_DAY', 0),
            'workspace_month' => (int) env('ASSISTANT_PROVIDER_BUDGET_WORKSPACE_MONTH', 0),
            'deployment_day' => (int) env('ASSISTANT_PROVIDER_BUDGET_DEPLOYMENT_DAY', 0),
            'deployment_month' => (int) env('ASSISTANT_PROVIDER_BUDGET_DEPLOYMENT_MONTH', 0),
        ],
    ],
];
