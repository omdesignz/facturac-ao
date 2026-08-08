<?php

namespace App\Http\Controllers;

use App\Fiscal\Documents\FiscalDocumentPdf;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Models\FiscalDocument;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The document as the customer receives it.
 *
 * Reached two ways, which is why the check is not a plain policy call. Someone
 * in the company opens it from the app; the customer opens it from a signed
 * link in their email and has no account at all. Both see the same page, so
 * what a company prints and what a customer keeps cannot disagree.
 */
class FiscalDocumentPrintController extends Controller
{
    public function __construct(
        private FiscalDocumentPresenter $presenter,
        private FiscalDocumentPdf $pdf,
    ) {}

    public function __invoke(Request $request, FiscalDocument $fiscalDocument): Response
    {
        // A draft has no number, no signature and nothing filed behind it.
        // Printing one would hand someone a document that does not exist.
        abort_if($fiscalDocument->document_no === null, 404);

        abort_unless(
            $request->hasValidSignature() || $this->belongsToCurrentCompany($request, $fiscalDocument),
            403,
        );

        return Inertia::render('Documents/Print', [
            'document' => $this->presenter->forPrint($fiscalDocument),
            // The signed link is the one that goes to the customer; only
            // someone already inside the company may hand it out.
            'shareUrl' => $request->hasValidSignature()
                ? null
                : $this->presenter->signedUrl($fiscalDocument),
        ]);
    }

    /**
     * Whether the person asking works at the company that issued this.
     *
     * Resolved from the user rather than the request: this route is public so
     * the customer's signed link works, which means the workspace middleware
     * never ran and there is no current workspace on the request to read.
     */
    /** The same document as a PDF, laid out the way the AGT publishes it. */
    public function pdf(Request $request, FiscalDocument $fiscalDocument): HttpResponse
    {
        abort_if($fiscalDocument->document_no === null, 404);

        abort_unless(
            $request->hasValidSignature() || $this->belongsToCurrentCompany($request, $fiscalDocument),
            403,
        );

        return response($this->pdf->render($fiscalDocument), 200, [
            'Content-Type' => 'application/pdf',
            // Inline so it opens in the viewer; the viewer's own save button
            // is a better download than forcing one.
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($fiscalDocument).'"',
        ]);
    }

    private function belongsToCurrentCompany(Request $request, FiscalDocument $document): bool
    {
        $user = $request->user();

        return $user instanceof User
            && $user->workspaces()->whereKey($document->workspace_id)->exists();
    }
}
