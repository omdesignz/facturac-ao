<?php

namespace App\Http\Controllers;

use App\Actions\RunAgtConnectionCheck;
use App\AgtEnvironment;
use App\Http\Requests\StoreAgtConnectionCheckRequest;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;

class AgtConnectionCheckController extends Controller
{
    public function store(
        StoreAgtConnectionCheckRequest $request,
        RunAgtConnectionCheck $runAgtConnectionCheck,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $user = $request->user();

        abort_unless($legalEntity instanceof LegalEntity && $user instanceof User, 404);

        $connection = $legalEntity->agtConnections()
            ->where('environment', AgtEnvironment::Homologation)
            ->first();

        if (! $connection instanceof AgtConnection) {
            return redirect()
                ->route('agt.connection.show')
                ->with('error', 'Guarde primeiro a configuração de homologação.');
        }

        try {
            $check = $runAgtConnectionCheck->execute($connection, $legalEntity, $user);
        } catch (LockTimeoutException) {
            return redirect()
                ->route('agt.connection.show')
                ->with('error', 'Já existe uma verificação em curso. Aguarde alguns segundos.');
        }

        return redirect()
            ->route('agt.connection.show')
            ->with(
                $check->status->value === 'passed' ? 'success' : 'error',
                $check->safe_message,
            );
    }
}
