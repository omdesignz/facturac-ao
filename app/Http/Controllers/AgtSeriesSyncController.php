<?php

namespace App\Http\Controllers;

use App\Actions\SyncAgtSeries;
use App\AgtConnectionStatus;
use App\Http\Requests\SyncAgtSeriesRequest;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AgtSeriesSyncController extends Controller
{
    public function __invoke(
        SyncAgtSeriesRequest $request,
        SyncAgtSeries $syncAgtSeries,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        $user = $request->user();

        abort_unless($legalEntity instanceof LegalEntity && $user instanceof User, 404);
        $connection = $legalEntity->agtConnections()
            ->where('status', AgtConnectionStatus::Verified)
            ->latest('verified_at')
            ->first();
        $establishment = $legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->oldest('id')
            ->first();

        if (! $connection instanceof AgtConnection || ! $establishment instanceof Establishment) {
            return back()->with('error', 'Verifique a ligação AGT e o estabelecimento antes de sincronizar séries.');
        }

        try {
            $result = $syncAgtSeries->execute($connection, $legalEntity, $establishment, $user);
        } catch (LockTimeoutException) {
            return back()->with('error', 'Já existe uma sincronização de séries em curso.');
        } catch (Throwable) {
            return back()->with('error', 'A resposta de séries da AGT não pôde ser aplicada com segurança.');
        }

        return back()->with(
            $result['successful'] ? 'success' : 'error',
            $result['message'],
        );
    }
}
