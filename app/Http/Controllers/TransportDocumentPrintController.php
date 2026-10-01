<?php

namespace App\Http\Controllers;

use App\Fiscal\Documents\TransportDocumentPdf;
use App\Fiscal\Documents\TransportDocumentPresenter;
use App\Models\TransportDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransportDocumentPrintController extends Controller
{
    public function __construct(
        private TransportDocumentPresenter $presenter,
        private TransportDocumentPdf $pdf,
    ) {}

    public function show(Request $request, TransportDocument $transportDocument): Response
    {
        abort_if($transportDocument->document_no === null, 404);
        Gate::authorize('view', $transportDocument);

        return Inertia::render('TransportDocuments/Print', [
            'transportDocument' => $this->presenter->forPrint($transportDocument),
        ]);
    }

    public function pdf(Request $request, TransportDocument $transportDocument): HttpResponse
    {
        abort_if($transportDocument->document_no === null, 404);
        Gate::authorize('view', $transportDocument);

        return response($this->pdf->render($transportDocument), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdf->filename($transportDocument).'"',
        ]);
    }
}
