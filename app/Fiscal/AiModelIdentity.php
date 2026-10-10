<?php

namespace App\Fiscal;

/** Output identity for the sole reviewed adapter; not a caller-selectable catalogue. */
enum AiModelIdentity
{
    case LegacyAnthropicIntent;

    public function provider(): string
    {
        return 'anthropic';
    }

    public function model(): string
    {
        return AssistantProviderProfile::MODEL;
    }

    public function profile(): string
    {
        return AssistantProviderProfile::ID;
    }
}
