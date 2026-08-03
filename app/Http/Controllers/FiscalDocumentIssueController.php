<?php

namespace App\Http\Controllers;

use App\Actions\IssueFiscalDocument;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Http\Requests\IssueFiscalDocumentRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class FiscalDocumentIssueController extends Controller
{
    public function __invoke(
        IssueFiscalDocumentRequest $request,
        FiscalDocument $fiscalDocument,
        IssueFiscalDocument $issueFiscalDocument,
    ): RedirectResponse {
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
        $user = $request->user();

        abort_unless(
            $legalEntity instanceof LegalEntity
                && $user instanceof User
                && $fiscalDocument->workspace_id === $legalEntity->workspace_id
                && $fiscalDocument->legal_entity_id === $legalEntity->id,
            404,
        );

        try {
            $submission = $issueFiscalDocument->execute(
                $fiscalDocument,
                $user,
                (string) $request->validated('series_public_id'),
                (int) $request->validated('revision'),
            );
        } catch (FiscalFinalizationBlocked $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('agt.submissions.index', ['submission' => $submission->public_id])
            ->with('success', 'Factura emitida. A entrega à AGT prossegue em segundo plano.');
    }
}
