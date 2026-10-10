<?php

namespace App\Fiscal;

use App\Fiscal\Agt\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Transaction/replay protocol; only the two reviewed closed registry entries are enabled. */
final class CommandIdempotency
{
    public function __construct(private readonly CanonicalJson $json) {}

    /** @return array{body: string, replayed: bool} */
    public function execute(IntegrationCommandContext $context, #[\SensitiveParameter] string $key, #[\SensitiveParameter] string $fingerprint, \Closure $effect, CommandCapability $capability = CommandCapability::CustomerCreate): array
    {
        abort_unless(DB::getDriverName() === 'pgsql' || (DB::getDriverName() === 'sqlite' && app()->environment('testing')), 503);
        abort_unless(DB::connection()->transactionLevel() === 0, 503);
        abort_unless(preg_match('/\A[\x20-\x7e]{1,128}\z/', $key) === 1 && trim($key) !== '', 422);
        $context->authorize(capability: $capability);
        abort_unless(preg_match('/\A[0-9a-f]{64}\z/', $fingerprint) === 1, 503);
        CommandAudit::record($context, 'external.command.attempted', 'attempted', capability: $capability);
        $namespace = ['integration_id' => $context->integrationId, 'workspace_id' => $context->workspaceId, 'legal_entity_id' => $context->entityId,
            'environment' => $context->environment, 'command' => $capability->value, 'capability_version' => 1, 'key_hash' => hash('sha256', $key)];
        $operationId = null;
        $committing = false;
        try {
            DB::beginTransaction();
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
                DB::statement("SET LOCAL lock_timeout='1s'");
                DB::statement("SET LOCAL statement_timeout='2s'");
                DB::statement("SET LOCAL transaction_timeout='5s'");
            }
            $started = hrtime(true);
            $sponsor = $context->authorize(locked: true, capability: $capability);
            $operation = DB::table('external_command_operations')->where($namespace)->first();
            $reservedId = null;
            if ($operation === null) {
                DB::table('external_command_capacity')->insertOrIgnore(['integration_id' => $context->integrationId, 'completed_count' => 0]);
                abort_unless((int) DB::table('external_command_capacity')->where('integration_id', $context->integrationId)->value('completed_count') < 100000, 503);
                $reservedId = (string) Str::uuid();
                DB::table('external_command_operations')->insertOrIgnore([...$namespace, 'operation_id' => $reservedId,
                    'origin_credential_id' => $context->credentialId, 'origin_sponsor_user_id' => $context->sponsorId,
                    'origin_sponsor_attribution_id' => $sponsor->attribution_id, 'fingerprint_hash' => $fingerprint,
                    'canonicalizer_version' => 'command-json-v1', 'state' => 'executing', 'created_at' => $this->time()]);
                $operation = DB::table('external_command_operations')->where($namespace)->first();
                abort_unless($operation !== null, 503);
            }
            if ($operation->state === 'succeeded') {
                abort_unless($operation->canonicalizer_version === 'command-json-v1', 503);
                abort_unless(hash_equals($operation->fingerprint_hash, $fingerprint), 409, 'IDEMPOTENCY_CONFLICT');
                $operationId = $operation->operation_id;
                $expected = $this->json->encode(['data' => ['public_id' => $operation->result_public_id], 'meta' => ['operation_id' => $operationId]]);
                abort_unless($operation->http_status === 201 && hash_equals($expected, $operation->response_body), 503);
                CommandAudit::record($context, 'external.command.authorized', 'authorized', $operationId, capability: $capability);
                CommandAudit::record($context, 'external.command.replayed', 'succeeded', $operationId, capability: $capability);
                $body = $operation->response_body;
                $replayed = true;
            } else {
                abort_unless($reservedId !== null && $operation->operation_id === $reservedId && $operation->state === 'executing', 503);
                $operationId = $reservedId;
                CommandAudit::record($context, 'external.command.authorized', 'authorized', $operationId, capability: $capability);
                $publicId = $effect($operationId);
                abort_unless(is_string($publicId) && preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $publicId) === 1, 503);
                $body = $this->json->encode(['data' => ['public_id' => $publicId], 'meta' => ['operation_id' => $operationId]]);
                abort_unless(strlen($body) <= 1024, 503);
                DB::table('external_command_operations')->where('id', $operation->id)->update(['state' => 'succeeded', 'result_public_id' => $publicId,
                    'http_status' => 201, 'response_body' => $body, 'completed_at' => $this->time()]);
                $replayed = false;
            }
            $context->authorize(capability: $capability);
            abort_if(hrtime(true) - $started > 5_000_000_000, 503);
            $now = $this->time();
            DB::table('integration_credentials')->where('id', $context->credentialId)
                ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $now))->update(['last_used_at' => $now]);
            $committing = true;
            DB::commit();

            return ['body' => $body, 'replayed' => $replayed];
        } catch (\Throwable $exception) {
            $this->rollback();
            $conflict = $exception instanceof HttpException && $exception->getMessage() === 'IDEMPOTENCY_CONFLICT';
            CommandAudit::record($context, $conflict ? 'external.command.idempotency-conflict' : 'external.command.failed', $committing ? 'outcome_unknown' : 'rolled_back', $conflict ? null : $operationId, capability: $capability);
            if ($exception instanceof QueryException && ($exception->errorInfo[0] ?? '') === '23505' && str_contains((string) ($exception->errorInfo[2] ?? ''), '"'.($capability === CommandCapability::CustomerCreate ? 'customers_entity_tax_id_unique' : 'catalogue_items_entity_code_unique').'"')) {
                throw new HttpException(409, $capability->conflict());
            }
            if ($exception instanceof QueryException && DB::getDriverName() === 'sqlite' && str_contains((string) ($exception->errorInfo[2] ?? ''), ($capability === CommandCapability::CustomerCreate ? 'UNIQUE constraint failed: customers.legal_entity_id, customers.tax_identification_number' : 'UNIQUE constraint failed: catalogue_items.legal_entity_id, catalogue_items.code'))) {
                throw new HttpException(409, $capability->conflict());
            }
            throw $exception;
        }
    }

    private function rollback(): void
    {
        while (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }
    }

    private function time(): string
    {
        return DB::getDriverName() === 'pgsql' ? DB::selectOne('SELECT clock_timestamp() AS value')->value : now()->format('Y-m-d H:i:s.u');
    }
}
