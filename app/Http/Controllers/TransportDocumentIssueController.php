<?php

namespace App\Http\Controllers;

use App\Actions\IssueTransportDocument;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\IssueTransportDocumentRequest;
use App\Models\TransportDocument;
use Illuminate\Http\RedirectResponse;

class TransportDocumentIssueController extends Controller
{
    public function __invoke(
        IssueTransportDocumentRequest $request,
        TransportDocument $transportDocument,
        IssueTransportDocument $issue,
    ): RedirectResponse {
        try {
            $issued = $issue->execute(
                $transportDocument,
                $request->user(),
                (int) $request->validated('expected_revision'),
            );
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('transport-documents.edit', $issued)
            ->with('success', "{$issued->document_no} emitida e pronta para acompanhar a mercadoria.");
    }
}
