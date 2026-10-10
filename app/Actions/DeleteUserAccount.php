<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closes a person's account.
 *
 * The hard part is not the deleting, it is what must survive it. Angolan
 * fiscal law requires issued documents to be retained for years, and those
 * documents name the person who issued them. So a user who has issued anything
 * is stripped rather than erased: name and email are replaced, credentials and
 * sessions destroyed, and the row stays only to keep `issued_by_user_id`
 * resolvable on documents already filed with the AGT.
 *
 * A workspace nobody else can reach is a different problem again, and this
 * refuses rather than guessing: leaving a company's books with no owner is
 * worse than making someone hand them over first.
 */
class DeleteUserAccount
{
    /**
     * What a workspace must be emptied of before it will go, children first.
     *
     * All of these cascade from `workspace_id` and would, in principle, look
     * after themselves. But several also hang off tables that only restrict —
     * `fiscal_documents` restricts on `establishments`, `subscription_charges`
     * on `workspace_subscriptions` — and those parents cascade from the
     * workspace as well. Which of the two paths the database walks first is not
     * something it promises, so on MySQL the restricting one sometimes wins and
     * refuses the whole delete. Emptying these in a stated order first means
     * the result no longer depends on that.
     *
     * @var list<string>
     */
    private const DEPENDENT_TABLES = [
        'fiscal_document_events',
        'agt_submissions',
        'fiscal_document_settlements',
        'fiscal_documents',
        'fiscal_series',
        'subscription_charges',
        'workspace_subscriptions',
    ];

    /**
     * What deleting this account would mean, in advance.
     *
     * The screen shows this before asking, because "delete my account" means
     * different things depending on what the person has done in it.
     *
     * @return array{
     *     can_delete: bool,
     *     blocking_workspaces: list<string>,
     *     retained_workspaces: list<array{name: string, until: string, documents: int}>,
     *     retains_documents: bool,
     *     retains_support_record: bool,
     *     issued_count: int,
     *     workspaces_deleted: list<string>,
     *     workspaces_left: list<string>
     * }
     */
    public function preview(User $user): array
    {
        $blocking = [];
        $deleted = [];
        $left = [];

        foreach ($this->ownedWorkspaces($user) as $workspace) {
            if ($this->hasOtherOwner($workspace, $user)) {
                $left[] = $workspace->name;

                continue;
            }

            if ($this->hasOtherMembers($workspace, $user) || $this->hasIntegrationEvidence($workspace) || $this->hasAiEvidence($workspace)) {
                // Someone else still works here; handing it over is a decision
                // for a person, not something to infer.
                $blocking[] = $workspace->name;

                continue;
            }

            $deleted[] = $workspace->name;
        }

        foreach ($this->memberWorkspaces($user) as $workspace) {
            $left[] = $workspace->name;
        }

        /*
         * Only documents that will outlive the account matter here. A document
         * in a workspace being deleted goes with it, so keeping the user row
         * for its sake would preserve a person for nothing.
         */
        $issued = FiscalDocument::query()
            ->where('issued_by_user_id', $user->id)
            ->whereNotIn('workspace_id', $this->workspaceIdsBeingDeleted($user))
            ->count();

        /*
         * A workspace holding documents still inside their retention window
         * cannot be destroyed at all, however much its owner wants it gone.
         * The obligation to keep them is the taxpayer's, and honouring a
         * deletion request by shredding what the law requires would put the
         * company in breach on their behalf.
         */
        $retained = [];

        foreach ($deleted as $index => $name) {
            $workspace = $this->ownedWorkspaces($user)->firstWhere('name', $name);

            if (! $workspace instanceof Workspace) {
                continue;
            }

            $window = $this->retentionWindow($workspace);

            if ($window !== null) {
                $retained[] = $window;
                unset($deleted[$index]);
            }
        }

        $deleted = array_values($deleted);

        return [
            'can_delete' => $blocking === [] && $retained === [],
            'blocking_workspaces' => $blocking,
            'retained_workspaces' => $retained,
            'retains_documents' => $issued > 0,
            'retains_support_record' => $this->hasSupportRecord($user),
            'issued_count' => $issued,
            'workspaces_deleted' => $deleted,
            'workspaces_left' => $left,
        ];
    }

    /**
     * Whether a record of support access names this person on either side.
     *
     * An impersonation is evidence about two people at once: who was helped and
     * who let themselves in. Destroying the row to close one of those accounts
     * would take the other's audit trail with it, so the row stays and the
     * person is stripped out of it instead.
     */
    private function hasSupportRecord(User $user): bool
    {
        return $user->impersonationsReceived()->exists()
            || $user->impersonationsPerformed()->exists();
    }

    private function hasAiEvidence(Workspace $workspace): bool
    {
        return DB::table('tenant_ai_settings')->where('workspace_id', $workspace->id)->exists();
    }

    private function hasIntegrationEvidence(Workspace $workspace): bool
    {
        return Integration::query()->where('workspace_id', $workspace->id)->exists();
    }

