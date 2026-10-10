<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Analytics\BillingSummaryQuery;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AnalyticsCapabilities
{
    public function __construct(private BillingSummaryQuery $query, private IntegrationRateLimiter $limiter) {}

    /** @return array<string, mixed> */
    public function billingSummary(DocumentReadContext $context, BillingSummaryCommand $command): array
    {
        abort_unless($context instanceof ExecutionContext || $context instanceof IntegrationReadContext, 403);
        abort_if(DB::connection()->transactionLevel() !== 0, 503);
        $context->authorize('analytics.billing.read');
        abort_unless($context->environment() === AgtEnvironment::Production, 403);
        $timezone = DB::table('legal_entities')->useWritePdo()->where('id', $context->legalEntityId())->where('workspace_id', $context->workspaceId())->value('timezone');
        abort_unless($timezone === 'Africa/Luanda', 503);
        $identity = $context instanceof IntegrationReadContext ? 'billing-integration:'.$context->integrationId : 'billing-human:'.$context->actorId.':'.$context->workspaceId();
        $this->limiter->consume([$identity => 6, 'billing-workspace:'.$context->workspaceId() => 30]);
        $store = Cache::store((string) config('integrations.cache_store'))->getStore();
        abort_unless($store instanceof DatabaseStore, 503);
        $lock = $store->lock('billing-query-workspace:'.$context->workspaceId(), 10);
        abort_unless($lock->get(), 429, '', ['Retry-After' => '1']);
        try {
            $context->authorize('analytics.billing.read');
            $data = $this->query->read($context, $command);
        } finally {
            $lock->release();
        }
        json_encode($data, JSON_THROW_ON_ERROR);
        $audit = $context->audit();
        RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event('analytics.billing.read')
            ->withProperties([...$audit, 'operation_id' => $audit['operation_id'] ?? (string) Str::uuid(), 'user_agent' => null,
                'operation' => 'analytics.billing.read', 'capability_version' => 2, 'metric_version' => BillingSummaryQuery::METRIC_VERSION,
                'method' => request()->method(), 'outcome' => 'succeeded'])->log('Scoped recorded billing read'));
        $context->recordSuccessfulUse();

        return $data;
    }
}
