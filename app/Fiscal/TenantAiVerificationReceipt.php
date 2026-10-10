<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Carbon\CarbonImmutable;

/** Pure receipt encoding/verification. A valid MAC never creates promotion authority. */
final class TenantAiVerificationReceipt
{
    public const FIELDS = [
        'schema_version',
        'issuer_policy_version',
        'evidence_id',
        'attempt_id',
        'deployment_id',
        'evidence_realm',
        'workspace_public_id',
        'legal_entity_public_id',
        'environment',
        'connection_id',
        'credential_version_id',
        'credential_version_number',
        'credential_created_at',
        'credential_wrap_revision',
        'expected_settings_revision',
        'expected_connection_revision',
        'prior_selected_version_id',
        'prior_active_generation',
        'proposed_activated_generation',
        'actor_attribution_id',
        'actor_membership_id',
        'profile_id',
        'profile_manifest_sha256',
        'provider_key',
        'credential_family',
        'endpoint_policy_key',
        'verifier_policy_sha256',
        'authority_references',
        'owner_approval_id',
        'account_mapping_sha256',
        'disclosure_id',
        'acknowledgement_id',
        'selection_revision',
        'entitlement_revision',
        'observed_organization_id',
        'observed_workspace_id',
        'claim_strength',
        'observed_model_id',
        'verification_outcome',
        'admitted_at',
        'send_authorized_at',
        'observed_at',
        'finalized_at',
        'promotion_expires_at',
        'promotion_disposition',
        'committed_activated_generation',
        'receipt_key_id',
    ];

    public const DOMAIN = "facturac:tenant-ai:verification:v1\n";

