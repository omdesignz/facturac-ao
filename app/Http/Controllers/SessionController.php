<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The devices signed in to an account.
 *
 * Read straight from the session store rather than a table of our own: the
 * rows the driver already keeps are the truth about who is signed in, and a
 * parallel record would be one more thing to fall out of step.
 */
class SessionController extends Controller
{
    /** Ends one other session, leaving this one alone. */
    public function destroy(Request $request, string $session): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        if ($session === $request->session()->getId()) {
            return back()->with(
                'error',
                'Esta é a sessão que está a usar. Termine-a saindo da conta.',
            );
        }

        $deleted = $this->query($user)->where('id', $session)->delete();

        return back()->with(
            'success',
            $deleted === 0
                ? 'Essa sessão já não estava activa.'
                : 'Sessão terminada nesse dispositivo.',
        );
    }

    /**
     * Ends every other session at once.
     *
     * Deliberately spares the current one: someone who has just realised their
     * password is known should not be signed out mid-decision.
     */
    public function destroyOthers(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $ended = $this->query($user)
            ->where('id', '!=', $request->session()->getId())
            ->delete();

        return back()->with(
            'success',
            $ended === 0
                ? 'Não havia outras sessões activas.'
                : "{$ended} sessão(ões) terminada(s). Continua com sessão iniciada neste dispositivo.",
        );
    }

    /**
     * @return Builder
     */
    private function query(User $user)
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id);
    }
}
