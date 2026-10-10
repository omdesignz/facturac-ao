<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiInvocationRefused;
use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/** Restricted one-operation consumer of the existing AES-GCM envelope for assistant intent only; no plaintext accessor. */
final class TenantAiInvocationSecret
{
    private ?\SensitiveParameterValue $value;

    private function __construct(#[\SensitiveParameter] string $value)
    {
        $this->value = new \SensitiveParameterValue($value);
    }

    public static function load(TenantAiInvocationSession $session, CredentialInvocationPermit $permit): self
    {
        $session->claimDecrypt($permit);
        if (DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        try {
            return DB::transaction(function () use ($session, $permit): self {
                $policy = $session->fresh($permit);
                $statement = DB::connection()->getPdo()->prepare('SELECT encryption_schema,secret_ciphertext,wrapped_dek,kek_version FROM tenant_ai_credentials WHERE id=? AND connection_id=? AND workspace_id=? AND deployment_id=?');
                try {
                    $statement->execute([$policy->credential->id, $policy->connection->id, $policy->connection->workspace_id, $policy->connection->deployment_id]);
                    $row = $statement->fetch(\PDO::FETCH_ASSOC);
                    if (! is_array($row) || (int) $row['encryption_schema'] !== 1 || ! is_string($row['kek_version'])) {
                        throw new TenantAiStorageUnavailable;
                    }
                    $binding = ['schema' => 1, 'deployment_id' => $policy->connection->deployment_id, 'workspace_public_id' => $policy->workspacePublicId,
                        'connection_id' => $policy->connection->id, 'version_id' => $policy->credential->id, 'provider' => $policy->connection->provider_key,
                        'credential_family' => $policy->connection->credential_family, 'endpoint_policy' => $policy->connection->endpoint_policy_key];
                    $keys = new TenantAiKeyFile;
                    $wrapped = self::decode($row['wrapped_dek'], $keys->read($row['kek_version']));
                    if (array_keys($wrapped) !== ['binding', 'dek', 'kek_version'] || $wrapped['binding'] !== $binding
                        || $wrapped['kek_version'] !== $row['kek_version'] || ! is_string($wrapped['dek'])) {
                        throw new TenantAiStorageUnavailable;
                    }
                    $dek = base64_decode($wrapped['dek'], true);
                    if (! is_string($dek) || strlen($dek) !== 32) {
                        throw new TenantAiStorageUnavailable;
                    }
                    $payload = self::decode($row['secret_ciphertext'], $dek);
                    if (array_keys($payload) !== ['binding', 'secret'] || $payload['binding'] !== $binding || ! is_string($payload['secret'])
                        || preg_match('/\A[\x20-\x7e]{1,512}\z/', $payload['secret']) !== 1) {
                        throw new TenantAiStorageUnavailable;
                    }
                    $keys->rejectVapCopy($payload['secret']);

                    return new self($payload['secret']);
                } finally {
                    $statement->closeCursor();
                    unset($row, $wrapped, $dek, $payload, $statement);
                }
            }, 1);
        } catch (TenantAiInvocationRefused $refusal) {
            throw new TenantAiInvocationRefused($refusal->reason);
        } catch (\Throwable) {
            throw new TenantAiInvocationRefused(AiInvocationDenial::BindingMismatch);
        }
    }

    /** Hands the value to exactly one exchange and forgets it on every outcome. */
    public function exchange(TenantAiInvocationSession $session, CredentialInvocationPermit $permit): string
    {
        if ($this->value === null) {
            throw new TenantAiStorageUnavailable;
        }
        try {
            return $session->observeSecret($this, $permit, $this->value->getValue());
        } finally {
            $this->value = null;
        }
    }

    /** @return array<string, mixed> */
    private static function decode(#[\SensitiveParameter] mixed $ciphertext, #[\SensitiveParameter] string $key): array
    {
        if (! is_string($ciphertext) || strlen($ciphertext) > 8192) {
            throw new TenantAiStorageUnavailable;
        }
        $value = json_decode((new Encrypter($key, 'aes-256-gcm'))->decryptString($ciphertext), true, 8, JSON_THROW_ON_ERROR);
        if (! is_array($value)) {
            throw new TenantAiStorageUnavailable;
        }

        return $value;
    }

    private function __clone() {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['secret' => 'redacted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
