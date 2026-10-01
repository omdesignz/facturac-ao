<?php

namespace App\Http\Controllers;

use App\Actions\CancelTransportDocument;
use App\Exceptions\BillingActionRefused;
use App\Http\Requests\CancelTransportDocumentRequest;
use App\Models\TransportDocument;
use Illuminate\Http\RedirectResponse;

class TransportDocumentCancellationController extends Controller
{
    public function __invoke(
        CancelTransportDocumentRequest $request,
        TransportDocument $transportDocument,
        CancelTransportDocument $cancel,
    ): RedirectResponse {
        try {
            $cancel->execute(
                $transportDocument,
                $request->user(),
                (string) $request->validated('reason'),
            );
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Guia anulada; o registo permanece no SAF-T.');
    }
}
