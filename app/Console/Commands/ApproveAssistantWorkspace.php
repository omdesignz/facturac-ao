<?php

namespace App\Console\Commands;

use App\Fiscal\AssistantProviderProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Records, or withdraws, an owner's approval for their company's questions to be sent to the provider.
 *
 * The approval names the owner who gave it and stops counting the moment that person is no longer an active owner.
 */
#[Signature('assistant:workspace {owner : Email of the approving owner} {workspace? : Public ID of the workspace, when the owner has more than one} {--days=365 : Days the approval stays valid} {--revoke : Withdraw the approval instead}')]
#[Description('Approve or withdraw the assistant for one company on behalf of its owner')]
class ApproveAssistantWorkspace extends Command
{
    public function handle(): int
    {
        $owner = User::query()->where('email', (string) $this->argument('owner'))->first();
        $days = (int) $this->option('days');
        if (! $owner instanceof User || $days < 1 || $days > 730) {
            $this->components->error('Indique o email de uma conta existente e --days entre 1 e 730.');

            return self::FAILURE;
        }
        $owned = WorkspaceMembership::query()->where('user_id', $owner->id)->where('is_active', true)->where('role', WorkspaceRole::Owner)->pluck('workspace_id');
        $workspaces = Workspace::query()->whereIn('id', $owned)
            ->when($this->argument('workspace') !== null, fn ($query) => $query->where('public_id', (string) $this->argument('workspace')))->get();
        if ($workspaces->count() !== 1) {
            $this->components->error($workspaces->isEmpty()
                ? 'Esta conta não é proprietária activa dessa empresa.'
                : 'Esta conta é proprietária de várias empresas. Indique o identificador público: '.$workspaces->pluck('public_id')->implode(', '));

            return self::FAILURE;
        }
        $workspace = $workspaces->first();
        $time = CarbonImmutable::now('UTC');
        $revoking = (bool) $this->option('revoke');
        $changed = DB::transaction(function () use ($workspace, $owner, $time, $days, $revoking): bool {
            $current = $this->approvals($workspace->id)->lockForUpdate()->first();
            $valid = $current !== null && $time->lt(CarbonImmutable::parse($current->expires_at));
            if ($revoking ? $current === null : $valid) {
                return false;
            }
            if ($current !== null) {
                $this->approvals($workspace->id)->where('id', $current->id)->update(['revoked_at' => $time->toIso8601String()]);
            }
            if (! $revoking) {
                DB::table('assistant_provider_tenants')->insert(['workspace_id' => $workspace->id, 'policy' => AssistantProviderProfile::POLICY,
                    'profile' => AssistantProviderProfile::ID, 'owner_attribution_id' => $owner->attribution_id,
                    'approval_reference' => 'owner-'.$time->format('Ymd'), 'expires_at' => $time->addDays($days)->toIso8601String()]);
            }

            return true;
        });
        if ($changed) {
            activity('security')->event($revoking ? 'assistant-workspace-revoked' : 'assistant-workspace-approved')
                ->performedOn($workspace)->causedBy($owner)->log($revoking ? 'assistant approval withdrawn' : 'assistant approved for workspace');
        }
        $this->components->info(match (true) {
            ! $changed && $revoking => "{$workspace->name} não tinha aprovação activa.",
            ! $changed => "{$workspace->name} já tem uma aprovação válida.",
            $revoking => "O assistente deixa de estar disponível em {$workspace->name}.",
            default => "O assistente fica disponível em {$workspace->name} durante {$days} dias. Cada membro confirma o aviso de privacidade antes da primeira pergunta.",
        });

        return self::SUCCESS;
    }

    private function approvals(int $workspaceId): Builder
    {
        $query = DB::table('assistant_provider_tenants')->where('workspace_id', $workspaceId)->where('policy', AssistantProviderProfile::POLICY)
            ->where('profile', AssistantProviderProfile::ID)->whereNull('revoked_at');

        return DB::getDriverName() === 'pgsql' ? $query->where('contract_version', 'legacy') : $query;
    }
}
