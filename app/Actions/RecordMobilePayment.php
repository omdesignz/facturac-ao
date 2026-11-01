<?php

namespace App\Actions;

use App\Analytics\AgingQuery;
use App\Analytics\ReceivablesQuery;
use App\Billing\SubscriptionAccess;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Exceptions\ReceiptEvidenceUnavailable;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\Pos\FinalConsumer;
use App\Fiscal\Pos\PosSeriesResolver;
use App\Fiscal\RequiredAudit;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\MobileInvoice;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A payment received, recorded from the phone as a Recibo through the same draft
 * and issue pipeline as the web.
 *
 * A receipt is only issued for an invoice the AGT is known to have accepted, and
 * for no more than is still owed once receipts and credit notes are counted. As
 * with invoices, draft and issue share a transaction and the client key brings a
 * retried request back to the receipt it already made.
 *
 * @phpstan-type OpenDocument array{public_id: string, document_no: string, document_date: string, due_date: string|null, outstanding_minor: int, is_settleable: bool, blocked_reason: string|null}
 * @phpstan-type Settlements list<array{document_public_id: string, amount_minor: int}>
 */
final readonly class RecordMobilePayment
{
    private const int MOST_OPEN_DOCUMENTS = 100;

    public function __construct(
        private SaveFiscalDocumentDraft $saveDraft,
        private IssueFiscalDocument $issue,
        private CreateMobileInvoice $invoices,
        private PosSeriesResolver $series,
        private ReceivablesQuery $receivables,
        private AgingQuery $aging,
    ) {}

    /**
     * What this customer still owes, oldest first.
     *
     * @return list<OpenDocument>
     */
    public function openDocuments(Customer $customer): array
    {
        $documents = $this->receivables->withBalances($this->aging->forCustomer($customer))
            ->whereIn('document_type', ReceivablesQuery::owableTypes())
            ->orderBy('document_date')
            ->orderBy('id')
            ->get()
            ->filter(fn (FiscalDocument $document): bool => $this->receivables->outstandingMinor($document) > 0)
            ->take(self::MOST_OPEN_DOCUMENTS);

        return array_values($documents->map(function (FiscalDocument $document): array {
            $isSettleable = ! $document->document_type->reducesReceivable()
                && CurrentAgtState::acceptanceEvidenceSatisfied($document);

            return [
                'public_id' => $document->public_id,
                'document_no' => (string) $document->document_no,
                'document_date' => $document->document_date->toDateString(),
                'due_date' => $document->due_date?->toDateString(),
                'outstanding_minor' => $this->receivables->outstandingMinor($document),
                'is_settleable' => $isSettleable,
                'blocked_reason' => $isSettleable ? null : 'Ainda sem validação da AGT.',
            ];
        })->all());
    }

    public function canBeNumbered(LegalEntity $legalEntity): bool
    {
        return $this->invoices->canBeNumbered($legalEntity, FiscalDocumentType::Receipt);
    }

    /**
     * @param  array{client_key: string, payment_method: string, payment_date: string, settlements: Settlements}  $payment
     *
     * @throws FiscalFinalizationBlocked
     * @throws ValidationException
     */
    public function execute(LegalEntity $legalEntity, User $user, Customer $customer, array $payment): MobileInvoice
    {
        app(SubscriptionAccess::class)->require($legalEntity->workspace_id, SubscriptionFeature::Invoicing);

        try {
            return DB::transaction(fn (): MobileInvoice => $this->create($legalEntity, $user, $customer, $payment));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($legalEntity, $payment['client_key']) ?? throw $exception;
        } catch (ReceiptEvidenceUnavailable $exception) {
            $this->recordDenial($legalEntity, $user, $payment, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array{client_key: string, payment_method: string, payment_date: string, settlements: Settlements}  $payment
     */
    private function create(LegalEntity $legalEntity, User $user, Customer $customer, array $payment): MobileInvoice
    {
        $existing = $this->existing($legalEntity, $payment['client_key']);

        if ($existing instanceof MobileInvoice) {
            return $existing;
        }

        $establishment = $this->invoices->establishment($legalEntity)
            ?? throw FiscalFinalizationBlocked::because('Esta empresa não tem um estabelecimento activo.');
        $series = $this->series->forEstablishment($legalEntity, $establishment, FiscalDocumentType::Receipt)
            ?? throw FiscalFinalizationBlocked::because('Não há série de Recibo disponível. Peça uma série à AGT em facturac.ao.');

        $this->assertStillOwed($legalEntity, $customer, $payment['settlements']);

        $today = now('Africa/Luanda')->toDateString();
        $draft = $this->saveDraft->execute($legalEntity, $user, [
            'document_type' => FiscalDocumentType::Receipt->value,
            'document_date' => $today,
            'due_date' => null,
            'currency_code' => $legalEntity->currency_code,
            'withholdings' => [],
            'establishment_public_id' => $establishment->public_id,
            'customer_public_id' => $customer->public_id,
            // A saved customer's own details are read by the draft.
            'customer' => FinalConsumer::profile(),
            'notes' => null,
            'references_document_public_id' => null,
            'adjustment_reason' => null,
            'payment_method' => $payment['payment_method'],
            'payment_amount_minor' => array_sum(array_column($payment['settlements'], 'amount_minor')),
            'payment_date' => $payment['payment_date'],
            'settlements' => $payment['settlements'],
            'lines' => [],
        ]);

        $this->issue->execute($draft, $user, $series->public_id, $draft->revision);

        return MobileInvoice::query()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'fiscal_document_id' => $draft->id,
            'client_key' => $payment['client_key'],
            'created_by_user_id' => $user->id,
        ]);
    }

    /**
     * Holds the invoices so a second receipt for the same money waits, then
     * finds the balance changed, rather than racing this one.
     *
     * @param  Settlements  $settlements
     */
    private function assertStillOwed(LegalEntity $legalEntity, Customer $customer, array $settlements): void
    {
        FiscalDocument::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn('public_id', array_column($settlements, 'document_public_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $open = collect($this->openDocuments($customer))->keyBy('public_id');

        foreach ($settlements as $settlement) {
            $document = $open->get($settlement['document_public_id']);

            if ($document === null || $settlement['amount_minor'] > $document['outstanding_minor']) {
                throw ValidationException::withMessages([
                    'settlements' => 'O valor em dívida mudou. Reveja o pagamento antes de continuar.',
                ]);
            }

            if (! $document['is_settleable']) {
                throw new ReceiptEvidenceUnavailable('unproven');
            }
        }
    }

    /**
     * The issuing step keeps this record too, but its copy leaves with the
     * rolled-back transaction, and a refused receipt must stay on the record.
     *
     * @param  array{client_key: string, payment_method: string, payment_date: string, settlements: Settlements}  $payment
     */
    private function recordDenial(LegalEntity $legalEntity, User $user, array $payment, ReceiptEvidenceUnavailable $exception): void
    {
        RequiredAudit::record(fn () => activity('fiscal-document')->causedBy($user)->performedOn($legalEntity)
            ->event('receipt-evidence-denied')->withProperties([
                'actor_kind' => 'human', 'workspace_id' => $legalEntity->workspace_id, 'legal_entity_id' => $legalEntity->id,
                'environment' => $exception->environment,
                'settled_document_public_ids' => array_column($payment['settlements'], 'document_public_id'),
                'capability' => 'existing.receipt.issue', 'contract_version' => 'SA1', 'reason' => 'source_agt_evidence_unproven',
                'operation_id' => (string) Str::uuid(),
                'request_id' => Context::get('request_id') ?? (string) Str::uuid(),
            ])->log('Receipt issuance denied: source AGT evidence is unproven'));
    }

    private function existing(LegalEntity $legalEntity, string $clientKey): ?MobileInvoice
    {
        $existing = MobileInvoice::query()
            ->with('fiscalDocument')
            ->where('legal_entity_id', $legalEntity->id)
            ->where('client_key', $clientKey)
            ->first();

        if ($existing instanceof MobileInvoice) {
            $existing->replayed = true;
        }

        return $existing;
    }
}