    /**
     * When a company's records stop being legally required, or null when
     * nothing it holds is still inside the window.
     *
     * Counted from the end of the year a document belongs to, which is how the
     * Código Geral Tributário frames it — not from the document's own date.
     *
     * @return array{name: string, until: string, documents: int}|null
     */
    private function retentionWindow(Workspace $workspace): ?array
    {
        $years = max(0, (int) config('fiscal.retention_years', 5));

        $latest = FiscalDocument::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('document_no')
            ->max('document_date');

        if ($latest === null) {
            return null;
        }

        $expiresAt = CarbonImmutable::parse($latest)->endOfYear()->addYears($years);

        if ($expiresAt->isPast()) {
            return null;
        }

        return [
            'name' => $workspace->name,
            'until' => $expiresAt->toDateString(),
            'documents' => FiscalDocument::query()
                ->where('workspace_id', $workspace->id)
                ->whereNotNull('document_no')
                ->count(),
        ];
    }

    /**
     * Workspaces this deletion would take with it.
     *
     * @return list<int>
     */
    private function workspaceIdsBeingDeleted(User $user): array
    {
        $ids = [];

        foreach ($this->ownedWorkspaces($user) as $workspace) {
            // A workspace held back by retention is not going anywhere, so its
            // documents outlive the account and still name their issuer.
            if (! $this->hasOtherOwner($workspace, $user)
                && ! $this->hasOtherMembers($workspace, $user)
                && ! $this->hasIntegrationEvidence($workspace)
                && ! $this->hasAiEvidence($workspace)
                && $this->retentionWindow($workspace) === null) {
                $ids[] = $workspace->id;
            }
        }

        return $ids;
    }

    public function execute(User $user): void
    {
        $preview = $this->preview($user);

        if ($preview['retained_workspaces'] !== []) {
            $first = $preview['retained_workspaces'][0];

            throw BillingActionRefused::because(
                "{$first['name']} tem documentos fiscais dentro do prazo legal de conservação, "
                ."que termina a {$first['until']}. Até lá não é possível eliminá-los, "
                .'mesmo a pedido do proprietário.',
            );
        }

        if (! $preview['can_delete']) {
            throw BillingActionRefused::because(
                'Transfira a propriedade de '.implode(', ', $preview['blocking_workspaces'])
                .' antes de eliminar a conta.',
            );
        }

        DB::transaction(function () use ($user, $preview): void {
            DB::table('ai_gateway_controls')->where('kind', 'global')->orderBy('id')->lockForUpdate()->get();
            foreach ($this->ownedWorkspaces($user) as $workspace) {
                if (in_array($workspace->name, $preview['workspaces_deleted'], true) && $this->hasAiEvidence($workspace)) {
                    throw BillingActionRefused::because('Existem registos de configuração de IA que devem ser conservados.');
                }
            }
            foreach ($this->ownedWorkspaces($user) as $workspace) {
                if (in_array($workspace->name, $preview['workspaces_deleted'], true)) {
                    $this->empty($workspace);
                    $workspace->delete();
                }
            }

            $user->workspaceMemberships()->delete();
            $user->passkeys()->delete();
            $user->socialIdentities()->delete();
            $user->notifications()->delete();

            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();

            if ($preview['retains_documents'] || $preview['retains_support_record']) {
                $this->anonymise($user);

                return;
            }

            $user->delete();
        });
    }

    /**
     * Clears out everything hanging off a workspace that is about to go.
     *
     * Only reached once the retention window has closed, which is the single
     * point in the application allowed to destroy fiscal records. Everywhere
     * else the database still refuses, which is the reason this has to be
     * spelled out rather than left to the cascade.
     */
    private function empty(Workspace $workspace): void
    {
        foreach (self::DEPENDENT_TABLES as $table) {
            DB::table($table)->where('workspace_id', $workspace->id)->delete();
        }
    }

    /**
     * Keeps the row, loses the person.
     *
     * Everything that identifies or authenticates is replaced. What remains is
     * an opaque marker so a document issued years ago still resolves to
     * something rather than to a broken reference.
     */
    private function anonymise(User $user): void
    {
        $user->forceFill([
            'name' => 'Utilizador removido',
            'email' => "removido-{$user->id}@invalido.local",
            'email_verified_at' => null,
            'password' => bcrypt(str()->random(64)),
            'remember_token' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'current_workspace_id' => null,
            'notification_preferences' => null,
            'is_support_staff' => false,
        ])->save();
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function ownedWorkspaces(User $user)
    {
        return Workspace::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('role', WorkspaceRole::Owner->value))
            ->get();
    }

    /**
     * @return Collection<int, Workspace>
     */
    private function memberWorkspaces(User $user)
    {
        return Workspace::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('role', '!=', WorkspaceRole::Owner->value))
            ->get();
    }

    private function hasOtherOwner(Workspace $workspace, User $user): bool
    {
        return $workspace->memberships()
            ->where('user_id', '!=', $user->id)
            ->where('role', WorkspaceRole::Owner->value)
            ->exists();
    }

    private function hasOtherMembers(Workspace $workspace, User $user): bool
    {
        return $workspace->memberships()
            ->where('user_id', '!=', $user->id)
            ->exists();
    }
}
