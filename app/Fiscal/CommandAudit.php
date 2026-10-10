<?php

namespace App\Fiscal;

use App\Models\CatalogueItem;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Contracts\Activity;

final class CommandAudit
{
    public static function record(IntegrationCommandContext|HumanCustomerCommandContext|HumanServiceCommandContext $context, string $event, string $outcome,
        ?string $operationId = null, Customer|CatalogueItem|null $customer = null, CommandCapability $capability = CommandCapability::CustomerCreate): void
    {
        abort_unless($customer === null || $customer instanceof ($capability->subjectClass()), 503);
        abort_unless(! ($context instanceof HumanCustomerCommandContext) || $capability === CommandCapability::CustomerCreate, 403);
        abort_unless(! ($context instanceof HumanServiceCommandContext) || $capability === CommandCapability::ServiceCreate, 403);
        if (! config('activitylog.enabled', true)) {
            throw new \RuntimeException('Required command audit is disabled.');
        }
        if (in_array($event, ['external.command.authorized', 'external.command.replayed', 'customer.created', 'catalogue.service.created'], true) && DB::connection()->transactionLevel() === 0) {
            throw new \RuntimeException('Successful command audit requires its transaction.');
        }
        $machine = $context instanceof IntegrationCommandContext;
        $causer = $machine ? $context->causer() : $context->authorize();
        $sponsorAttribution = $context instanceof IntegrationCommandContext ? DB::table('users')->useWritePdo()->where('id', $context->sponsorId)->value('attribution_id') : $context->authorize()->attribution_id;
        $properties = ['actor_kind' => $machine ? 'integration' : 'human', 'principal_kind' => $machine ? 'integration' : 'user',
            'real_actor_kind' => $machine ? 'integration' : 'user', 'effective_actor_kind' => $machine ? 'integration' : 'user',
            'real_actor_id' => $causer->id, 'effective_actor_id' => $causer->id, 'authority_user_id' => $machine ? $context->sponsorId : $causer->id,
            'sponsor_attribution_id' => $sponsorAttribution, 'integration_id' => $machine ? $context->integrationId : null,
            'credential_id' => $machine ? $context->credentialId : null, 'workspace_id' => $context->workspaceId,
            'legal_entity_id' => $context->entityId, 'environment' => $machine ? $context->environment : 'production', 'request_id' => $context->requestId,
            'client_request_id' => $machine ? $context->clientRequestId : null, 'operation_id' => $operationId ?? ($machine ? null : (string) Str::uuid()),
            'capability' => $capability->value, 'capability_version' => 1, 'approval_kind' => $machine ? 'standing_grant' : null,
            'approval_id' => null, 'automation_id' => null, 'impersonation_session' => null, 'human_actor_id' => $machine ? null : $causer->id,
            'user_agent' => null, 'outcome' => $outcome];
        if ($machine && $operationId !== null) {
            $origin = DB::table('external_command_operations')->useWritePdo()->where('operation_id', $operationId)
                ->where('integration_id', $context->integrationId)->where('command', $capability->value)->where('capability_version', 1)
                ->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)->where('environment', $context->environment)->first(['origin_credential_id', 'origin_sponsor_attribution_id']);
            if ($origin !== null) {
                $properties['origin_credential_id'] = (int) $origin->origin_credential_id;
                $properties['origin_sponsor_attribution_id'] = $origin->origin_sponsor_attribution_id;
            }
        }
        RequiredAudit::record(function () use ($causer, $properties, $event, $customer, $capability): ?\Spatie\Activitylog\Contracts\Activity {
            $logger = activity($event === $capability->creationEvent() ? ($capability === CommandCapability::CustomerCreate ? 'customer' : 'catalogue-item') : 'capability')->causedBy($causer)->event($event)->withProperties($properties);
            if ($customer !== null) {
                $logger->performedOn($customer);
            }
            $logger->tap(function (Activity $activity): void {
                if (! $activity instanceof Model || $activity->getConnection() !== DB::connection()) {
                    throw new \RuntimeException('Command audit connection mismatch.');
                }
            });
            $activity = $logger->log($capability === CommandCapability::CustomerCreate ? 'Customer command' : 'Catalogue service command');
            if ($activity instanceof Model && $activity->getConnection() !== DB::connection()) {
                throw new \RuntimeException('Command audit connection mismatch.');
            }

            return $activity;
        });
    }
}
