<?php

namespace App\Console\Commands;

use App\Fiscal\AssistantProviderProfile;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Says which of the assistant's gates is closed and what opens it.
 *
 * Every gate fails closed with the same "unavailable" answer on purpose, so this is the only place that explains why.
 */
#[Signature('assistant:check {workspace? : Public ID of a company whose approval should also be checked}')]
#[Description('Explain whether the assistant can answer, and what is missing if it cannot')]
class CheckAssistantReadiness extends Command
{
    public function handle(): int
    {
        $failures = 0;
        foreach ($this->gates() as $gate => $missing) {
            $failures += $missing === null ? 0 : 1;
            $this->components->twoColumnDetail($gate, $missing === null ? '<fg=green>pronto</>' : '<fg=red>'.$missing.'</>');
        }
        $failures === 0
            ? $this->components->info('O assistente pode responder. Cada membro precisa de autenticação de dois factores e de confirmar o aviso de privacidade.')
            : $this->components->error("Faltam {$failures} condições. O assistente responde \"indisponível\" até estarem todas prontas.");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, ?string> Gate label to what is missing, or null when it is ready. */
    private function gates(): array
    {
        $provider = (array) config('assistant.provider');
        $now = CarbonImmutable::now('UTC');
        $budget = $provider['budget_id'] ?? null;
        $budgets = (array) ($provider['budgets'] ?? []);
        $gates = [
            'Assistente ligado' => config('assistant.enabled') === true ? null : 'ASSISTANT_ENABLED=true',
            'Fornecedor ligado' => ($provider['enabled'] ?? null) === true && ($provider['egress_enabled'] ?? null) === true
                ? null : 'ASSISTANT_PROVIDER_ENABLED=true e ASSISTANT_PROVIDER_EGRESS_ENABLED=true',
            'Perfil do modelo' => AssistantProviderProfile::valid() ? null : 'o perfil '.AssistantProviderProfile::ID.' expirou em '.AssistantProviderProfile::EXPIRES,
            'Aviso de privacidade' => ($provider['notice_version'] ?? null) === AssistantProviderProfile::POLICY
                && is_string($provider['notice_url'] ?? null) && preg_match('#^/[a-zA-Z0-9/_-]{1,160}$#D', $provider['notice_url']) === 1
                ? null : 'ASSISTANT_PROVIDER_NOTICE_VERSION='.AssistantProviderProfile::POLICY.' e ASSISTANT_PROVIDER_NOTICE_URL=/privacidade',
            'Chave do fornecedor' => $this->keyFile($provider['secret_reference'] ?? null),
            'Limites de despesa' => $this->budgets($budgets),
        ];
        if (! is_string($budget) || ! Str::isUuid($budget)) {
            return [...$gates, 'Orçamento do assistente' => 'ASSISTANT_PROVIDER_BUDGET_ID com um UUID'];
        }
        if (DB::getDriverName() === 'pgsql') {
            $mapping = config('tenant_ai.legacy_accounting.'.$budget);
            $root = is_array($mapping) ? DB::table('ai_gateway_controls')->where('deployment_id', $mapping['deployment_id'] ?? null)
                ->where('kind', 'global')->where('subject_key', 'root')->first() : null;
            $gates['Controlo raiz'] = match (true) {
                $root === null => 'TENANT_AI_DEPLOYMENT_ID e depois assistant:provision',
                (bool) $root->circuit_blocked => 'o controlo raiz está bloqueado',
                default => null,
            };
            $gates['Orçamento de utilização partilhado'] = $this->control(is_array($mapping) ? ($mapping['usage_budget_id'] ?? null) : null, null, $now);
        }
        $gates['Orçamento do assistente'] = $this->control($budget, $provider['approval_reference'] ?? null, $now);
        if ($this->argument('workspace') !== null) {
            $gates['Aprovação da empresa'] = $this->workspace((string) $this->argument('workspace'), $now);
        }

        return $gates;
    }

    private function keyFile(mixed $path): ?string
    {
        if (! is_string($path) || ! str_starts_with($path, '/')) {
            return 'ASSISTANT_PROVIDER_SECRET_FILE com um caminho absoluto';
        }
        clearstatcache();
        $stat = is_link($path) || ! is_file($path) || ! is_readable($path) ? false : lstat($path);
        if ($stat === false) {
            return 'o ficheiro da chave não existe, é uma ligação simbólica ou não pode ser lido';
        }
        if (($stat['mode'] & 077) !== 0) {
            return 'o ficheiro da chave tem de ser privado: chmod 600';
        }
        $key = rtrim((string) file_get_contents($path, false, null, 0, 514), "\n");

        return preg_match('/^[a-zA-Z0-9_-]{16,512}$/D', $key) === 1 ? null : 'o ficheiro tem de conter apenas a chave, numa linha';
    }

    /** @param array<string, mixed> $budgets */
    private function budgets(array $budgets): ?string
    {
        foreach (AssistantProviderProfile::BUDGET_CEILINGS as $scope => $ceiling) {
            $value = $budgets[$scope] ?? null;
            if (! is_int($value) || $value < AssistantProviderProfile::reservation() || $value > $ceiling) {
                return 'ASSISTANT_PROVIDER_BUDGET_'.strtoupper($scope).' entre '.AssistantProviderProfile::reservation().' e '.$ceiling;
            }
        }

        return null;
    }

    private function control(mixed $budget, mixed $reference, CarbonImmutable $now): ?string
    {
        $row = is_string($budget) && Str::isUuid($budget) ? DB::table('assistant_provider_controls')->where('budget_id', $budget)->first() : null;

        return match (true) {
            $row === null => 'assistant:provision',
            (bool) $row->circuit_blocked => 'o disjuntor disparou ('.($row->outcome ?? 'sem motivo registado').'): assistant:provision --reset-circuit',
            ! $row->enabled || $row->approval_expires_at === null || ! $now->lt(CarbonImmutable::parse($row->approval_expires_at)) => 'a aprovação expirou: assistant:provision',
            $reference !== null && $row->approval_reference !== $reference => 'a referência de aprovação mudou: assistant:provision',
            default => null,
        };
    }

    private function workspace(string $publicId, CarbonImmutable $now): ?string
    {
        $workspace = Workspace::query()->where('public_id', $publicId)->first();
        if (! $workspace instanceof Workspace) {
            return 'empresa não encontrada';
        }
        $query = DB::table('assistant_provider_tenants')->where('workspace_id', $workspace->id)->where('policy', AssistantProviderProfile::POLICY)
            ->where('profile', AssistantProviderProfile::ID)->whereNull('revoked_at');
        $approval = (DB::getDriverName() === 'pgsql' ? $query->where('contract_version', 'legacy') : $query)->first();
        if ($approval === null || ! $now->lt(CarbonImmutable::parse($approval->expires_at))) {
            return 'assistant:workspace <email do proprietário>';
        }

        return DB::table('workspace_memberships')->join('users', 'users.id', '=', 'workspace_memberships.user_id')
            ->where('workspace_memberships.workspace_id', $workspace->id)->where('workspace_memberships.is_active', true)
            ->where('workspace_memberships.role', 'owner')->where('users.attribution_id', $approval->owner_attribution_id)->exists()
            ? null : 'quem aprovou já não é proprietário activo: assistant:workspace <email do proprietário>';
    }
}
