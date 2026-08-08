<?php

namespace App\Http\Controllers;

use App\Actions\StartImpersonation;
use App\Actions\StopImpersonation;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StartImpersonationRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function store(
        StartImpersonationRequest $request,
        User $user,
        StartImpersonation $startImpersonation,
    ): RedirectResponse {
        try {
            $startImpersonation->execute(
                $request,
                $request->user(),
                $user,
                (string) $request->validated('reason'),
            );
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('dashboard')
            ->with('status', "Está a ver a aplicação como {$user->name}. Tudo o que fizer fica registado.");
    }

    /**
     * Available to whoever is signed in, because during an impersonation that
     * is the customer's account: the way back has to work from inside it.
     */
    public function destroy(Request $request, StopImpersonation $stopImpersonation): RedirectResponse
    {
        $impersonator = $stopImpersonation->execute($request);

        if ($impersonator === null) {
            return redirect()->route('dashboard');
        }

        return redirect()
            ->route('support.index')
            ->with('success', 'Sessão de diagnóstico encerrada e registada.');
    }
}
