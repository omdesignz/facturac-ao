<?php

namespace App\Http\Controllers;

use App\Actions\ValidateDataImport;
use App\Http\Requests\UpdateDataImportMappingRequest;
use App\Models\DataImport;
use DomainException;
use Illuminate\Http\RedirectResponse;

class DataImportMappingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        UpdateDataImportMappingRequest $request,
        DataImport $dataImport,
        ValidateDataImport $validateDataImport,
    ): RedirectResponse {
        try {
            $validatedImport = $validateDataImport->execute(
                $dataImport,
                $request->mappingProfile(),
            );
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('imports.index', ['import' => $validatedImport->public_id])
            ->with(
                $validatedImport->invalid_rows === 0 ? 'success' : 'error',
                $validatedImport->invalid_rows === 0
                    ? 'Todas as linhas foram validadas. A importação está pronta para confirmação.'
                    : "Foram encontradas {$validatedImport->invalid_rows} linhas com erros. Corrija o ficheiro e carregue-o novamente.",
            );
    }
}
