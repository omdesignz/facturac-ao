<?php

namespace App\Actions;

use App\Billing\SubscriptionAccess;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Calculation\CalculatedFiscalLine;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\CatalogueDocumentLines;
use App\Fiscal\Pos\FinalConsumer;
use App\Fiscal\Pos\PosSeriesResolver;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentType;
use App\FiscalOperationType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\MobileInvoice;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An invoice made on the phone, through the same draft and issue pipeline as the
 * web. The phone says who and which items; prices and taxes are read here.
 *
 * Issuing happens in one transaction with the draft, so an invoice either exists
 * completely or not at all, and the client key brings a retried request back to
 * the invoice it already made. A number is only ever spent once.
 *
 * A line is an item from the catalogue, whose price and tax are read here, or a
 * one-off line the person describes and prices, under a tax treatment the
 * editor supports. Either may carry a discount.
 *
 * @phpstan-type Lines list<array{catalogue_item_public_id?: string, quantity: string, discount_percentage?: string, description?: string, unit_price_minor?: int, tax_treatment?: string, service?: bool}>
 */
final readonly class CreateMobileInvoice
{
    public function __construct(
        private SaveFiscalDocumentDraft $saveDraft,
        private IssueFiscalDocument $issue,
        private CatalogueDocumentLines $catalogueLines,
        private PosSeriesResolver $series,
        private FiscalCalculator $calculator,
    ) {}

    /**
     * What the invoice would come to, without writing anything.
     *
     * @param  Lines  $lines
     * @return array{customer_name: string, customer_tax_identification_number: string|null, due_date: string|null, lines: list<array{description: string, quantity: string, unit_of_measure: string, tax_percentage: string, discount_percentage: string, gross_minor: int}>, net_total_minor: int, tax_total_minor: int, gross_total_minor: int, currency_code: string}
     */
    public function preview(LegalEntity $legalEntity, string $customerPublicId, array $lines): array
    {
        $customer = $this->customer($legalEntity, $customerPublicId);
        $requested = $this->lines($legalEntity, $customer, $lines);
        $calculation = $this->calculator->calculate($requested, FiscalDocumentType::Invoice);

        return [
            'customer_name' => $customer->name,
            'customer_tax_identification_number' => $customer->tax_identification_number,
            'due_date' => $this->dueDate($customer),
            'lines' => array_map(fn (CalculatedFiscalLine $line, array $source): array => [
                'description' => $line->productDescription,
                'quantity' => $source['quantity'],
                'unit_of_measure' => $line->unitOfMeasure,
                'tax_percentage' => $source['tax_percentage'],
                'discount_percentage' => $source['discount_percentage'],
                'gross_minor' => $line->grossAmountMinor,
            ], $calculation->lines, $requested),
            'net_total_minor' => $calculation->netTotalMinor,
            'tax_total_minor' => $calculation->taxPayableMinor,
            'gross_total_minor' => $calculation->grossTotalMinor,
            'currency_code' => $legalEntity->currency_code,
        ];
    }

    /**
     * The establishment an invoice from the phone belongs to.
     */
    public function establishment(LegalEntity $legalEntity): ?Establishment
    {
        return $legalEntity->establishments()
            ->where('is_active', true)
            ->orderByDesc('is_head_office')
            ->oldest('id')
            ->first();
    }

    public function canBeNumbered(LegalEntity $legalEntity, FiscalDocumentType $type = FiscalDocumentType::Invoice): bool
    {
        $establishment = $this->establishment($legalEntity);

        return $establishment instanceof Establishment
            && $this->series->exists($legalEntity, $establishment, $type);
    }

    /**
     * @param  array{client_key: string, customer_public_id: string, lines: Lines, notes?: string|null, expected_total_minor: int, issue: bool}  $invoice
     *
     * @throws FiscalFinalizationBlocked
     * @throws ValidationException
     */
    public function execute(LegalEntity $legalEntity, User $user, array $invoice): MobileInvoice
    {
        app(SubscriptionAccess::class)->require($legalEntity->workspace_id, SubscriptionFeature::Invoicing);

        try {
            return DB::transaction(fn (): MobileInvoice => $this->create($legalEntity, $user, $invoice));
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical requests raced past the check: the loser's whole
            // transaction, number included, is gone, and it answers with the winner's.
            return $this->existing($legalEntity, $invoice['client_key']) ?? throw $exception;
        }
    }

    /**
     * @param  array{client_key: string, customer_public_id: string, lines: Lines, notes?: string|null, expected_total_minor: int, issue: bool}  $invoice
     */
    private function create(LegalEntity $legalEntity, User $user, array $invoice): MobileInvoice
    {
        $existing = $this->existing($legalEntity, $invoice['client_key']);

        if ($existing instanceof MobileInvoice) {
            return $existing;
        }

        $customer = $this->customer($legalEntity, $invoice['customer_public_id']);
        $establishment = $this->establishment($legalEntity)
            ?? throw FiscalFinalizationBlocked::because('Esta empresa não tem um estabelecimento activo.');

        $draft = $this->saveDraft->execute($legalEntity, $user, [
            'document_type' => FiscalDocumentType::Invoice->value,
            'document_date' => now('Africa/Luanda')->toDateString(),
            'due_date' => $this->dueDate($customer),
            'currency_code' => $legalEntity->currency_code,
            'withholdings' => [],
            'establishment_public_id' => $establishment->public_id,
            'customer_public_id' => $customer->public_id,
            // A saved customer's own details are read by the draft.
            'customer' => FinalConsumer::profile(),
            'notes' => $invoice['notes'] ?? null,
            'references_document_public_id' => null,
            'adjustment_reason' => null,
            'lines' => $this->lines($legalEntity, $customer, $invoice['lines']),
        ]);

        // The customer is billed what the screen showed, or not at all.
        if ($draft->gross_total_minor !== $invoice['expected_total_minor']) {
            throw ValidationException::withMessages([
                'expected_total_minor' => 'O total mudou desde que a factura foi apresentada. Reveja-a antes de continuar.',
            ]);
        }

        if ($invoice['issue']) {
            $this->issue($legalEntity, $establishment, $draft, $user);
        }

        return MobileInvoice::query()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'fiscal_document_id' => $draft->id,
            'client_key' => $invoice['client_key'],
            'created_by_user_id' => $user->id,
        ]);
    }

    /**
     * The fiscal lines, in the order the phone sent them: catalogue lines read
     * from the catalogue, one-off lines from what the person typed.
     *
     * @param  Lines  $lines
     * @return list<array{operation_type: string, operation_date: string|null, product_code: string, product_description: string, quantity: string, unit_of_measure: string, unit_price: string, discount_percentage: string, tax_type: string, tax_code: string|null, tax_percentage: string, tax_exemption_code: string|null}>
     */
    private function lines(LegalEntity $legalEntity, Customer $customer, array $lines): array
    {
        $fromCatalogue = array_filter($lines, fn (array $line): bool => isset($line['catalogue_item_public_id']));
        $positions = array_keys($fromCatalogue);

        try {
            $built = $this->catalogueLines->for(
                $legalEntity,
                $legalEntity->currency_code,
                $customer,
                array_values(array_map(fn (array $line): array => [
                    'catalogue_item_public_id' => (string) $line['catalogue_item_public_id'],
                    'quantity' => $line['quantity'],
                    'discount_percentage' => $line['discount_percentage'] ?? '0',
                ], $fromCatalogue)),
            );
        } catch (ValidationException $exception) {
            // The catalogue counts only its own lines; name the line the person sent.
            $messages = [];

            foreach ($exception->errors() as $key => $errors) {
                $messages[preg_replace_callback('/^lines\.(\d+)/', fn (array $match): string => 'lines.'.$positions[(int) $match[1]], $key)] = $errors;
            }

            throw ValidationException::withMessages($messages);
        }

        $catalogue = array_combine($positions, $built) ?: [];
        $result = [];

        foreach ($lines as $index => $line) {
            $result[] = $catalogue[$index] ?? $this->oneOffLine($line);
        }

        return $result;
    }

    /**
     * A line described and priced by the person. Its code comes from the
     * description, so the same wording is one product in the SAF-T and never
     * borrows a catalogue item's code.
     *
     * @param  array{quantity: string, discount_percentage?: string, description?: string, unit_price_minor?: int, tax_treatment?: string, service?: bool}  $line
     * @return array{operation_type: string, operation_date: string|null, product_code: string, product_description: string, quantity: string, unit_of_measure: string, unit_price: string, discount_percentage: string, tax_type: string, tax_code: string|null, tax_percentage: string, tax_exemption_code: string|null}
     */
    private function oneOffLine(array $line): array
    {
        $description = mb_substr(trim((string) ($line['description'] ?? '')), 0, 200);
        $treatment = SupportedTaxTreatment::from((string) ($line['tax_treatment'] ?? ''));
        $minor = (int) ($line['unit_price_minor'] ?? 0);

        return [
            'operation_type' => ($line['service'] ?? false)
                ? FiscalOperationType::GeneralService->value
                : FiscalOperationType::GoodsTransfer->value,
            'operation_date' => null,
            'product_code' => self::oneOffCode($description),
            'product_description' => $description,
            'quantity' => $line['quantity'],
            'unit_of_measure' => 'UN',
            'unit_price' => intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT),
            'discount_percentage' => $line['discount_percentage'] ?? '0',
            'tax_type' => $treatment->taxType()->value,
            'tax_code' => $treatment->taxCode(),
            'tax_percentage' => $treatment->percentage(),
            'tax_exemption_code' => $treatment->exemptionCode(),
        ];
    }

    public static function oneOffCode(string $description): string
    {
        $normalised = mb_strtolower(preg_replace('/\s+/u', ' ', trim($description)) ?? '');

        return 'AV-'.strtoupper(substr(sha1($normalised), 0, 8));
    }

    private function issue(LegalEntity $legalEntity, Establishment $establishment, FiscalDocument $draft, User $user): void
    {
        $series = $this->series->forEstablishment($legalEntity, $establishment, FiscalDocumentType::Invoice)
            ?? throw FiscalFinalizationBlocked::because('Não há série de Factura disponível. Peça uma série à AGT em facturac.ao.');

        $this->issue->execute($draft, $user, $series->public_id, $draft->revision);
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

    public function customer(LegalEntity $legalEntity, string $publicId): Customer
    {
        $customer = Customer::query()
            ->where('public_id', $publicId)
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_active', true)
            ->first();

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages([
                'customer_public_id' => 'Este cliente já não está disponível.',
            ]);
        }

        return $customer;
    }

    private function dueDate(Customer $customer): ?string
    {
        $days = (int) $customer->payment_terms_days;

        return $days > 0 ? now('Africa/Luanda')->addDays($days)->toDateString() : null;
    }
}
