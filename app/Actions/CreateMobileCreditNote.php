<?php

namespace App\Actions;

use App\Billing\SubscriptionAccess;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Calculation\FiscalCalculator;
use App\Fiscal\Documents\CurrentAgtState;
use App\Fiscal\Pos\PosSeriesResolver;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLineTax;
use App\Models\LegalEntity;
use App\Models\MobileInvoice;
use App\Models\User;
use App\SubscriptionFeature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A credit note made on the phone, against one invoice the AGT has validated.
 *
 * The phone only chooses which lines and how much of each to credit. Everything
 * else is copied from the invoice here, so a note can never carry a price, tax
 * or description the invoice did not have, and no line can be credited past
 * what earlier notes have left of it. The rest goes through the web's own draft
 * and issue steps, which check the reference again before a number is spent.
 *
 * What the phone will not correct is left to the web on purpose: documents the
 * AGT has not validated or has returned, foreign currency and withholdings.
 *
 * @phpstan-type Lines list<array{line_number: int, quantity: string}>
 * @phpstan-type DraftLine array{reference_line_number: int, operation_type: string, operation_date: string|null, product_code: string, product_description: string, quantity: string, unit_of_measure: string, unit_price: string, discount_percentage: string, tax_type: string, tax_code: string|null, tax_percentage: string, tax_exemption_code: string|null}
 * @phpstan-type CreditableLine array{line_number: int, description: string, unit_of_measure: string, quantity: string, credited_quantity: string, creditable_quantity: string, unit_gross_minor: int}
 */
