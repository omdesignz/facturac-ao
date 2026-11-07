<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Fiscal\Documents\CustomerStatementPdf;
use App\Fiscal\Documents\QuotePdf;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The customer statement and the quote as PDFs, for the phone's share sheet.
 *
 * Rendered now and never stored: the phone keeps the file only as long as the
 * share sheet needs it. Neither is a fiscal document.
 */
class MobileBusinessPdfController
{
    public function statement(Request $request, string $customerPublicId, CustomerStatementPdf $pdf): Response
    {
        $customer = $this->legalEntity($request)->customers()->where('public_id', $customerPublicId)->firstOrFail();

        Gate::authorize('view', $customer);

        return $this->file($pdf->render($customer), $pdf->filename($customer));
    }

    public function quote(Request $request, string $quotePublicId, QuotePdf $pdf): Response
    {
        Gate::authorize('viewAny', Customer::class);

        $quote = Quote::query()
            ->where('legal_entity_id', $this->legalEntity($request)->id)
            ->where('public_id', $quotePublicId)
            ->firstOrFail();

        return $this->file($pdf->render($quote), $pdf->filename($quote));
    }

    private function file(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            // Personal data, and each answer is a fresh render.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function legalEntity(Request $request): LegalEntity
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        return $legalEntity;
    }
}
