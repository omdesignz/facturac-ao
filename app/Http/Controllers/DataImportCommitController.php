<?php

namespace App\Http\Controllers;

use App\Actions\CommitDataImport;
use App\Http\Requests\CommitDataImportRequest;
use App\Models\DataImport;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;

class DataImportCommitController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        CommitDataImportRequest $request,
        DataImport $dataImport,
        CommitDataImport $commitDataImport,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 404);

        try {
            $committedImport = $commitDataImport->execute($dataImport, $user);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('imports.index', ['import' => $committedImport->public_id])
            ->with('success', "Importação concluída: {$committedImport->created_rows} novos e {$committedImport->updated_rows} actualizados.");
    }
}
