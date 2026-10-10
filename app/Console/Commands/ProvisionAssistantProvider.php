<?php

namespace App\Console\Commands;

use App\Fiscal\AssistantProviderProfile;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates or renews the operator records the assistant's provider path checks on every question.
 *
 * Deliberately a console command: switching on a path that sends customer questions to an
 * external provider should be done by someone with server access and leave a trace.
 */
#[Signature('assistant:provision {--days=90 : Days the operator approval stays valid} {--reset-circuit : Clear a tripped circuit breaker on the assistant budget}')]
#[Description('Create or renew the control records the assistant provider path requires')]
class ProvisionAssistantProvider extends Command
{
    public function handle(): int
    {
        $budget = config('assistant.provider.budget_id');
        $reference = config('assistant.provider.approval_reference');
        $days = (int) $this->option('days');
        if (! is_string($budget) || ! Str::isUuid($budget) || ! is_string($reference)
            || preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $reference) !== 1 || $days < 1 || $days > 365) {
            $this->components->error('Defina ASSISTANT_PROVIDER_BUDGET_ID (UUID) e ASSISTANT_PROVIDER_APPROVAL_REFERENCE, e use --days entre 1 e 365.');

            return self::FAILURE;
        }
        $postgres = DB::getDriverName() === 'pgsql';
        $mapping = config('tenant_ai.legacy_accounting.'.$budget);
        if ($postgres && (! is_array($mapping) || ! Str::isUuid((string) ($mapping['deployment_id'] ?? '')) || ! Str::isUuid((string) ($mapping['usage_budget_id'] ?? ''))
            || $mapping['usage_budget_id'] === $budget)) {
            $this->components->error('Defina TENANT_AI_DEPLOYMENT_ID e ASSISTANT_PROVIDER_USAGE_BUDGET_ID com dois UUID diferentes do orçamento.');

            return self::FAILURE;
        }
        $expires = CarbonImmutable::now('UTC')->addDays($days);
        $reset = (bool) $this->option('reset-circuit');
        $outcomes = DB::transaction(function () use ($budget, $reference, $expires, $reset, $postgres, $mapping): array {
            $outcomes = [];
            if ($postgres) {
                $root = DB::table('ai_gateway_controls')->where('deployment_id', $mapping['deployment_id'])
                    ->where('kind', 'global')->where('subject_key', 'root')->lockForUpdate()->first();
                if ($root === null) {
                    DB::table('ai_gateway_controls')->insert(['id' => (string) Str::uuid(), 'deployment_id' => $mapping['deployment_id'], 'kind' => 'global',
                        'subject_key' => 'root', 'circuit_blocked' => false, 'created_at' => $expires->subDays(0)->toIso8601String(), 'updated_at' => $expires->toIso8601String()]);
                }
                $outcomes['Controlo raiz'] = $root === null ? 'criado' : ($root->circuit_blocked ? 'BLOQUEADO' : 'presente');
                $outcomes['Orçamento de utilização partilhado'] = $this->control($mapping['usage_budget_id'], ['contract_version' => 'gateway_v1',
                    'deployment_id' => $mapping['deployment_id'], 'ownership_kind' => 'shared_usage', 'account_role' => 'usage'], $reference, $expires, $reset);
            }
            $outcomes['Orçamento do assistente'] = $this->control($budget, $postgres ? ['contract_version' => 'legacy'] : [], $reference, $expires, $reset);

            return $outcomes;
        });
        activity('security')->event('assistant-provider-provisioned')
            ->withProperties(['budget_id' => $budget, 'approval_reference' => $reference, 'approval_expires_at' => $expires->toIso8601String(), 'circuit_reset' => $reset])
            ->log('assistant provider provisioned');
        foreach ($outcomes as $label => $outcome) {
            $this->components->twoColumnDetail($label, $outcome);
        }
        $this->components->info('Aprovação do operador válida até '.$expires->format('Y-m-d').'. Aprove cada empresa com assistant:workspace.');

        return in_array('BLOQUEADO', $outcomes, true) ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, string> $identity */
    private function control(string $budget, array $identity, string $reference, CarbonImmutable $expires, bool $reset): string
    {
        $row = DB::table('assistant_provider_controls')->where('budget_id', $budget)->lockForUpdate()->first();
        $time = CarbonImmutable::now('UTC')->toIso8601String();
        if ($row === null) {
            DB::table('assistant_provider_controls')->insert([...$identity, 'budget_id' => $budget, 'enabled' => true, 'circuit_blocked' => false,
                'profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY, 'approval_reference' => $reference,
                'approval_expires_at' => $expires->toIso8601String(), 'created_at' => $time, 'updated_at' => $time]);

            return 'criado';
        }
        foreach ($identity as $column => $value) {
            if ($row->{$column} !== $value) {
                throw new \RuntimeException('O orçamento '.$budget.' já existe com outra finalidade.');
            }
        }
        $blocked = (bool) $row->circuit_blocked && ! $reset;
        DB::table('assistant_provider_controls')->where('budget_id', $budget)->update([
            'enabled' => ! $blocked, 'circuit_blocked' => $blocked, 'outcome' => $blocked ? $row->outcome : null,
            'profile' => AssistantProviderProfile::ID, 'policy' => AssistantProviderProfile::POLICY, 'approval_reference' => $reference,
            'approval_expires_at' => $expires->toIso8601String(), 'updated_at' => $time,
            ...(DB::getDriverName() === 'pgsql' ? ['revision' => $row->revision + 1] : [])]);

        return $blocked ? 'BLOQUEADO' : 'renovado';
    }
}
