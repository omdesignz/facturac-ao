<?php

namespace App\Actions;

use App\AgtConnectionStatus;
use App\AgtSubmissionOperation;
use App\AgtSubmissionStatus;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Agt\V2_0\AgtRequestPayloadBuilder;
use App\Fiscal\Calculation\CalculatedFiscalDocument;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Calculation\FiscalReceiptCalculator;
use App\Fiscal\Documents\FiscalDocumentNumber;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentEventType;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Jobs\ArchiveFiscalDocumentPdf;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\FiscalSeries;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class IssueFiscalDocument
{
    public function __construct(
        private FiscalCalculator $calculator,
        private FiscalDocumentNumber $documentNumber,
        private ApplyFiscalDocumentStockMovements $applyStockMovements,
        private SendFiscalDocumentToCustomer $sendToCustomer,
        private FiscalDocumentPayloadBuilder $documentPayloadBuilder,
        private AgtRequestPayloadBuilder $requestPayloadBuilder,
        private JwsSigner $jwsSigner,
        private CanonicalJson $canonicalJson,
        private FiscalReceiptCalculator $receiptCalculator,
    ) {}

    public function execute(
        FiscalDocument $draft,
        User $issuer,
        string $seriesPublicId,
        int $expectedRevision,
    ): AgtSubmission {
        $submission = DB::transaction(function () use (
            $draft,
            $issuer,
            $seriesPublicId,
            $expectedRevision,
        ): AgtSubmission {
            $document = $this->lockedDraft($draft);

            if ($document->revision !== $expectedRevision) {
                throw FiscalFinalizationBlocked::because(
                    'O rascunho foi alterado noutra sessão. Recarregue a página antes de emitir.',
                );
            }

            $series = $this->lockedSeries($document, $seriesPublicId);
            $connection = $series->agtConnection;

            $this->assertDocumentCanBeIssued($document, $series, $connection);
            $this->assertCalculationIntegrity($document);

            $issuedAt = now('Africa/Luanda');
            $sequence = $series->next_number;
            $documentNo = $this->documentNumber->compose(
                $document->document_type,
                $series->series_code,
                $sequence,
            );
            $softwareKeyFingerprint = $this->fingerprint(
                (string) $connection->software_key_reference,
                'software',
            );
            $taxpayerKeyFingerprint = $this->fingerprint(
                (string) $connection->taxpayer_key_reference,
                'contribuinte',
            );

            if (! hash_equals((string) $connection->software_key_fingerprint, $softwareKeyFingerprint)
                || ! hash_equals((string) $connection->taxpayer_key_fingerprint, $taxpayerKeyFingerprint)) {
                throw FiscalFinalizationBlocked::because(
                    'Uma chave fiscal mudou desde a última verificação. Teste novamente a ligação à AGT.',
                );
            }

            $document->fill([
                'fiscal_series_id' => $series->id,
                'agt_connection_id' => $connection->id,
                'issued_by_user_id' => $issuer->id,
                'updated_by_user_id' => $issuer->id,
                'status' => FiscalDocumentStatus::Issued,
                'agt_document_status' => 'N',
                'document_no' => $documentNo,
                'issue_sequence' => $sequence,
                'software_product_id' => $connection->product_id,
                'software_product_version' => $connection->product_version,
                'software_validation_number' => $connection->software_validation_number,
                'software_key_fingerprint' => $softwareKeyFingerprint,
                'taxpayer_key_fingerprint' => $taxpayerKeyFingerprint,
                'system_entry_at' => $issuedAt,
                'frozen_at' => $issuedAt,
                'issued_at' => $issuedAt,
            ]);

            $signableObject = $this->documentPayloadBuilder->signableObject($document);
            $document->signable_payload_sha256 = hash(
                'sha256',
                $this->canonicalJson->encode($signableObject),
            );
            $document->document_jws = $this->jwsSigner->sign(
                $signableObject,
                (string) $connection->taxpayer_key_reference,
            );
            $document->document_payload_sha256 = hash(
                'sha256',
                $this->canonicalJson->encode($this->documentPayloadBuilder->document($document)),
            );

            $submissionUuid = (string) Str::uuid();
            $requestBody = $this->canonicalJson->encode(
                $this->requestPayloadBuilder->registerInvoice(
                    $connection,
                    $document,
                    $submissionUuid,
                    $issuedAt,
                ),
            );

            $document->save();

            $submission = $document->submissions()->create([
                'submission_uuid' => $submissionUuid,
                'workspace_id' => $document->workspace_id,
                'legal_entity_id' => $document->legal_entity_id,
                'agt_connection_id' => $connection->id,
                'operation' => AgtSubmissionOperation::RegisterInvoice,
                'schema_version' => $document->payload_schema_version,
                'status' => AgtSubmissionStatus::Pending,
                'request_body' => $requestBody,
                'request_body_sha256' => hash('sha256', $requestBody),
                'safe_message' => 'Documento emitido e colocado na fila segura para a AGT.',
                'attempt_count' => 0,
                'next_attempt_at' => $issuedAt,
            ]);

            // Inside the same transaction as the issuance: a document that
            // exists without its stock having moved is how an inventory quietly
            // stops matching the shelf.
            $this->applyStockMovements->execute($document, $issuer);

            $series->forceFill([
                'status' => $sequence >= $series->last_authorized_number
                    ? FiscalSeriesStatus::Closed
                    : FiscalSeriesStatus::InUse,
                'next_number' => $sequence + 1,
                'last_issued_number' => $sequence,
                'last_document_date' => $document->document_date,
            ])->save();

            $document->events()->createMany([
                [
                    'workspace_id' => $document->workspace_id,
                    'legal_entity_id' => $document->legal_entity_id,
                    'actor_user_id' => $issuer->id,
                    'event_type' => FiscalDocumentEventType::Issued,
                    'agt_document_status' => 'N',
                    'safe_context' => [
                        'document_no' => $documentNo,
                        'series_code' => $series->series_code,
                        'sequence' => $sequence,
                        'document_payload_sha256' => $document->document_payload_sha256,
                    ],
                    'occurred_at' => $issuedAt,
                ],
                [
                    'workspace_id' => $document->workspace_id,
                    'legal_entity_id' => $document->legal_entity_id,
                    'agt_submission_id' => $submission->id,
                    'actor_user_id' => $issuer->id,
                    'event_type' => FiscalDocumentEventType::SubmissionQueued,
                    'agt_document_status' => 'N',
                    'safe_context' => [
                        'submission_uuid' => $submissionUuid,
                        'request_body_sha256' => $submission->request_body_sha256,
                    ],
                    'occurred_at' => $issuedAt,
                ],
            ]);

            activity('fiscal-document')
                ->causedBy($issuer)
                ->performedOn($document)
                ->event('fiscal-document-issued')
                ->withProperties([
                    'workspace_id' => $document->workspace_id,
                    'legal_entity_id' => $document->legal_entity_id,
                    'document_public_id' => $document->public_id,
                    'document_no' => $documentNo,
                    'series_code' => $series->series_code,
                    'issue_sequence' => $sequence,
                    'submission_public_id' => $submission->public_id,
                    'request_body_sha256' => $submission->request_body_sha256,
                ])
                ->log('Documento fiscal emitido e transmissão AGT agendada.');

            return $submission;
        }, 5);

        SubmitAgtDocument::dispatch($submission->id)->afterCommit();
        // The as-issued PDF, kept before anything about the sheet can change.
        ArchiveFiscalDocumentPdf::dispatch($draft->id)->afterCommit();

        $this->sendToCustomerIfConfigured($draft->fresh(), $issuer);

        return $submission;
    }

    /**
     * Emails the document if this customer is set up for it.
     *
     * Outside the transaction and swallowing its own failure: a mail server
     * being down is not a reason to unwind an issuance the AGT has already been
     * told about. The failure is logged and the document can be resent by hand.
     */
    private function sendToCustomerIfConfigured(?FiscalDocument $document, User $issuer): void
    {
        if (! $document instanceof FiscalDocument) {
            return;
        }

        $document->loadMissing('customer');

        if (! $this->sendToCustomer->shouldSendAutomatically($document)) {
            return;
        }

        try {
            $this->sendToCustomer->execute($document, $issuer);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function lockedDraft(FiscalDocument $draft): FiscalDocument
    {
        $document = FiscalDocument::query()
            ->with(['legalEntity', 'establishment', 'lines.taxes'])
            ->whereKey($draft->id)
            ->where('workspace_id', $draft->workspace_id)
            ->where('legal_entity_id', $draft->legal_entity_id)
            ->lockForUpdate()
            ->first();

        if (! $document instanceof FiscalDocument) {
            throw (new ModelNotFoundException)->setModel(FiscalDocument::class, [$draft->id]);
        }

        if (! $document->isMutable()) {
            throw FiscalFinalizationBlocked::because('Este documento já foi emitido e não pode ser repetido.');
        }

        return $document;
    }

    private function lockedSeries(FiscalDocument $document, string $seriesPublicId): FiscalSeries
    {
        $series = FiscalSeries::query()
            ->with('agtConnection')
            ->where('public_id', $seriesPublicId)
            ->where('workspace_id', $document->workspace_id)
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('establishment_id', $document->establishment_id)
            ->lockForUpdate()
            ->first();

        if (! $series instanceof FiscalSeries) {
            throw FiscalFinalizationBlocked::because(
                'A série seleccionada não está disponível para este estabelecimento.',
            );
        }

        return $series;
    }

    private function assertDocumentCanBeIssued(
        FiscalDocument $document,
        FiscalSeries $series,
        AgtConnection $connection,
    ): void {
        if ($document->payload_schema_version !== '2.0' || $connection->schema_version !== '2.0') {
            throw FiscalFinalizationBlocked::because(
                'A AGT exige o contrato 2.0. Guarde novamente o rascunho e verifique a ligação AGT.',
            );
        }

        if (! in_array($document->document_type, FiscalDocumentType::issuable(), true)) {
            throw FiscalFinalizationBlocked::because('Este tipo de documento não está disponível para emissão no contrato 2.0.');
        }

        if (($document->document_type->requiresLines() && $document->lines->isEmpty())
            || $document->gross_total_minor <= 0) {
            throw FiscalFinalizationBlocked::because('A factura deve ter pelo menos uma linha e total positivo.');
        }

        if (mb_strlen($document->customer_name) > 200
            || ($document->adjustment_reason !== null && mb_strlen($document->adjustment_reason) > 60)
            || $document->lines->contains(fn (FiscalDocumentLine $line): bool => mb_strlen($line->product_description) > 200 || mb_strlen($line->unit_of_measure) > 20
            )) {
            throw FiscalFinalizationBlocked::because('Um campo excede os limites do contrato 2.0. Revise o rascunho.');
        }

        if ($series->document_type !== $document->document_type) {
            throw FiscalFinalizationBlocked::because('A série não corresponde ao tipo deste documento.');
        }

        if ($series->series_year !== (int) $document->document_date->format('Y')) {
            throw FiscalFinalizationBlocked::because('A série não corresponde ao ano fiscal do documento.');
        }

        if (! $series->canAllocate()) {
            throw FiscalFinalizationBlocked::because('A série está fechada ou sem números autorizados disponíveis.');
        }

        if ($series->contingency_indicator !== FiscalSeriesContingency::Normal) {
            throw FiscalFinalizationBlocked::because('A emissão em contingência ainda não está autorizada neste fluxo.');
        }

        if ($series->invoicing_method !== 'FESF') {
            throw FiscalFinalizationBlocked::because('A série não está autorizada para emissão por software certificado.');
        }

        if ($series->last_document_date !== null
            && $document->document_date->isBefore($series->last_document_date)) {
            throw FiscalFinalizationBlocked::because(
                'A data do documento é anterior à última factura emitida nesta série.',
            );
        }

        if ($connection->status !== AgtConnectionStatus::Verified
            || ! $connection->environment->isEnabled()
            || ! $connection->hasBasicCredentials()
            || blank($connection->software_key_reference)
            || blank($connection->taxpayer_key_reference)
            || blank($connection->software_key_fingerprint)
            || blank($connection->taxpayer_key_fingerprint)) {
            throw FiscalFinalizationBlocked::because(
                'A ligação AGT deve estar verificada, activa e com as chaves fiscais disponíveis.',
            );
        }
    }

    private function assertCalculationIntegrity(FiscalDocument $document): void
    {
        if ($document->document_type->settlesOtherDocuments()) {
            $this->assertReceiptIntegrity($document);

            return;
        }

        try {
            $calculationProfiles = [];

            foreach ($document->lines as $line) {
                $calculationProfiles[] = $this->calculationProfile($line);
            }

            $calculation = $this->calculator->calculate($calculationProfiles, $document->document_type);
            $fingerprint = hash(
                'sha256',
                $this->canonicalJson->encode($calculation->fingerprintData()),
            );
        } catch (Throwable) {
            throw FiscalFinalizationBlocked::because(
                'Os valores do rascunho não puderam ser novamente validados.',
            );
        }

        if (! hash_equals($document->calculation_sha256, $fingerprint)
            || ! $this->totalsMatch($document, $calculation)
            || ! $this->linesMatch($document, $calculation)) {
            throw FiscalFinalizationBlocked::because(
                'A verificação independente dos valores falhou. Guarde novamente o rascunho.',
            );
        }
    }

    private function assertReceiptIntegrity(FiscalDocument $document): void
    {
        $document->loadMissing('settlements');
        FiscalDocument::query()
            ->where('workspace_id', $document->workspace_id)
            ->where('legal_entity_id', $document->legal_entity_id)
            ->whereIn('id', $document->settlements->pluck('settled_document_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $document->load(['settlements.settledDocument', 'withholdings']);

        if ($document->settlements->contains(fn ($settlement): bool => $settlement->settledDocument->status !== FiscalDocumentStatus::Valid
        )) {
            throw FiscalFinalizationBlocked::because('A AGT deve validar os documentos de origem antes da emissão do recibo.');
        }

        try {
            $calculation = $this->receiptCalculator->calculate($document);
            $fingerprint = hash('sha256', $this->canonicalJson->encode($calculation));
            $allocations = $document->settlements->map(fn ($settlement): array => [
                'settled_document_id' => $settlement->settled_document_id,
                'settled_document_no' => $settlement->settled_document_no,
                'amount_minor' => $settlement->amount_minor,
                'net_amount_minor' => $settlement->net_amount_minor,
                'tax_amount_minor' => $settlement->tax_amount_minor,
                'withholding_allocations' => $settlement->withholding_allocations,
            ])->values()->all();
            $withholdings = $document->withholdings->sortBy(fn ($entry): string => $entry->withholding_type->value)
                ->map(fn ($entry): array => [
                    'type' => $entry->withholding_type->value,
                    'base_minor' => $entry->base_minor,
                    'rate_basis_points' => $entry->rate_basis_points,
                    'amount_minor' => $entry->amount_minor,
                ])->values()->all();
        } catch (Throwable) {
            throw FiscalFinalizationBlocked::because('O saldo dos documentos de origem mudou. Guarde novamente o recibo.');
        }

        if (! hash_equals($document->calculation_sha256, $fingerprint)
            || $document->lines->isNotEmpty()
            || $allocations !== $calculation['allocations']
            || $withholdings !== $calculation['withholdings']
            || $document->net_total_minor !== $calculation['net_total_minor']
            || $document->tax_payable_minor !== $calculation['tax_payable_minor']
            || $document->gross_total_minor !== $calculation['gross_total_minor']
            || $document->payment_amount_minor !== $calculation['gross_total_minor']) {
            throw FiscalFinalizationBlocked::because('A verificação dos valores do recibo falhou. Guarde novamente o rascunho.');
        }
    }

    /**
     * @return array{
     *     operation_type: string,
     *     product_code: string,
     *     product_description: string,
     *     quantity: string,
     *     unit_of_measure: string,
     *     unit_price: string,
     *     discount_percentage: string,
     *     tax_type: string,
     *     tax_code: string|null,
     *     tax_percentage: string,
     *     tax_exemption_code: string|null
     * }
     */
    private function calculationProfile(FiscalDocumentLine $line): array
    {
        if ($line->taxes->count() !== 1) {
            throw FiscalFinalizationBlocked::because('Cada linha deve possuir exactamente um tratamento fiscal.');
        }

        /** @var FiscalDocumentLineTax $tax */
        $tax = $line->taxes->first();

        return [
            'operation_type' => $line->operation_type->value,
            'product_code' => $line->product_code,
            'product_description' => $line->product_description,
            'quantity' => (string) CanonicalNumber::fromScaledInteger($line->quantity_units, $line->quantity_scale),
            'unit_of_measure' => $line->unit_of_measure,
            'unit_price' => (string) CanonicalNumber::fromMinorUnits($line->unit_price_base_minor),
            'discount_percentage' => (string) CanonicalNumber::fromBasisPoints($line->discount_rate_basis_points),
            'tax_type' => $tax->tax_type->value,
            'tax_code' => $tax->tax_code,
            'tax_percentage' => (string) CanonicalNumber::fromBasisPoints($tax->tax_rate_basis_points),
            'tax_exemption_code' => $tax->tax_exemption_code,
        ];
    }

    private function totalsMatch(
        FiscalDocument $document,
        CalculatedFiscalDocument $calculation,
    ): bool {
        return $document->settlement_total_minor === $calculation->settlementTotalMinor
            && $document->net_total_minor === $calculation->netTotalMinor
            && $document->tax_payable_minor === $calculation->taxPayableMinor
            && $document->gross_total_minor === $calculation->grossTotalMinor;
    }

    private function linesMatch(
        FiscalDocument $document,
        CalculatedFiscalDocument $calculation,
    ): bool {
        if ($document->lines->count() !== count($calculation->lines)) {
            return false;
        }

        return $document->lines->values()->every(function (
            FiscalDocumentLine $line,
            int $index,
        ) use ($calculation): bool {
            $expected = $calculation->lines[$index] ?? null;

            return $expected instanceof CalculatedFiscalLine
                && $line->line_number === $expected->lineNumber
                && $line->unit_price_micros === $expected->unitPriceMicros
                && $line->base_amount_minor === $expected->baseAmountMinor
                && $line->settlement_amount_minor === $expected->settlementAmountMinor
                && $line->net_amount_minor === $expected->netAmountMinor
                && $line->tax_amount_minor === $expected->taxAmountMinor
                && $line->gross_amount_minor === $expected->grossAmountMinor
                && $line->taxes->first()?->tax_contribution_minor === $expected->taxAmountMinor;
        });
    }

    private function fingerprint(string $keyReference, string $keyLabel): string
    {
        try {
            return $this->jwsSigner->fingerprint($keyReference);
        } catch (Throwable) {
            throw FiscalFinalizationBlocked::because(
                "A chave do {$keyLabel} não está disponível no cofre seguro.",
            );
        }
    }
}
