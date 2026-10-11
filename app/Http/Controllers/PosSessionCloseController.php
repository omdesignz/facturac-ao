<?php

namespace App\Http\Controllers;

use App\Actions\ClosePosSession;
use App\Exceptions\BillingActionRefused;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Http\Requests\ClosePosSessionRequest;
use App\Models\PosSession;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Closes a shift against the cash counted in the drawer.
 *
 * The opener closes their own; an owner or administrator may close one that
 * was left open by someone who has gone home.
 */
class PosSessionCloseController extends Controller
{
    use ResolvesPosCompany;

    public function __invoke(
        ClosePosSessionRequest $request,
        PosSession $posSession,
        ClosePosSession $closeSession,
    ): RedirectResponse {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        try {
            $closed = $closeSession->execute($posSession, $user, $request->countedCashMinor(), $request->notes());
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('pos.sessions.show', $closed)
            ->with('success', 'Turno fechado.');
    }
}
