<?php

namespace App\Http\Controllers;

use App\Actions\SendFiscalDocumentToCustomer;
use App\Exceptions\BillingActionRefused;
use App\Models\FiscalDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Sends an already-issued document to the customer, on request.
 */
class FiscalDocumentDeliveryController extends Controller
{
    public function __invoke(
        Request $request,
        FiscalDocument $fiscalDocument,
        SendFiscalDocumentToCustomer $send,
    ): RedirectResponse {
        Gate::authorize('deliver', $fiscalDocument);

        try {
            $send->execute($fiscalDocument, $request->user());
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with(
            'success',
            "Documento enviado para {$fiscalDocument->fresh()->sent_to_email}.",
        );
    }
}