    /** @param array<string, mixed> $fields */
    public static function canonical(array $fields): string
    {
        if (array_keys($fields) !== self::FIELDS) {
            throw new TenantAiStorageUnavailable;
        }
        $nullable = ['prior_selected_version_id', 'committed_activated_generation'];
        $decimal = ['credential_version_number', 'credential_wrap_revision', 'expected_settings_revision',
            'expected_connection_revision', 'prior_active_generation', 'proposed_activated_generation', 'actor_membership_id',
            'owner_approval_id', 'acknowledgement_id', 'selection_revision', 'entitlement_revision', 'committed_activated_generation'];
        $uuid = ['evidence_id', 'attempt_id', 'deployment_id', 'connection_id', 'credential_version_id',
            'prior_selected_version_id', 'actor_attribution_id', 'profile_id', 'disclosure_id'];
        $times = ['credential_created_at', 'admitted_at', 'send_authorized_at', 'observed_at', 'finalized_at', 'promotion_expires_at'];
        foreach ($fields as $name => $value) {
            if ($name === 'authority_references') {
                $fields[$name] = self::references($value);

                continue;
            }
            if ($value === null && in_array($name, $nullable, true)) {
                continue;
            }
            if (! is_string($value) || strlen($value) > ($name === 'observed_workspace_id' ? 127 : 120) || preg_match('/\A[\x20-\x7e]+\z/', $value) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            if (in_array($name, $decimal, true)) {
                self::decimal($value, $name === 'prior_active_generation');
            }
            if (in_array($name, $uuid, true) && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $value) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            if ($name === 'observed_organization_id' && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/', $value) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            if (str_ends_with($name, '_sha256') && preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            if (str_ends_with($name, '_public_id') && preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $value) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            if (in_array($name, $times, true)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new \DateTimeZone('UTC'));
                if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $value) {
                    throw new TenantAiStorageUnavailable;
                }
            }
        }
        foreach (['schema_version' => ['verification-v1'], 'issuer_policy_version' => ['anthropic-model-metadata-v1'],
            'evidence_realm' => ['provider_tls', 'offline_fixture'], 'environment' => ['production'], 'provider_key' => ['anthropic'],
            'credential_family' => ['anthropic-api-key-v1'], 'claim_strength' => ['organization_and_workspace_observed'],
            'verification_outcome' => ['provider_authenticated_model_visible'],
            'promotion_disposition' => ['promoted', 'rejected_stale', 'rejected_policy', 'expired', 'abandoned']] as $field => $allowed) {
            if (! in_array($fields[$field], $allowed, true)) {
                throw new TenantAiStorageUnavailable;
            }
        }
        if (! is_string($fields['observed_workspace_id']) || preg_match('/\Awrkspc_[A-Za-z0-9]{1,120}\z/', $fields['observed_workspace_id']) !== 1
            || (($fields['promotion_disposition'] === 'promoted') !== ($fields['committed_activated_generation'] !== null))) {
            throw new TenantAiStorageUnavailable;
        }
        $bytes = self::DOMAIN.json_encode(array_values($fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($bytes) > 8192) {
            throw new TenantAiStorageUnavailable;
        }

        return $bytes;
    }

    /** @param array<string, mixed> $fields */
    public static function authentic(array $fields, string $mac, #[\SensitiveParameter] string $key): bool
    {
        try {
            return strlen($key) === 32 && preg_match('/\A[0-9a-f]{64}\z/', $mac) === 1
                && hash_equals(hash_hmac('sha256', self::canonical($fields), $key), $mac);
        } catch (\Throwable) {
            return false;
        }
    }

    /** Fixed persistence-to-receipt projection; this creates no authority.
     * @param  array<string, mixed>  $attempt
     * @return array<string, mixed>
     */
    public static function fields(array $attempt): array
    {
        $fields = [];
        foreach (self::FIELDS as $name) {
            $value = match ($name) {
                'schema_version' => 'verification-v1', 'issuer_policy_version' => $attempt['verifier_policy_key'] ?? null,
                'attempt_id' => $attempt['id'] ?? null, default => $attempt[$name] ?? null,
            };
            if ($name === 'authority_references' && is_string($value)) {
                $value = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
            } elseif (in_array($name, ['credential_created_at', 'admitted_at', 'send_authorized_at', 'observed_at', 'finalized_at', 'promotion_expires_at'], true) && is_string($value)) {
                $value = TenantAiVerificationAdmission::time(CarbonImmutable::parse($value));
            } elseif (is_int($value)) {
                $value = (string) $value;
            }
            $fields[$name] = $value;
        }

        return $fields;
    }

    private static function decimal(string $value, bool $zero = false): void
    {
        if (preg_match($zero ? '/\A(?:0|[1-9][0-9]*)\z/' : '/\A[1-9][0-9]*\z/', $value) !== 1
            || strlen($value) > 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') > 0)) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** @return list<array{string, string, string}> */
    private static function references(mixed $references): array
    {
        if (! is_array($references) || ! array_is_list($references) || count($references) < 1 || count($references) > 16) {
            throw new TenantAiStorageUnavailable;
        }
        $seen = [];
        foreach ($references as $reference) {
            if (! is_array($reference) || ! array_is_list($reference) || count($reference) !== 3
                || ! is_string($reference[0]) || ! is_string($reference[1]) || ! is_string($reference[2])
                || preg_match('/\A[a-z_]{1,40}\z/', $reference[0]) !== 1 || preg_match('/\A[a-z0-9-]{1,80}\z/', $reference[1]) !== 1
                || isset($seen[$reference[0].':'.$reference[1]])) {
                throw new TenantAiStorageUnavailable;
            }
            self::decimal($reference[2]);
            if ($reference[0] === 'owner_approval') {
                self::decimal($reference[1]);
            } elseif (! in_array($reference[0], ['gateway_control', 'budget_control'], true)
                || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $reference[1]) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            $seen[$reference[0].':'.$reference[1]] = true;
        }
        usort($references, fn (array $a, array $b): int => strcmp($a[0].':'.$a[1], $b[0].':'.$b[1]));

        return $references;
    }
}
