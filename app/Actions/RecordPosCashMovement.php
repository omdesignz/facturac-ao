<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Fiscal\Pos\PosSessionSummary;
use App\Models\PosCashMovement;
use App\Models\PosSession;
use App\Models\User;
use App\PosCashMovementType;
use Illuminate\Support\Facades\DB;

/**
 * Money put into, or taken out of, the drawer during a shift.
 *
 * The shift row is locked so a withdrawal is judged against the cash that is
 * really there, not against what it was a moment ago in another tab.
 */
final readonly class RecordPosCashMovement
{
    public function __construct(private PosSessionSummary $summary) {}

    public function execute(
        PosSession $session,
        User $user,
        PosCashMovementType $type,
        int $amountMinor,
        string $reason,
    ): PosCashMovement {
        if ($amountMinor <= 0) {
            throw BillingActionRefused::because('O valor tem de ser superior a zero.');
        }

        return DB::transaction(function () use ($session, $user, $type, $amountMinor, $reason): PosCashMovement {
            $locked = PosSession::query()
                ->whereKey($session->id)
                ->where('legal_entity_id', $session->legal_entity_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isOpen()) {
                throw BillingActionRefused::because('Este turno já foi fechado.');
            }

            if ($type === PosCashMovementType::Out) {
                $inDrawer = $this->summary->expectedCash($locked);

                if ($amountMinor > $inDrawer) {
                    throw BillingActionRefused::because(sprintf(
                        'A retirada é superior ao dinheiro que deve estar na gaveta (%s %s).',
                        number_format($inDrawer / 100, 2, ',', ' '),
                        $locked->currency_code,
                    ));
                }
            }

            $movement = PosCashMovement::query()->create([
                'workspace_id' => $locked->workspace_id,
                'legal_entity_id' => $locked->legal_entity_id,
                'pos_session_id' => $locked->id,
                'type' => $type,
                'amount_minor' => $amountMinor,
                'reason' => $reason,
                'created_by_user_id' => $user->id,
            ]);

            activity('pos-session')
                ->causedBy($user)
                ->performedOn($locked)
                ->event('pos-cash-movement-recorded')
                ->withProperties([
                    'type' => $type->value,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                    'movement_public_id' => $movement->public_id,
                ])
                ->log($type === PosCashMovementType::In ? 'Reforço de caixa registado.' : 'Retirada de caixa registada.');

            return $movement;
        });
    }
}