final readonly class CreateMobileCreditNote
{
    /** Quantities are compared at the finest scale a line may have. */
    private const int SCALE = 4;

    public const array CORRECTABLE = [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt];

    public function __construct(
        private SaveFiscalDocumentDraft $saveDraft,
        private IssueFiscalDocument $issue,
        private PosSeriesResolver $series,
        private FiscalCalculator $calculator,
    ) {}

    /**
     * Why the phone cannot correct this document, or null when it can.
     */
    public function refusal(FiscalDocument $source): ?string
    {
        $legalEntity = $source->legalEntity;

        return match (true) {
            ! in_array($source->document_type, self::CORRECTABLE, true) => 'No telemóvel corrigem-se facturas e facturas-recibo. Use facturac.ao para este documento.',
            $source->status === FiscalDocumentStatus::Invalid => 'A AGT devolveu este documento. Trate-o em facturac.ao.',
            ! CurrentAgtState::acceptanceEvidenceSatisfied($source) => 'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.',
            $source->currency_code !== $legalEntity->currency_code || $source->withholdings()->exists() => 'Este documento tem moeda estrangeira ou retenções. Corrija-o em facturac.ao.',
            array_sum(array_map(fn (array $line): int => $this->scaled($line['creditable_quantity']), $this->lines($source))) === 0 => 'Este documento já foi creditado por inteiro.',
            default => null,
        };
    }

    public function canBeNumbered(FiscalDocument $source): bool
    {
        $establishment = $this->establishment($source);

        return $establishment instanceof Establishment
            && $this->series->exists($source->legalEntity, $establishment, FiscalDocumentType::CreditNote);
    }

    /**
     * Each line of the invoice with how much of it is still there to credit.
     *
     * @return list<CreditableLine>
     */
    public function lines(FiscalDocument $source): array
    {
        $credited = $this->creditedByLine($source);

        return array_values($source->lines()->with('taxes')->orderBy('line_number')->get()
            ->map(function (FiscalDocumentLine $line) use ($credited): array {
                $quantity = $this->scaled($this->quantity($line));
                $done = min($quantity, $credited[$line->line_number] ?? 0);

                return [
                    'line_number' => $line->line_number,
                    'description' => $line->product_description,
                    'unit_of_measure' => $line->unit_of_measure,
                    'quantity' => $this->quantity($line),
                    'credited_quantity' => $this->unscaled($done),
                    'creditable_quantity' => $this->unscaled($quantity - $done),
                    'unit_gross_minor' => $this->calculator->calculate(
                        [$this->draftLine($line, '1')],
                        FiscalDocumentType::CreditNote,
                    )->grossTotalMinor,
                ];
            })
            ->all());
    }

    /**
     * What the note would come to, without writing anything.
     *
     * @param  Lines  $lines
     * @return array{lines: list<array{line_number: int, description: string, quantity: string, gross_minor: int}>, net_total_minor: int, tax_total_minor: int, gross_total_minor: int, currency_code: string}
     */
    public function preview(FiscalDocument $source, array $lines): array
    {
        $draftLines = $this->requestedLines($source, $lines);
        $calculation = $this->calculator->calculate($draftLines, FiscalDocumentType::CreditNote);

        return [
            'lines' => array_map(fn ($calculated, array $line): array => [
                'line_number' => (int) $line['reference_line_number'],
                'description' => $calculated->productDescription,
                'quantity' => $line['quantity'],
                'gross_minor' => $calculated->grossAmountMinor,
            ], $calculation->lines, $draftLines),
            'net_total_minor' => $calculation->netTotalMinor,
            'tax_total_minor' => $calculation->taxPayableMinor,
            'gross_total_minor' => $calculation->grossTotalMinor,
            'currency_code' => $source->currency_code,
        ];
    }

    /**
     * @param  array{client_key: string, reason: string, lines: Lines, expected_total_minor: int}  $note
     *
     * @throws FiscalFinalizationBlocked
     * @throws ValidationException
     */
    public function execute(LegalEntity $legalEntity, User $user, FiscalDocument $source, array $note): MobileInvoice
    {
        app(SubscriptionAccess::class)->require($legalEntity->workspace_id, SubscriptionFeature::Invoicing);

        try {
            return DB::transaction(fn (): MobileInvoice => $this->create($legalEntity, $user, $source, $note));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->existing($legalEntity, $note['client_key']) ?? throw $exception;
        }
    }

    /**
     * @param  array{client_key: string, reason: string, lines: Lines, expected_total_minor: int}  $note
     */
    private function create(LegalEntity $legalEntity, User $user, FiscalDocument $source, array $note): MobileInvoice
    {
        $existing = $this->existing($legalEntity, $note['client_key']);

        if ($existing instanceof MobileInvoice) {
            return $existing;
        }

        // Two notes against one invoice wait for each other, so neither can
        // credit what the other has just taken.
        $source = FiscalDocument::query()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();

        $refusal = $this->refusal($source);

        if ($refusal !== null) {
            throw FiscalFinalizationBlocked::because($refusal);
        }

        $establishment = $this->establishment($source)
            ?? throw FiscalFinalizationBlocked::because('O estabelecimento desta factura já não está activo.');
        $series = $this->series->forEstablishment($legalEntity, $establishment, FiscalDocumentType::CreditNote)
            ?? throw FiscalFinalizationBlocked::because('Não há série de Nota de Crédito disponível. Peça uma série à AGT em facturac.ao.');
        $customer = $this->customer($source);

        $draft = $this->saveDraft->execute($legalEntity, $user, [
            'document_type' => FiscalDocumentType::CreditNote->value,
            'document_date' => now('Africa/Luanda')->toDateString(),
            'due_date' => null,
            'currency_code' => $source->currency_code,
            'withholdings' => [],
            'establishment_public_id' => $establishment->public_id,
            'customer_public_id' => $customer?->public_id,
            'customer' => [
                'name' => $source->customer_name,
                'tax_identification_number' => $source->customer_tax_identification_number,
                'country_code' => $source->customer_country_code,
                'address_line' => $source->customer_address,
            ],
            'notes' => null,
            'references_document_public_id' => $source->public_id,
            'adjustment_reason' => $note['reason'],
            'lines' => $this->requestedLines($source, $note['lines']),
        ]);

        if ($draft->gross_total_minor !== $note['expected_total_minor']) {
            throw ValidationException::withMessages([
                'expected_total_minor' => 'O total mudou desde que a nota foi apresentada. Reveja-a antes de continuar.',
            ]);
        }

        $this->issue->execute($draft, $user, $series->public_id, $draft->revision);

        return MobileInvoice::query()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'fiscal_document_id' => $draft->id,
            'client_key' => $note['client_key'],
            'created_by_user_id' => $user->id,
        ]);
    }

    /**
     * The invoice's own lines, at the quantities asked for, each checked against
     * what is left of it.
     *
     * @param  Lines  $requested
     * @return list<DraftLine>
     */
    private function requestedLines(FiscalDocument $source, array $requested): array
    {
        $creditable = collect($this->lines($source))->keyBy('line_number');
        $sourceLines = $source->lines()->with('taxes')->get()->keyBy('line_number');
        $lines = [];

        foreach ($requested as $index => $line) {
            $available = $creditable->get($line['line_number']);

            if ($available === null) {
                throw ValidationException::withMessages([
                    "lines.{$index}.line_number" => 'Esta linha não existe na factura.',
                ]);
            }

            if ($this->scaled($line['quantity']) > $this->scaled($available['creditable_quantity'])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.quantity" => "Só pode creditar até {$available['creditable_quantity']} {$available['unit_of_measure']} de {$available['description']}.",
                ]);
            }

            $lines[] = $this->draftLine($sourceLines[$line['line_number']], $line['quantity']);
        }

        return $lines;
    }

    /**
     * @return DraftLine
     */
    private function draftLine(FiscalDocumentLine $line, string $quantity): array
    {
        /** @var FiscalDocumentLineTax $tax */
        $tax = $line->taxes->firstOrFail();

        return [
            'reference_line_number' => $line->line_number,
            'operation_type' => $line->operation_type->value,
            'operation_date' => $line->operation_date?->toDateString(),
            'product_code' => $line->product_code,
            'product_description' => $line->product_description,
            'quantity' => $quantity,
            'unit_of_measure' => $line->unit_of_measure,
            'unit_price' => CanonicalNumber::fromMinorUnits($line->unit_price_base_minor)->value(),
            'discount_percentage' => CanonicalNumber::fromBasisPoints($line->discount_rate_basis_points)->value(),
            'tax_type' => $tax->tax_type->value,
            'tax_code' => $tax->tax_code,
            'tax_percentage' => CanonicalNumber::fromBasisPoints($tax->tax_rate_basis_points)->value(),
            'tax_exemption_code' => $tax->tax_exemption_code,
        ];
    }

    /**
     * How much of each line issued credit notes have already taken, at SCALE.
     * A note the AGT returned still counts: whether one may be replaced is a
     * question the AGT has not answered yet.
     *
     * @return array<int, int>
     */
    private function creditedByLine(FiscalDocument $source): array
    {
        $credited = [];

        FiscalDocumentLine::query()
            ->whereHas('fiscalDocument', fn ($query) => $query
                ->where('references_document_id', $source->id)
                ->where('legal_entity_id', $source->legal_entity_id)
                ->where('document_type', FiscalDocumentType::CreditNote)
                ->where('status', '!=', FiscalDocumentStatus::Draft))
            ->whereNotNull('reference_line_number')
            ->get(['reference_line_number', 'quantity_units', 'quantity_scale'])
            ->each(function (FiscalDocumentLine $line) use (&$credited): void {
                $credited[(int) $line->reference_line_number] = ($credited[(int) $line->reference_line_number] ?? 0)
                    + $this->scaled($this->quantity($line));
            });

        return $credited;
    }

    private function establishment(FiscalDocument $source): ?Establishment
    {
        return Establishment::query()
            ->whereKey($source->establishment_id)
            ->where('legal_entity_id', $source->legal_entity_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * The invoice's customer, while they are still an active one; otherwise the
     * note carries the details the invoice was issued with.
     */
    private function customer(FiscalDocument $source): ?Customer
    {
        return $source->customer_id === null ? null : Customer::query()
            ->whereKey($source->customer_id)
            ->where('legal_entity_id', $source->legal_entity_id)
            ->where('is_active', true)
            ->first();
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

    private function quantity(FiscalDocumentLine $line): string
    {
        return CanonicalNumber::fromScaledInteger($line->quantity_units, $line->quantity_scale)->value();
    }

    private function scaled(string $quantity): int
    {
        [$whole, $fraction] = array_pad(explode('.', $quantity, 2), 2, '');

        return (int) $whole * 10 ** self::SCALE + (int) str_pad(substr($fraction, 0, self::SCALE), self::SCALE, '0');
    }

    private function unscaled(int $quantity): string
    {
        return CanonicalNumber::fromScaledInteger($quantity, self::SCALE)->value();
    }
}
