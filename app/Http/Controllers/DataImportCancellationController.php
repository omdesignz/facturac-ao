<?php

namespace App\Http\Controllers;

use App\Actions\CancelDataImport;
use App\Http\Requests\CancelDataImportRequest;
use App\Models\DataImport;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;

class DataImportCancellationController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        CancelDataImportRequest $request,
        DataImport $dataImport,
        CancelDataImport $cancelDataImport,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        try {
            $cancelDataImport->execute($dataImport, $user);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('imports.index')
            ->with('success', 'Importação cancelada e ficheiro original eliminado.');
    }
}
