<?php

namespace App\Http\Controllers;

use App\Actions\RequestAgtSeries;
use App\Actions\SyncAgtSeries;
use App\AgtConnectionStatus;
use App\Http\Requests\StoreAgtSeriesRequest;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\RedirectResponse;
use Throwable;

class AgtSeriesRequestController extends Controller
{
    public function __invoke(
        StoreAgtSeriesRequest $request,
        RequestAgtSeries $requestAgtSeries,
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
            return back()->with('error', 'Verifique a ligação AGT e o estabelecimento antes de solicitar uma série.');
        }

        try {
            $result = $requestAgtSeries->execute(
                $connection,
                $legalEntity,
                $request->documentType(),
                $request->seriesYear(),
                $user,
            );
        } catch (LockTimeoutException) {
            return back()->with('error', 'Já existe um pedido desta série em curso.');
        } catch (Throwable) {
            return back()->with('error', 'O pedido de série não pôde ser registado com segurança.');
        }

        if (! $result->successful) {
            return back()->with('error', $result->safeMessage);
        }

        try {
            $synchronization = $syncAgtSeries->execute(
                $connection,
                $legalEntity,
                $establishment,
                $user,
            );
        } catch (Throwable) {
            return back()->with(
                'success',
                $result->safeMessage.' A autorização foi concluída; sincronize as séries para a importar.',
            );
        }

        if ($synchronization['successful']) {
            return back()->with(
                'success',
                $result->safeMessage.' A série já está disponível no sistema.',
            );
        }

        return back()->with(
            'success',
            $result->safeMessage.' A autorização foi concluída; sincronize novamente para a importar.',
        );
    }
}
