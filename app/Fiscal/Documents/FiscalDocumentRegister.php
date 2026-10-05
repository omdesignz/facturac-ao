<?php

namespace App\Fiscal\Documents;

use App\AgtSubmissionStatus;
use App\Analytics\DocumentSnapshot;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class FiscalDocumentRegister
{
    public function __construct(private DocumentSnapshot $snapshot) {}

    /**
     * One document for the detail panel: the same row the list shows, its
     * snapshot and its history.
     *
     * Looked up inside the company only, so a public id from another
     * workspace finds nothing rather than someone else's invoice.
     *
     * @return array<string, mixed>|null
     */
    public function selected(LegalEntity $legalEntity, string $publicId, bool $canPrepareDocuments): ?array
    {
        $document = FiscalDocument::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', $publicId)
            ->with([
                'customer:id,public_id,email',
                'establishment:id,public_id,name,code',
                'submissions' => fn ($query) => $query->latest('id'),
            ])
            ->first();

        if (! $document instanceof FiscalDocument) {
            return null;
        }

        return [
            ...$this->present($document, $canPrepareDocuments),
            'snapshot' => $this->snapshot->describe($document, CarbonImmutable::now()),
            'history' => $this->snapshot->history($document),
        ];
    }

    /**
     * @param  array{
     *     q: string,
     *     status: string,
     *     family: string,
     *     type: string,
     *     establishment: string,
     *     from: string,
     *     to: string
     * }  $filters
     * @return array<string, mixed>
     */
    public function for(
        LegalEntity $legalEntity,
        array $filters,
        bool $canPrepareDocuments,
    ): array {
        $query = FiscalDocument::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->select([
                'id',
                'public_id',
                'workspace_id',
                'legal_entity_id',
                'establishment_id',
                'customer_id',
                'document_type',
                'status',
                'document_no',
                'document_date',
                'due_date',
                'currency_code',
                'customer_name',
                'customer_tax_identification_number',
                'gross_total_minor',
                'issued_at',
                'sent_to_customer_at',
                'sent_to_email',
                'send_count',
                'created_at',
                'updated_at',
            ])
            ->with([
                'customer:id,public_id,email',
                'establishment:id,public_id,name,code',
                'submissions:id,public_id,fiscal_document_id,status,request_id,safe_message,updated_at',
            ]);

        $this->applyFilters($query, $legalEntity, $filters);

        $documents = $query
            ->latest('document_date')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return [
            'documents' => [
                'data' => array_values($documents->getCollection()
                    ->map(fn (FiscalDocument $document): array => $this->present(
                        $document,
                        $canPrepareDocuments,
                    ))
                    ->all()),
                'links' => $documents->linkCollection()->all(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
            'summary' => $this->summary($legalEntity),
            'filters' => $filters,
            'options' => [
                'statuses' => [
                    ['value' => 'all', 'label' => 'Todos os estados'],
                    ['value' => 'draft', 'label' => 'Rascunhos'],
                    ['value' => 'active', 'label' => 'Em curso'],
                    ['value' => 'valid', 'label' => 'Validados'],
                    ['value' => 'attention', 'label' => 'Requer atenção'],
                ],
                'families' => [
                    ['value' => 'all', 'label' => 'Todos'],
                    ['value' => 'invoice', 'label' => 'Facturas'],
                    ['value' => 'receipt', 'label' => 'Recibos'],
                    ['value' => 'adjustment', 'label' => 'Notas de correcção'],
                ],
                'types' => array_map(
                    fn (FiscalDocumentType $type): array => [
                        'value' => $type->value,
                        'label' => "{$type->value} · {$type->label()}",
                    ],
                    FiscalDocumentType::issuable(),
                ),
                'establishments' => array_values($legalEntity->establishments()
                    ->orderByDesc('is_head_office')
                    ->orderBy('name')
                    ->get(['public_id', 'name', 'code', 'is_active'])
                    ->map(fn ($establishment): array => [
                        'value' => $establishment->public_id,
                        'label' => $establishment->name,
                        'hint' => $establishment->is_active
                            ? $establishment->code
                            : "{$establishment->code} · inactivo",
                    ])
                    ->all()),
            ],
            'permissions' => [
                'create' => $canPrepareDocuments,
            ],
        ];
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @param  array{
     *     q: string,
     *     status: string,
     *     family: string,
     *     type: string,
     *     establishment: string,
     *     from: string,
     *     to: string
     * }  $filters
     */
    private function applyFilters(
        Builder $query,
        LegalEntity $legalEntity,
        array $filters,
    ): void {
        if ($filters['q'] !== '') {
            $search = "%{$filters['q']}%";

            $query->where(function (Builder $matching) use ($search): void {
                $matching
                    ->where('document_no', 'like', $search)
                    ->orWhere('customer_name', 'like', $search)
                    ->orWhere('customer_tax_identification_number', 'like', $search);
            });
        }

        if ($filters['type'] !== '') {
            $query->where('document_type', $filters['type']);
        } elseif ($filters['family'] !== 'all') {
            $query->whereIn('document_type', $this->familyTypes($filters['family']));
        }

        if ($filters['establishment'] !== '') {
            $establishmentId = $legalEntity->establishments()
                ->where('public_id', $filters['establishment'])
                ->value('id');

            $query->where('establishment_id', $establishmentId);
        }

        if ($filters['from'] !== '') {
            $query->whereDate('document_date', '>=', $filters['from']);
        }

        if ($filters['to'] !== '') {
            $query->whereDate('document_date', '<=', $filters['to']);
        }

        $this->applyWorkflowStatus($query, $legalEntity, $filters['status']);
    }

    /** @param Builder<FiscalDocument> $query */
    private function applyWorkflowStatus(
        Builder $query,
        LegalEntity $legalEntity,
        string $status,
    ): void {
        if ($status === 'all') {
            return;
        }

        if ($status === 'draft') {
            $query->where('status', FiscalDocumentStatus::Draft);

            return;
        }

        [$submissionStatuses, $legacyStatuses] = match ($status) {
            'active' => [
                [
                    AgtSubmissionStatus::Pending,
                    AgtSubmissionStatus::Sending,
                    AgtSubmissionStatus::Retrying,
                    AgtSubmissionStatus::Received,
                    AgtSubmissionStatus::Processing,
                ],
                [
                    FiscalDocumentStatus::Issued,
                    FiscalDocumentStatus::Received,
                    FiscalDocumentStatus::Processing,
                ],
            ],
            'valid' => [
                [AgtSubmissionStatus::Valid],
                [FiscalDocumentStatus::Valid],
            ],
            'attention' => [
                [
                    AgtSubmissionStatus::Invalid,
                    AgtSubmissionStatus::Rejected,
                    AgtSubmissionStatus::Cancelled,
                    AgtSubmissionStatus::Failed,
                ],
                [FiscalDocumentStatus::Invalid, FiscalDocumentStatus::Contingency],
            ],
        };

        $query->where(function (Builder $workflow) use (
            $legalEntity,
            $submissionStatuses,
            $legacyStatuses,
        ): void {
            $workflow
                ->whereIn(
                    'id',
                    $this->submissionDocumentIds($legalEntity, $submissionStatuses),
                )
                ->orWhere(function (Builder $legacy) use ($legalEntity, $legacyStatuses): void {
                    $legacy
                        ->whereNotIn('id', $this->submissionDocumentIds($legalEntity))
                        ->whereIn('status', $legacyStatuses);
                });
        });
    }

    /**
     * @param  list<AgtSubmissionStatus>  $statuses
     * @return Builder<AgtSubmission>
     */
    private function submissionDocumentIds(
        LegalEntity $legalEntity,
        array $statuses = [],
    ): Builder {
        return AgtSubmission::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                $statuses !== [],
                fn (Builder $query): Builder => $query->whereIn('status', $statuses),
            )
            ->select('fiscal_document_id');
    }

    /** @return list<string> */
    private function familyTypes(string $family): array
    {
        return array_values(array_map(
            fn (FiscalDocumentType $type): string => $type->value,
            array_filter(
                FiscalDocumentType::issuable(),
                fn (FiscalDocumentType $type): bool => match ($family) {
                    'receipt' => $type->isReceipt(),
                    'adjustment' => $type->isAdjustment(),
                    'invoice' => ! $type->isReceipt() && ! $type->isAdjustment(),
                },
            ),
        ));
    }

    /** @return array{total: int, draft: int, active: int, valid: int, attention: int} */
    private function summary(LegalEntity $legalEntity): array
    {
        $submissionCounts = $legalEntity->agtSubmissions()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $legacyCounts = $legalEntity->fiscalDocuments()
            ->whereNotIn('id', $this->submissionDocumentIds($legalEntity))
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'total' => $legalEntity->fiscalDocuments()->count(),
            'draft' => (int) ($legacyCounts[FiscalDocumentStatus::Draft->value] ?? 0),
            'active' => $this->sumCounts($submissionCounts, [
                AgtSubmissionStatus::Pending,
                AgtSubmissionStatus::Sending,
                AgtSubmissionStatus::Retrying,
                AgtSubmissionStatus::Received,
                AgtSubmissionStatus::Processing,
            ]) + $this->sumCounts($legacyCounts, [
                FiscalDocumentStatus::Issued,
                FiscalDocumentStatus::Received,
                FiscalDocumentStatus::Processing,
            ]),
            'valid' => $this->sumCounts($submissionCounts, [AgtSubmissionStatus::Valid])
                + $this->sumCounts($legacyCounts, [FiscalDocumentStatus::Valid]),
            'attention' => $this->sumCounts($submissionCounts, [
                AgtSubmissionStatus::Invalid,
                AgtSubmissionStatus::Rejected,
                AgtSubmissionStatus::Cancelled,
                AgtSubmissionStatus::Failed,
            ]) + $this->sumCounts($legacyCounts, [
                FiscalDocumentStatus::Invalid,
                FiscalDocumentStatus::Contingency,
            ]),
        ];
    }

    /**
     * @param  Collection<string, int|string>  $counts
     * @param  list<AgtSubmissionStatus|FiscalDocumentStatus>  $statuses
     */
    private function sumCounts(Collection $counts, array $statuses): int
    {
        return array_sum(array_map(
            fn (AgtSubmissionStatus|FiscalDocumentStatus $status): int => (int) ($counts[$status->value] ?? 0),
            $statuses,
        ));
    }

    /** @return array<string, mixed> */
    private function present(FiscalDocument $document, bool $canPrepareDocuments): array
    {
        $submission = $document->submissions->first();
        $workflowStatus = $submission?->status->value ?? $document->status->value;
        $workflowLabel = $submission?->status->label() ?? $document->status->label();
        $deliveryEmail = $document->customer?->email;

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'family' => $this->family($document->document_type),
            'document_date' => $document->document_date->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'customer_public_id' => $document->customer?->public_id,
            'customer_name' => $document->customer_name,
            'customer_tax_identification_number' => $document->customer_tax_identification_number,
            'establishment' => [
                'public_id' => $document->establishment->public_id,
                'name' => $document->establishment->name,
                'code' => $document->establishment->code,
            ],
            'gross_total_minor' => $document->gross_total_minor,
            'currency_code' => $document->currency_code,
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'workflow_status' => $workflowStatus,
            'workflow_label' => $workflowLabel,
            'workflow_message' => $submission?->safe_message,
            'submission' => $submission instanceof AgtSubmission ? [
                'public_id' => $submission->public_id,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'request_id' => $submission->request_id,
            ] : null,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'updated_at' => $document->updated_at?->toIso8601String(),
            'sent_at' => $document->sent_to_customer_at?->toIso8601String(),
            'send_count' => $document->send_count,
            'delivery_email' => is_string($deliveryEmail) && trim($deliveryEmail) !== ''
                ? trim($deliveryEmail)
                : null,
            'can_edit' => $canPrepareDocuments && $document->isMutable(),
            'can_print' => $document->document_no !== null,
            'can_send' => $canPrepareDocuments
                && $document->document_no !== null
                && is_string($deliveryEmail)
                && trim($deliveryEmail) !== '',
        ];
    }

    private function family(FiscalDocumentType $type): string
    {
        if ($type->isAdjustment()) {
            return 'adjustment';
        }

        if ($type->isReceipt()) {
            return 'receipt';
        }

        return 'invoice';
    }
}
