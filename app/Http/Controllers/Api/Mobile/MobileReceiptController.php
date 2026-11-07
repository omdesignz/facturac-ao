<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Fiscal\Documents\AgtQrCode;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Fiscal\Documents\FiscalDocumentRegister;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\PosSale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The till receipt as data, for the phone to lay out on a thermal printer.
 *
 * The same fields the web's `Pos/Receipt` page prints, from the same frozen
 * issuer and checks, so paper from the phone and paper from the browser agree.
 * The QR travels as the AGT's own PNG, not as the link to re-encode: the phone
 * prints those exact modules, and the encoding never forks.
 */
class MobileReceiptController
{
    public function __construct(
        private readonly FiscalDocumentRegister $register,
        private readonly FiscalDocumentPresenter $presenter,
        private readonly FiscalDocumentPrints $prints,
        private readonly AgtQrCode $qrCode,
    ) {}

    public function __invoke(Request $request, string $documentPublicId): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $selected = $this->register->selected($legalEntity, $documentPublicId, false);

        abort_if($selected === null, 404);

        $document = FiscalDocument::query()->where('public_id', $documentPublicId)->firstOrFail();

        // A draft has no number and nothing filed behind it; a refused one is
        // not something to hand a customer.
        abort_if($document->document_no === null || $selected['workflow_status'] === 'invalid', 404);

        $print = $this->presenter->forPrint($document, $this->prints->verified($document));
        $verificationUrl = $print['authenticity']['verification_url'];

        $sale = PosSale::query()
            ->where('fiscal_document_id', $document->id)
            ->with(['session.register', 'createdBy'])
            ->first();

        return response()->json(['data' => [
            'document' => [
                ...array_intersect_key($print, array_flip([
                    'public_id', 'document_no', 'document_type', 'document_type_label', 'status_label',
                    'agt_accepted', 'document_date', 'due_date', 'issued_at', 'currency_code', 'notes',
                    'footer_text', 'payment_method_label', 'company', 'customer', 'tax_summary',
                    'support_email',
                ])),
                'lines' => array_map(fn (array $line): array => array_intersect_key($line, array_flip([
                    'line_number', 'operation_date', 'product_code', 'product_description', 'unit_of_measure',
                    'quantity', 'unit_price_minor', 'discount_rate', 'net_amount_minor', 'tax_amount_minor',
                    'gross_amount_minor', 'tax_rate', 'tax_exemption_code',
                ])), $print['lines']),
                'totals' => array_intersect_key($print['totals'], array_flip([
                    'net_minor', 'tax_minor', 'gross_minor', 'settled_minor',
                ])),
                'authenticity' => [
                    'software_validation_number' => $print['authenticity']['software_validation_number'],
                    'digest' => $print['authenticity']['digest'],
                    'qr_png' => $verificationUrl === null ? null : base64_encode($this->qrCode->png($verificationUrl)),
                ],
            ],
            // Only a till sale has money handed over, a till and a cashier.
            'sale' => $sale === null ? null : [
                'tendered_minor' => $sale->tendered_minor,
                'change_minor' => $sale->change_minor,
                'payment_method_label' => $sale->payment_method->label(),
                'register_name' => $sale->session->register->name,
                'cashier_name' => $sale->createdBy->name,
            ],
        ]]);
    }
}
