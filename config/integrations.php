<?php

return [
    'enabled' => (bool) env('EXTERNAL_READ_INTEGRATIONS_ENABLED', false),
    'commands_enabled' => (bool) env('EXTERNAL_COMMAND_INTEGRATIONS_ENABLED', false),
    'cache_store' => 'database',
];
