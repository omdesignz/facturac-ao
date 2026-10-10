<?php

namespace App\Actions;

use App\AgtEnvironment;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\ExecutionContext;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ApproveRecurringInvoice
{
    public function execute(RecurringInvoice $profile, User $actor, AgtEnvironment $environment, CarbonImmutable $expiresAt): int
    {
        return DB::transaction(function () use ($profile, $actor, $environment, $expiresAt): int {
            $profile = RecurringInvoice::query()->lockForUpdate()->findOrFail($profile->id);
            $context = ExecutionContext::resolve($actor, $profile->legalEntity, $environment);
            if (! $profile->auto_issue || ! $profile->is_active || $expiresAt->isPast()) {
                throw ValidationException::withMessages(['auto_issue' => 'A aprovação exige uma avença activa e uma validade futura.']);
            }
            DB::table('recurring_approvals')->where('recurring_invoice_id', $profile->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $id = DB::table('recurring_approvals')->insertGetId([
                'recurring_invoice_id' => $profile->id, 'approved_by_user_id' => $actor->id,
                'configuration_revision' => $profile->configuration_revision,
                'configuration_sha256' => self::fingerprint($profile), 'environment' => $environment->value,
                'approved_at' => now(), 'expires_at' => $expiresAt,
            ]);
            activity('recurring-invoice')->performedOn($profile)->causedBy($actor)->event('approved')
                ->withProperties([...$context->audit(), 'approval_id' => $id, 'configuration_revision' => $profile->configuration_revision])->log('Recurring standing authority approved');

            return $id;
        });
    }

    public function revoke(RecurringInvoice $profile, User $actor): void
    {
        DB::transaction(function () use ($profile, $actor): void {
            $profile = RecurringInvoice::query()->lockForUpdate()->findOrFail($profile->id);
            $context = ExecutionContext::resolve($actor, $profile->legalEntity, AgtEnvironment::Homologation);
            DB::table('recurring_approvals')->where('recurring_invoice_id', $profile->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            activity('recurring-invoice')->performedOn($profile)->causedBy($actor)->event('approval-revoked')->withProperties($context->audit())->log('Recurring standing authority revoked');
        });
    }

    public static function fingerprint(RecurringInvoice $profile): string
    {
        $profile->load(['customer', 'legalEntity', 'establishment']);
        abort_unless($profile->customer->workspace_id === $profile->workspace_id
            && $profile->customer->legal_entity_id === $profile->legal_entity_id
            && $profile->establishment->workspace_id === $profile->workspace_id
            && $profile->establishment->legal_entity_id === $profile->legal_entity_id
            && $profile->legalEntity->workspace_id === $profile->workspace_id, 403);

        return hash('sha256', app(CanonicalJson::class)->encode([
            'version' => 1,
            'profile' => Arr::only($profile->attributesToArray(), RecurringInvoice::CONFIGURATION_FIELDS),
            'customer' => $profile->customer->only(['id', 'name', 'tax_identification_number', 'country_code', 'address_line', 'payment_terms_days', 'is_active', 'auto_send_documents', 'email']),
            'entity' => Arr::only($profile->legalEntity->attributesToArray(), ['id', 'legal_name', 'tax_identification_number', 'tax_regime', 'currency_code']),
            'establishment' => $profile->establishment->only(['id', 'name', 'code']),
        ]));
    }
}
