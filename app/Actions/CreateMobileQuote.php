<?php

namespace App\Actions;

use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Documents\CatalogueDocumentLines;
use App\Fiscal\SupportedTaxTreatment;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * A quote made on the phone, through the web's own save action. The phone says who
 * and which items; descriptions, prices and taxes come from the catalogue.
 *
 * @phpstan-type Lines list<array{catalogue_item_public_id: string, quantity: string}>
 */
final readonly class CreateMobileQuote
{
    /** The most a quote's unit price may be, as on the web's form, in minor units. */
    private const int MOST_UNIT_PRICE_MINOR = 100_000_000_000;

    public function __construct(
        private SaveQuote $saveQuote,
        private CreateMobileInvoice $invoices,
        private CatalogueDocumentLines $catalogueLines,
    ) {}

    /**
     * What the quote would come to, without writing anything.
     *
     * @param  Lines  $lines
     * @return array<string, mixed>
     */
    public function preview(LegalEntity $legalEntity, string $customerPublicId, array $lines): array
    {
        $customer = $this->invoices->customer($legalEntity, $customerPublicId);
        $this->quoteLines($legalEntity, $customer, $lines);

        return [...$this->calculated(fn (): array => $this->invoices->preview($legalEntity, $customerPublicId, $lines)), 'due_date' => null];
    }

    /**
     * @param  array{client_key: string, customer_public_id: string, lines: Lines, valid_for_days: int, expected_total_minor: int}  $quote
     *
     * @throws FiscalFinalizationBlocked
     * @throws ValidationException
     */
    public function execute(LegalEntity $legalEntity, User $user, array $quote): Quote
    {
        try {
            return DB::transaction(fn (): Quote => $this->existing($legalEntity, $quote['client_key'])
                ?? $this->calculated(fn (): Quote => $this->create($legalEntity, $user, $quote)));
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical requests raced past the check: the loser's whole
            // transaction, reference included, is gone, and it answers with the winner's.
            return $this->existing($legalEntity, $quote['client_key']) ?? throw $exception;
        }
    }

    /**
     * @param  array{client_key: string, customer_public_id: string, lines: Lines, valid_for_days: int, expected_total_minor: int}  $quote
     */
    private function create(LegalEntity $legalEntity, User $user, array $quote): Quote
    {
        $customer = $this->invoices->customer($legalEntity, $quote['customer_public_id']);
        $establishment = $this->invoices->establishment($legalEntity)
            ?? throw FiscalFinalizationBlocked::because('Esta empresa não tem um estabelecimento activo.');
        $today = now('Africa/Luanda');

        $saved = $this->saveQuote->execute($legalEntity, $user, [
            'customer_public_id' => $customer->public_id,
            'customer' => [
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'country_code' => $customer->country_code,
                'address_line' => $customer->address_line,
            ],
            'establishment_public_id' => $establishment->public_id,
            'issue_date' => $today->toDateString(),
            'valid_until' => $today->copy()->addDays($quote['valid_for_days'])->toDateString(),
            'notes' => null,
            'lines' => $this->quoteLines($legalEntity, $customer, $quote['lines']),
        ]);

        // The customer is quoted what the screen showed, or not at all.
        if ($saved->gross_total_minor !== $quote['expected_total_minor']) {
            throw ValidationException::withMessages([
                'expected_total_minor' => 'O total mudou desde que o orçamento foi apresentado. Reveja-o antes de continuar.',
            ]);
        }

        $saved->forceFill(['client_key' => $quote['client_key']])->save();

        return $saved;
    }

    /**
     * The catalogue's lines in the shape the save action reads, held to the web form's rules.
     *
     * @param  Lines  $lines
     * @return list<array{product_code: string, operation_type: string, product_description: string, unit_of_measure: string, quantity_units: int, quantity_scale: int, unit_price_minor: int, discount_rate_basis_points: int, tax_type: string, tax_code: string|null, tax_percentage: string, tax_exemption_code: string|null}>
     */
    private function quoteLines(LegalEntity $legalEntity, Customer $customer, array $lines): array
    {
        return array_map(function (array $line): array {
            $priceMinor = $this->scaled($line['unit_price'], 2);
            $treatment = SupportedTaxTreatment::fromLegacyComponents(
                $line['tax_type'],
                $line['tax_code'],
                $line['tax_percentage'],
                $line['tax_exemption_code'],
            )?->profile();

            if ($priceMinor > self::MOST_UNIT_PRICE_MINOR) {
                throw ValidationException::withMessages([
                    'lines' => "O preço de {$line['product_description']} passa do limite de um orçamento.",
                ]);
            }

            if ($treatment === null) {
                throw ValidationException::withMessages([
                    'lines' => "O tratamento fiscal de {$line['product_description']} não é suportado.",
                ]);
            }

            return [
                'product_code' => $line['product_code'],
                'operation_type' => $line['operation_type'],
                'product_description' => $line['product_description'],
                'unit_of_measure' => $line['unit_of_measure'],
                'quantity_units' => $this->scaled($line['quantity'], 4),
                'quantity_scale' => 4,
                'unit_price_minor' => $priceMinor,
                'discount_rate_basis_points' => 0,
                'tax_type' => $treatment['type'],
                'tax_code' => $treatment['code'],
                'tax_percentage' => $treatment['percentage'],
                'tax_exemption_code' => $treatment['exemption_code'],
            ];
        }, $this->catalogueLines->for($legalEntity, $legalEntity->currency_code, $customer, $lines));
    }

    /**
     * A sum too large for the fiscal calculator is the person's to fix, not a crash.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $calculate
     * @return TResult
     */
    private function calculated(callable $calculate): mixed
    {
        try {
            return $calculate();
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages([
                'lines' => 'O total deste orçamento é demasiado grande. Reduza as quantidades.',
            ]);
        }
    }

    private function existing(LegalEntity $legalEntity, string $clientKey): ?Quote
    {
        return Quote::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('client_key', $clientKey)
            ->first();
    }

    /** Parses a decimal string into scaled integers without touching a float. */
    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }
}
