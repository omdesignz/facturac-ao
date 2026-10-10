<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Illuminate\Support\Facades\DB;

/** Purpose-scoped accounting recovery. Never decrypts, transmits, reselects, refunds or issues authority. */
final class TenantAiInvocationRecovery
{
    public const STALE_SECONDS = 120;

    /** Classifies at most 100 stale inference attempts: unsent without a send mark, otherwise usage unknown. */
    public function recover(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            return 0;
        }
        if (DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $ids = DB::transaction(function () {
            TenantAiVerificationAdmission::limits(hrtime(true) / 1e9 + 10);

            return DB::table('assistant_provider_attempts')->useWritePdo()->where('contract_version', 'gateway_v1')->where('purpose', 'assistant_intent')
                ->where('state', 'admitted')->whereRaw("admitted_at < clock_timestamp() - interval '".self::STALE_SECONDS." seconds'")
                ->orderBy('admitted_at')->orderBy('id')->limit(100)->pluck('id');
        }, 1);
        $completed = 0;
        $admission = new TenantAiInvocationAdmission;
        foreach ($ids as $id) {
            try {
                $completed += $admission->settle($id, null, false, false, null, self::STALE_SECONDS) ? 1 : 0;
            } catch (\Throwable) {
                throw new TenantAiStorageUnavailable;
            }
        }

        return $completed;
    }
}
