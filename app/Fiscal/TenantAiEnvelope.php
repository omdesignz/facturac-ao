<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/** Encrypted bytes are never a normal model/resource projection. */
final readonly class TenantAiEnvelope
{
    private \SensitiveParameterValue $ciphertext;

    private \SensitiveParameterValue $wrapped;

    private \SensitiveParameterValue $version;

    private function __construct(#[\SensitiveParameter] string $ciphertext, #[\SensitiveParameter] string $wrapped, #[\SensitiveParameter] string $version)
    {
        $this->ciphertext = new \SensitiveParameterValue($ciphertext);
        $this->wrapped = new \SensitiveParameterValue($wrapped);
        $this->version = new \SensitiveParameterValue($version);
    }

    /** @param array<string, int|string> $binding */
    public static function seal(#[\SensitiveParameter] string $secret, array $binding, TenantAiKeyFile $keys): self
    {
        try {
            if (preg_match('/\A[\x20-\x7e]{1,512}\z/', $secret) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            $keys->rejectVapCopy($secret);
            $version = config('tenant_ai.kek_version');
            if (! is_string($version)) {
                throw new TenantAiStorageUnavailable;
            }
            $kek = $keys->read($version);
            $dek = random_bytes(32);
            $cipher = (new Encrypter($dek, 'aes-256-gcm'))->encryptString(json_encode(['binding' => $binding, 'secret' => $secret], JSON_THROW_ON_ERROR));
            $wrapped = (new Encrypter($kek, 'aes-256-gcm'))->encryptString(json_encode(['binding' => $binding, 'dek' => base64_encode($dek), 'kek_version' => $version], JSON_THROW_ON_ERROR));

            return new self($cipher, $wrapped, $version);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    /** Direct prepared insert avoids Laravel query events exposing envelope bindings.
     * @param  array<string, mixed>  $row
     */
    public function insert(TenantAiContext $context, array $row): void
    {
        $actor = $context->authorize();
        if (array_keys($row) !== ['id', 'connection_id', 'workspace_id', 'deployment_id', 'version_number', 'creator_attribution_id', 'creator_user_id', 'created_at', 'updated_at']
            || $row['workspace_id'] !== $context->workspaceId || $row['deployment_id'] !== $context->deploymentId
            || $row['creator_attribution_id'] !== $actor->attribution_id || $row['creator_user_id'] !== $actor->id
            || DB::transactionLevel() < 1) {
            throw new TenantAiStorageUnavailable;
        }
        $pdo = DB::connection()->getPdo();
        $values = [...$row, 'secret_ciphertext' => $this->ciphertext->getValue(), 'wrapped_dek' => $this->wrapped->getValue(), 'kek_version' => $this->version->getValue()];
        $statement = $pdo->prepare('INSERT INTO tenant_ai_credentials ('.implode(',', array_keys($values)).') VALUES ('.implode(',', array_fill(0, count($values), '?')).')');
        try {
            $statement->execute(array_values($values));
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        } finally {
            $statement->closeCursor();
        }
    }

    /** @param array<string, mixed> $row
     * @param  array<string, int|string>  $binding
     */
    public static function inspectForStorageTest(TenantAiContext $context, #[\SensitiveParameter] array $row, array $binding, TenantAiKeyFile $keys, #[\SensitiveParameter] \Closure $assert): void
    {
        try {
            if (! app()->runningUnitTests() || ! app()->environment('testing') || PHP_SAPI !== 'cli') {
                throw new TenantAiStorageUnavailable;
            }
            $context->authorize();
            if (($binding['workspace_public_id'] ?? null) !== $context->workspacePublicId || ($binding['deployment_id'] ?? null) !== $context->deploymentId
                || (int) ($row['workspace_id'] ?? 0) !== $context->workspaceId || ($row['deployment_id'] ?? null) !== $context->deploymentId
                || ($binding['version_id'] ?? null) !== ($row['id'] ?? null) || ($binding['connection_id'] ?? null) !== ($row['connection_id'] ?? null)) {
                throw new TenantAiStorageUnavailable;
            }
            $version = $row['kek_version'] ?? null;
            if (! is_string($version) || ($row['encryption_schema'] ?? null) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            $wrapped = self::decode($row['wrapped_dek'] ?? null, $keys->read($version));
            if (array_keys($wrapped) !== ['binding', 'dek', 'kek_version'] || $wrapped['kek_version'] !== $version || $wrapped['binding'] !== $binding || ! is_string($wrapped['dek'])) {
                throw new TenantAiStorageUnavailable;
            }
            $dek = base64_decode($wrapped['dek'], true);
            if (! is_string($dek) || strlen($dek) !== 32) {
                throw new TenantAiStorageUnavailable;
            }
            $payload = self::decode($row['secret_ciphertext'] ?? null, $dek);
            if (array_keys($payload) !== ['binding', 'secret'] || $payload['binding'] !== $binding || ! is_string($payload['secret'])
                || preg_match('/\A[\x20-\x7e]{1,512}\z/', $payload['secret']) !== 1) {
                throw new TenantAiStorageUnavailable;
            }
            $assert($payload['secret']);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
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

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['envelope' => 'redacted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
