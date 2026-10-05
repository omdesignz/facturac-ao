<?php

namespace App\Http\Controllers;

use App\Fiscal\Documents\FiscalDocumentRegister;
use App\Http\Requests\IndexFiscalDocumentsRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class FiscalDocumentIndexController extends Controller
{
    public function __invoke(
        IndexFiscalDocumentsRequest $request,
        FiscalDocumentRegister $register,
    ): Response|RedirectResponse {
        $legalEntity = $request->currentLegalEntity();

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Inertia::encryptHistory();

        $canPrepareDocuments = $request->user()?->can('create', FiscalDocument::class) === true;
        $selected = $request->selectedDocument();

        return Inertia::render('Documents/Index', [
            ...$register->for($legalEntity, $request->filters(), $canPrepareDocuments),
            // Loaded on its own when a row is opened, so picking a document
            // never re-runs the list query.
            'selected' => fn (): ?array => $selected === null
                ? null
                : $register->selected($legalEntity, $selected, $canPrepareDocuments),
        ]);
    }
}
