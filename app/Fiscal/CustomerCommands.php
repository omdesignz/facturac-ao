<?php

namespace App\Fiscal;

use App\Models\Customer;
use App\Models\PriceList;
use Illuminate\Support\Facades\DB;

final class CustomerCommands
{
    public function create(HumanCustomerCommandContext $context, CustomerCreateInput $input): Customer
    {
        return DB::transaction(function () use ($context, $input): Customer {
            $context->authorize(locked: true);

            return $this->persist($context, $input);
        });
    }

    public function persist(IntegrationCommandContext|HumanCustomerCommandContext $context, CustomerCreateInput $input, ?string $operationId = null): Customer
    {
        abort_unless(DB::connection()->transactionLevel() > 0, 503);
        $context->authorize(locked: true);
        if ($context instanceof IntegrationCommandContext) {
            abort_unless($input->isExternal && $operationId !== null, 403);
            abort_unless(DB::table('external_command_operations')->where('operation_id', $operationId)
                ->where('integration_id', $context->integrationId)->where('origin_credential_id', $context->credentialId)
                ->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)
                ->where('environment', $context->environment)->where('command', CommandCapability::CustomerCreate->value)
                ->where('capability_version', 1)->where('state', 'executing')->exists(), 403);
        }
        CustomerCreateInput::coreValidator($input->attributes)->validate();
        $attributes = $input->attributes;
        if (($attributes['price_list_id'] ?? null) === null) {
            $defaults = PriceList::query()->useWritePdo()->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)
                ->where('is_default', true)->orderBy('id')->limit(2)->sharedLock()->get(['id']);
            abort_if($context instanceof IntegrationCommandContext && $defaults->count() > 1, 503);
            $attributes['price_list_id'] = $defaults->first()?->id;
        }
        if ($attributes['price_list_id'] !== null) {
            abort_unless(PriceList::query()->useWritePdo()->whereKey($attributes['price_list_id'])->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)->sharedLock()->first() !== null, 422);
        }
        $customer = new Customer([...$attributes, 'workspace_id' => $context->workspaceId, 'legal_entity_id' => $context->entityId]);
        $customer->disableLogging();
        $saved = CommandDatabase::sensitive(fn (): bool => $customer->save());
        abort_unless($saved && $customer->exists, 503);
        $customer->enableLogging();
        CommandAudit::record($context, 'customer.created', 'succeeded', $operationId, $customer);

        return $customer;
    }
}
