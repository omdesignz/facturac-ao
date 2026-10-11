<?php

namespace App\Http\Controllers;

use App\Actions\RecordPosCashMovement;
use App\Exceptions\BillingActionRefused;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Http\Requests\StorePosCashMovementRequest;
use App\Models\PosSession;
use App\Models\User;
use App\PosCashMovementType;
use Illuminate\Http\RedirectResponse;

/**
 * Cash put into, or taken out of, the drawer in the middle of a shift.
 */
class PosCashMovementController extends Controller
{
    use ResolvesPosCompany;

    public function store(
        StorePosCashMovementRequest $request,
        PosSession $posSession,
        RecordPosCashMovement $recordMovement,
    ): RedirectResponse {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $movement = $recordMovement->execute(
                $posSession,
                $user,
                $request->movementType(),
                $request->amountMinor(),
                $request->reason(),
            );
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // «Reforço» is masculine and «Retirada» feminine; the message agrees.
        return back()->with('success', $movement->type === PosCashMovementType::Out
            ? 'Retirada registada.'
            : 'Reforço registado.');
    }
}
