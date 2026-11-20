<?php

namespace App\Fiscal;

use App\Actions\CreateMobileCreditNote;
use App\Actions\CreateMobileInvoice;
use App\Fiscal\Documents\CreditNoteEligibility;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues a document for an integration, inside the command ledger's
 * transaction, through the same draft and issue steps as the web and the
 * phone. The integration's sponsor is the issuer of record; the command audit
 * names the integration that asked.
 */
final readonly class DocumentCommands
{
    public function __construct(private CreateMobileInvoice $invoices, private CreateMobileCreditNote $creditNotes) {}

    public function issue(IntegrationCommandContext $context, DocumentIssueInput $input, string $operationId): FiscalDocument
    {
        abort_unless(DB::connection()->transactionLevel() > 0, 503);
        $context->authorize(locked: true, capability: CommandCapability::DocumentIssue);
        abort_unless(DB::table('external_command_operations')->where('operation_id', $operationId)
            ->where('integration_id', $context->integrationId)->where('origin_credential_id', $context->credentialId)
            ->where('workspace_id', $context->workspaceId)->where('legal_entity_id', $context->entityId)
            ->where('environment', $context->environment)->where('command', CommandCapability::DocumentIssue->value)
            ->where('capability_version', 1)->where('state', 'executing')->exists(), 403);
        $entity = LegalEntity::query()->useWritePdo()->whereKey($context->entityId)->where('workspace_id', $context->workspaceId)->first();
        $issuer = User::query()->useWritePdo()->whereKey($context->sponsorId)->first();
        abort_unless($entity instanceof LegalEntity && $issuer instanceof User, 403);
        $attributes = $input->attributes;

        $document = $input->type === FiscalDocumentType::CreditNote
            ? $this->creditNotes->issueNote($entity, $issuer, $this->source($context, $attributes['references_document_public_id']), [
                'reason' => $attributes['reason'],
                'lines' => $attributes['lines'],
                'expected_total_minor' => $attributes['expected_total_minor'],
            ], $context->environment)
            : $this->invoices->issueDocument($entity, $issuer, [
                'document_type' => $input->type,
                'customer_public_id' => $attributes['customer_public_id'],
                'lines' => $attributes['lines'],
                'notes' => $attributes['notes'],
                'payment_method' => $attributes['payment_method'] === null ? null : PaymentMethod::from($attributes['payment_method']),
                'expected_total_minor' => $attributes['expected_total_minor'],
            ], $context->environment);

        $document->refresh();
        abort_unless($document->status === FiscalDocumentStatus::Issued && $document->document_type === $input->type
            && $document->environment === $context->environment && (int) $document->legal_entity_id === $context->entityId, 503);
        CommandAudit::record($context, CommandCapability::DocumentIssue->creationEvent(), 'succeeded', $operationId, $document, CommandCapability::DocumentIssue);

        return $document;
    }

    /**
     * The invoice a credit note corrects: issued by this company, in this
     * environment, and of a kind a note may correct. Anything else reads as
     * not there, so another company's numbering is never revealed.
     */
    private function source(IntegrationCommandContext $context, string $publicId): FiscalDocument
    {
        return FiscalDocument::query()->useWritePdo()
            ->where('public_id', $publicId)
            ->where('workspace_id', $context->workspaceId)
            ->where('legal_entity_id', $context->entityId)
            ->where('environment', $context->environment)
            ->where('status', FiscalDocumentStatus::Issued)
            ->whereIn('document_type', CreditNoteEligibility::CORRECTABLE)
            ->first()
            ?? throw ValidationException::withMessages([
                'references_document_public_id' => 'Não há uma factura ou factura-recibo emitida com este identificador.',
            ]);
    }
}
