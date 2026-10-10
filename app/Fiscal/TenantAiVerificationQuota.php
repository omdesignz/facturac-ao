<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** CR1 synchronization only. Holding this lock never grants verification authority. */
final class TenantAiVerificationQuota
{
    private const NAMESPACE = 1180058417;

    private const DOMAIN = "facturac:tenant-ai:verification-admission:actor:v1\n";

    public static function key(string $actorAttributionId): int
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/', $actorAttributionId) !== 1) {
            throw new TenantAiStorageUnavailable;
        }
        $prefix = unpack('Nvalue', substr(hash('sha256', self::DOMAIN.$actorAttributionId, true), 0, 4));
        if (! is_array($prefix) || ! is_int($prefix['value'] ?? null)) {
            throw new TenantAiStorageUnavailable;
        }
        $unsigned = $prefix['value'];

        return $unsigned < 2147483648 ? $unsigned : $unsigned - 4294967296;
    }

    /** Must be the first lock in an outermost primary READ COMMITTED admission transaction. */
    public function acquire(TenantAiContext $context, float $deadline): void
    {
        $actor = $context->authorize();
        $this->transaction();
        $remaining = (int) floor(($deadline - hrtime(true) / 1e9) * 1000);
        if ($remaining < 1) {
            throw new TenantAiStorageUnavailable;
        }
        DB::selectOne("SELECT set_config('lock_timeout', ?, true), set_config('statement_timeout', ?, true)", [
            min(250, $remaining).'ms', min(1000, $remaining).'ms',
        ], false);
        DB::selectOne('SELECT pg_advisory_xact_lock(?::integer, ?::integer)', [self::NAMESPACE, self::key($actor->attribution_id)], false);
    }

    /** A separate post-lock statement snapshot; failed and future-dated admissions still count. */
    public function assertAvailable(TenantAiContext $context): CarbonImmutable
    {
        $actor = $context->authorize();
        $this->transaction();
        $key = self::key($actor->attribution_id);
        $held = DB::selectOne("SELECT EXISTS (
            SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid()
              AND database=(SELECT oid FROM pg_database WHERE datname=current_database())
              AND classid::bigint=? AND objid::bigint=? AND objsubid=2
              AND mode='ExclusiveLock' AND granted
        ) AS held", [self::NAMESPACE, $key < 0 ? $key + 4294967296 : $key], false);
        if (! $held->held) {
            throw new TenantAiStorageUnavailable;
        }
        $instant = CarbonImmutable::parse(DB::selectOne('SELECT clock_timestamp() AS instant', [], false)->instant)->utc();
        $ids = DB::table('assistant_provider_attempts')->useWritePdo()
            ->where('contract_version', 'gateway_v1')->where('purpose', 'connection_probe')
            ->where('actor_attribution_id', $actor->attribution_id)
            ->where('admitted_at', '>', $instant->subSeconds(3600)->format('Y-m-d H:i:s.uP'))
            ->orderBy('admitted_at')->orderBy('id')->limit(5)->pluck('id');
        if ($ids->count() >= 5) {
            throw new TenantAiStorageUnavailable;
        }

        return $instant;
    }

    private function transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 1
            || DB::selectOne('SHOW transaction_isolation', [], false)->transaction_isolation !== 'read committed') {
            throw new TenantAiStorageUnavailable;
        }
    }
}
