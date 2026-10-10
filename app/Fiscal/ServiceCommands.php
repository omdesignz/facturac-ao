<?php

namespace App\Fiscal;

use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use Illuminate\Support\Facades\DB;

final class ServiceCommands
{
    public function __construct(private readonly ServiceTaxProfile $tax) {}

    public function create(HumanServiceCommandContext $context, ServiceCreateInput $input): CatalogueItem
    {
        return DB::transaction(function () use ($context, $input): CatalogueItem {
            $context->authorize(locked: true);

            return $this->persist($context, $input);
        });
    }

    public function persist(IntegrationCommandContext|HumanServiceCommandContext $context, ServiceCreateInput $input, ?string $operationId = null): CatalogueItem
    {
        abort_unless(DB::connection()->transactionLevel() > 0, 503);
        if ($context instanceof IntegrationCommandContext) {
            $context->authorize(locked: true, capability: CommandCapability::ServiceCreate);
            abort_unless($input->isExternal && $operationId !== null, 403);
            abort_unless(DB::table('external_command_operations')->where('operation_id', $operationId)
                ->where('integration_id', $context->integrationId)->where('origin_credential_id', $context->credentialId)
                ->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)
                ->where('environment', $context->environment)->where('command', CommandCapability::ServiceCreate->value)
                ->where('capability_version', 1)->where('state', 'executing')->exists(), 403);
        } else {
            abort_if($input->isExternal, 403);
            $context->authorize(locked: true);
        }
        $entity = LegalEntity::query()->useWritePdo()->whereKey($context->entityId)->where('workspace_id', $context->workspaceId)->sharedLock()->first();
        abort_unless($entity !== null, 403);
        if ($input->isExternal) {
            abort_unless($entity->currency_code === 'AOA' && in_array('AOA', config('fiscal.currencies', []), true), 503);
        }
        $profile = $this->tax->verified($input->attributes['tax_treatment'], $input->isExternal);
        $attributes = array_intersect_key($input->attributes, array_flip(['code', 'name', 'description', 'unit_of_measure']));
        $expected = [...$attributes, 'workspace_id' => $context->workspaceId, 'legal_entity_id' => $context->entityId,
            'type' => 'service', 'is_active' => $input->isExternal ? true : $input->attributes['is_active'],
            'tracks_stock' => false, 'stock_scale' => 3, 'reorder_level_units' => null,
            'unit_price_minor' => (int) $input->attributes['unit_price_minor'], 'currency_code' => $entity->currency_code,
            'tax_type' => $profile['type'], 'tax_code' => $profile['code'], 'tax_percentage' => $profile['percentage'], 'tax_exemption_code' => $profile['exemption_code']];
        $item = new CatalogueItem;
        $item->forceFill($expected);
        $item->disableLogging();
        $saved = CommandDatabase::sensitive(fn (): bool => $item->save(), CommandCapability::ServiceCreate);
        abort_unless($saved && $item->exists, 503);
        $persisted = CatalogueItem::query()->useWritePdo()->whereKey($item->id)->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)->first();
        abort_unless($persisted !== null, 503);
        foreach ($expected as $field => $value) {
            $actual = in_array($field, ['is_active', 'tracks_stock', 'stock_scale', 'unit_price_minor'], true) ? $persisted->getAttribute($field) : $persisted->getRawOriginal($field);
            abort_unless($value === null ? $actual === null : (is_bool($value) ? $actual === $value : (string) $actual === (string) $value)
                || ($field === 'tax_percentage' && $persisted->tax_percentage === ($value === '14' ? '14.00' : '0.00')), 503);
        }
        CommandAudit::record($context, 'catalogue.service.created', 'succeeded', $operationId, $item, CommandCapability::ServiceCreate);
        $item->enableLogging();

        return $item;
    }
}
