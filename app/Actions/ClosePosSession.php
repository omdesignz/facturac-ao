<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Fiscal\Pos\PosSessionSummary;
use App\Models\PosSession;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\PosSessionStatus;
use Illuminate\Support\Facades\DB;

/**
 * Closes a shift against the cash actually counted in the drawer.
 *
 * The summary is frozen into the row: the report of a closed shift is a record
 * of what was true at the close and never moves afterwards.
 */
final readonly class ClosePosSession
{
    public function __construct(private PosSessionSummary $summary) {}

    public function execute(PosSession $session, User $user, int $countedCashMinor, ?string $notes): PosSession
    {
        if ($countedCashMinor < 0) {
            throw BillingActionRefused::because('O dinheiro contado não pode ser negativo.');
        }

        return DB::transaction(function () use ($session, $user, $countedCashMinor, $notes): PosSession {
            $locked = PosSession::query()
                ->whereKey($session->id)
                ->where('legal_entity_id', $session->legal_entity_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw BillingActionRefused::because('Este turno já foi fechado.');
            }

            if ($locked->opened_by_user_id !== $user->id && ! $this->managesWorkspace($user, $locked)) {
                throw BillingActionRefused::because(
                    'Só quem abriu o turno, ou um administrador, o pode fechar.',
                );
            }

            $summary = $this->summary->forSession($locked);
            $expected = $summary['expected_cash_minor'];

            $locked->forceFill([
                'status' => PosSessionStatus::Closed,
                'closed_by_user_id' => $user->id,
                'closed_at' => now(),
                'counted_cash_minor' => $countedCashMinor,
                'expected_cash_minor' => $expected,
                'cash_difference_minor' => $countedCashMinor - $expected,
                'closing_notes' => $notes === null || trim($notes) === '' ? null : trim($notes),
                'closing_summary' => $summary,
            ])->save();

            activity('pos-session')
                ->causedBy($user)
                ->performedOn($locked)
                ->event('pos-session-closed')
                ->withProperties([
                    'counted_cash_minor' => $countedCashMinor,
                    'expected_cash_minor' => $expected,
                    'cash_difference_minor' => $countedCashMinor - $expected,
                    'closed_by_other_user' => $locked->opened_by_user_id !== $user->id,
                ])
                ->log('Turno fechado.');

            return $locked;
        });
    }

    private function managesWorkspace(User $user, PosSession $session): bool
    {
        $membership = WorkspaceMembership::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $session->workspace_id)
            ->where('is_active', true)
            ->first();

        return $membership?->role->canManageWorkspace() === true;
    }
}
