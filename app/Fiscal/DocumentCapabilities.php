<?php

namespace App\Fiscal;

use App\Actions\ApproveRecurringInvoice;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\Documents\QualifiedAgtStatusRead;
use App\Models\FiscalDocument;
use App\Models\RecurringInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Internal entry point. Adapters cannot supply tenant or authority overrides. */
final readonly class DocumentCapabilities
{
    public function __construct(private QualifiedAgtStatusRead $qualifiedRead) {}

    /** @return array<string, mixed> */
    public function readQualifiedAgtStatus(DocumentReadContext $context, DocumentReadCommand $command): array
    {
        $context->authorize('documents.agt-status.read');
        $document = $this->qualifiedRead->snapshot($context, $command);
        $data = $this->qualifiedRead->present($document, $this->qualifiedRead->databaseNow());
        $audit = $context->audit();
        RequiredAudit::record(fn () => activity('capability')->performedOn($document)->causedBy($context->auditCauser())
            ->event('documents.agt-status.read')->withProperties([...$audit, 'operation_id' => $audit['operation_id'] ?? (string) Str::uuid(),
                'outcome' => 'succeeded', 'capability_version' => 2, 'user_agent' => null,
                'operation' => 'documents.agt-status.read', 'method' => request()->method(), 'resource_public_id' => $document->public_id])->log('Scoped qualified AGT read'));
        $context->recordSuccessfulUse();

        return $data;
    }

    /** @return array<string, mixed> */
    public function read(DocumentReadContext $context, string $publicId): array
    {
        $context->authorize('documents.read');
        $document = $this->documents($context)->where('public_id', $publicId)->firstOrFail();
        RequiredAudit::record(fn () => activity('capability')->performedOn($document)->causedBy($context->auditCauser())
            ->event('documents.read')->withProperties([...$context->audit(), 'capability_version' => 1, 'resource_public_id' => $document->public_id])->log('Scoped document read'));

        $context->recordSuccessfulUse();

        return $this->present($document);
    }

    /** @return array<string, mixed> */
    public function readDocument(DocumentReadContext $context, DocumentReadCommand $command): array
    {
        return $this->read($context, $command->publicId);
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function listDocuments(DocumentReadContext $context, DocumentListCommand $command): LengthAwarePaginator
    {
        $context->authorize('documents.read');
        $query = $this->documents($context);
        if ($command->agtStatus !== null) {
            CurrentAgtState::legacyMatching($query, [$command->agtStatus]);
        }
        if ($command->type !== null) {
            $query->where('document_type', $command->type->value);
        }
        $documents = $query->latest('id')->paginate($command->perPage, page: $command->page);
        RequiredAudit::record(fn () => activity('capability')->causedBy($context->auditCauser())->event('documents.list')
            ->withProperties([...$context->audit(), 'capability_version' => 1, 'page' => $command->page,
                'per_page' => $command->perPage, 'result_count' => $documents->count()])->log('Scoped document list'));

        $context->recordSuccessfulUse();

        return new LengthAwarePaginator($documents->getCollection()->map(fn (FiscalDocument $document): array => $this->present($document)),
            $documents->total(), $documents->perPage(), $documents->currentPage());
    }

    /** @return Builder<FiscalDocument> */
    private function documents(DocumentReadContext $context): Builder
    {
        return FiscalDocument::query()->where('workspace_id', $context->workspaceId())->where('legal_entity_id', $context->legalEntityId())
            ->where('environment', $context->environment()->value)
            ->select(['id', 'public_id', 'workspace_id', 'legal_entity_id', 'document_no', 'document_type', 'environment', 'revision', 'currency_code', 'gross_total_minor', 'status'])
            ->with('submissions:id,fiscal_document_id,status');
    }

    /** @return array<string, mixed> */
    private function present(FiscalDocument $document): array
    {
        return [...$document->only(['public_id', 'document_no', 'environment', 'revision', 'currency_code', 'gross_total_minor']),
            'document_type' => $document->document_type->value,
            'issue_status' => $document->status->value,
            'agt_status' => CurrentAgtState::legacyWorkflowStatus($document)->value,
            'agt_status_source' => $document->isMutable() ? 'draft' : ($document->submissions->isEmpty() ? 'legacy' : 'submission')];
    }

    public function approveRecurring(ExecutionContext $context, string $publicId, CarbonImmutable $expiresAt): int
    {
        return DB::transaction(function () use ($context, $publicId, $expiresAt): int {
            $context->authorize('recurring.approve');
            $profile = RecurringInvoice::query()->where('public_id', $publicId)
                ->where('workspace_id', $context->workspaceId())->where('legal_entity_id', $context->legalEntityId())
                ->lockForUpdate()->firstOrFail();

            return Context::scope(fn (): int => app(ApproveRecurringInvoice::class)->execute($profile,
                User::findOrFail($context->actorId), $context->environment, $expiresAt),
                data: ['request_id' => $context->correlationId]);
        });
    }
}
