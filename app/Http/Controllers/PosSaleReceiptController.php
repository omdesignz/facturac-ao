<?php

namespace App\Http\Controllers;

use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Models\PosSale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The till receipt for a sale.
 *
 * The document is exactly what the full-page print shows, with the same frozen
 * issuer, QR code and certification text; only the cash handed over and given
 * back are added. Reached by people inside the company only: there is no
 * signed public link for a till receipt.
 */
class PosSaleReceiptController extends Controller
{
    use ResolvesPosCompany;

    public function __construct(
        private FiscalDocumentPresenter $presenter,
        private FiscalDocumentPrints $prints,
    ) {}

    public function __invoke(Request $request, PosSale $posSale): Response
    {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posSale->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('view', $posSale);

        $posSale->load(['fiscalDocument', 'session.register', 'createdBy']);
        $document = $posSale->fiscalDocument;

        return Inertia::render('Pos/Receipt', [
            'document' => $this->presenter->forPrint($document, $this->prints->verified($document)),
            'sale' => [
                'tendered_minor' => $posSale->tendered_minor,
                'change_minor' => $posSale->change_minor,
                'payment_method_label' => $posSale->payment_method->label(),
                'register_name' => $posSale->session->register->name,
                'cashier_name' => $posSale->createdBy->name,
            ],
        ]);
    }
}
