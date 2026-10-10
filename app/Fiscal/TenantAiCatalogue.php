<?php

namespace App\Fiscal;

/** Immutable materialization of the accepted legacy profile; no execution grant. */
final class TenantAiCatalogue
{
    public const ID = '3a1f266e-8cb8-4b20-9bfa-aa72c7576a80';

    /** @return array<string, mixed> */
    public static function profile(): array
    {
        $values = ['id' => self::ID, 'profile_key' => AssistantProviderProfile::ID,
            'provider_key' => 'anthropic', 'credential_family' => 'anthropic-api-key-v1', 'endpoint_policy_key' => 'p7-anthropic-us-v1',
            'model_key' => AssistantProviderProfile::MODEL, 'response_model_key' => AssistantProviderProfile::MODEL,
            'protocol_key' => 'anthropic-messages-2023-06-01', 'price_key' => AssistantProviderProfile::PRICE_ID,
            'privacy_key' => 'p7-pc1', 'disclosure_key' => AssistantProviderProfile::POLICY, 'input_policy_key' => AssistantProviderProfile::POLICY,
            'purpose' => 'assistant_intent', 'allows_vap' => true, 'allows_customer' => false, 'monetary_applicable' => true,
            'currency' => 'USD', 'input_envelope' => AssistantProviderProfile::INPUT_ENVELOPE, 'output_envelope' => AssistantProviderProfile::OUTPUT_LIMIT,
            'request_byte_limit' => AssistantProviderProfile::BODY_LIMIT, 'response_byte_limit' => 65536, 'deadline_ms' => 10000,
            'reservation_micro_usd' => AssistantProviderProfile::reservation(), 'valid_from' => '2026-10-08T00:00:00+00:00',
            'valid_until' => AssistantProviderProfile::EXPIRES, 'created_at' => '2026-10-08T00:00:00+00:00'];

        return [...$values, 'manifest_sha256' => hash('sha256', json_encode($values, JSON_THROW_ON_ERROR))];
    }
}
