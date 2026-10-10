<?php

use App\Fiscal\AssistantProviderProfile;

return [
    'enabled' => (bool) env('ASSISTANT_ENABLED', false),
    'provider' => [
        'enabled' => false,
        'egress_enabled' => false,
        // Protected deployment override: absolute CLI path matching the running PHP version; never PATH lookup.
        'dns_php_cli' => null,
        'profile' => AssistantProviderProfile::ID,
        'price_profile' => AssistantProviderProfile::PRICE_ID,
        'budget_id' => null,
        'approval_reference' => null,
        'secret_reference' => null,
        'notice_url' => null,
        'notice_version' => null,
        'budgets' => ['attempt' => 0, 'user_day' => 0, 'workspace_day' => 0, 'workspace_month' => 0, 'deployment_day' => 0, 'deployment_month' => 0],
    ],
];
