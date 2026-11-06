<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Actions\CreateMobileCreditNote;
use App\Actions\PrepareRepeatInvoice;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\Documents\DocumentLiveActivities;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\FiscalDocumentRegister;
use App\Http\Requests\MobileListRequest;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The web's register, read from the phone: the same rows, statuses and history.
 */
class MobileDocumentController
{
    public function __construct(private readonly FiscalDocumentRegister $register) {}

    public function index(MobileListRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');

        $documents = $this->register->paginate($legalEntity, [
            'q' => $request->search(),
            'status' => 'all',
            'family' => 'all',
            'type' => '',
            'establishment' => '',
            'from' => '',
            'to' => '',
        ]);

        return $request->pageOf($documents, array_values($documents->getCollection()
            ->map(fn (FiscalDocument $document): array => $this->row($this->register->present($document, false)))
            ->all()));
    }

    public function show(Request $request, string $documentPublicId, FiscalDocumentPresenter $presenter): JsonResponse
    {
        Gate::authorize('viewAny', FiscalDocument::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $selected = $this->register->selected($legalEntity, $documentPublicId, false);

        abort_if($selected === null, 404);

        $document = FiscalDocument::query()->where('public_id', $documentPublicId)->firstOrFail();
        $snapshot = $selected['snapshot'];

        return response()->json(['data' => [
            ...$this->row($selected),
            'type_label' => $selected['document_type_label'],
            'customer_public_id' => $selected['customer_public_id'],
            'customer_tax_identification_number' => $document->customer->tax_identification_number ?? $selected['customer_tax_identification_number'],
            'is_returned' => $selected['workflow_status'] === 'invalid',
            // Where the lock-screen tracker stands, for a phone without pushes.
            'live_stage' => DocumentLiveActivities::stageOf($selected['workflow_status']),
            'due_date' => $selected['due_date'],
            'outstanding_minor' => $snapshot['outstanding_minor'],
            'days_past_due' => $snapshot['days_past_due'],
            'message' => $selected['workflow_message'],
            // The note sheet says why when it cannot be issued; this only says whether to offer it.
            'can_repeat' => Gate::allows('create', FiscalDocument::class) && PrepareRepeatInvoice::supports($document),
            'can_credit' => in_array($document->document_type, CreateMobileCreditNote::CORRECTABLE, true)
                && $selected['workflow_status'] === 'valid'
                && Gate::allows('create', FiscalDocument::class),
            'can_remind' => $selected['customer_public_id'] !== null && $snapshot['days_past_due'] > 0,
            // A document the AGT refused is not something to hand a customer.
            'share_url' => $selected['can_print'] && $selected['workflow_status'] !== 'invalid' ? $presenter->signedUrl($document) : null,
            'web_url' => route('documents.index', ['documento' => $documentPublicId]),
            'history' => array_map(fn (array $event): array => [
                'label' => $event['label'],
                'occurred_at' => $event['occurred_at'],
            ], $selected['history']),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function row(array $document): array
    {
        return [
            'public_id' => $document['public_id'],
            'document_no' => $document['document_no'],
            'customer_name' => $document['customer_name'],
            'document_date' => $document['document_date'],
            'gross_total_minor' => $document['gross_total_minor'],
            'currency_code' => $document['currency_code'],
            'status_label' => $document['workflow_label'],
            'tone' => match (true) {
                $document['workflow_status'] === 'valid' => 'positive',
                in_array($document['workflow_status'], CurrentAgtState::ATTENTION, true) => 'critical',
                default => 'neutral',
            },
            'opened_by_customer' => $document['opened_at'] !== null,
        ];
    }
}
