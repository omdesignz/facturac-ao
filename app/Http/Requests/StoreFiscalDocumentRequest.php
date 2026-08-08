<?php

namespace App\Http\Requests;

use App\FiscalDocumentType;
use App\FiscalOperationType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use App\Models\LegalEntity;
use App\Models\Workspace;
use App\PaymentMethod;
use App\WithholdingType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFiscalDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', FiscalDocument::class) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'document_type' => [
                'required',
                Rule::in(array_map(
                    fn (FiscalDocumentType $type): string => $type->value,
                    FiscalDocumentType::issuable(),
                )),
            ],
            // An adjustment must name the issued document it corrects, and the
            // AGT will not accept it without a reason.
            'references_document_public_id' => [
                Rule::requiredIf(fn (): bool => $this->isAdjustment()),
                'nullable',
                'string',
                Rule::exists('fiscal_documents', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0)
                        ->whereNotNull('document_no'),
                ),
            ],
            'payment_method' => [
                Rule::requiredIf(fn (): bool => $this->isReceipt()),
                'nullable',
                Rule::enum(PaymentMethod::class),
            ],
            'payment_date' => [
                Rule::requiredIf(fn (): bool => $this->isReceipt()),
                'nullable',
                'date_format:Y-m-d',
                'before_or_equal:today',
            ],
            // A standalone receipt names the invoices it pays off.
            'settlements' => $this->settlesOtherDocuments()
                ? ['required', 'array', 'min:1', 'max:100']
                : ['nullable', 'array', 'max:0'],
            'settlements.*.document_public_id' => [
                'required',
                'string',
                'distinct',
                Rule::exists('fiscal_documents', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0)
                        ->whereNotNull('document_no'),
                ),
            ],
            'settlements.*.amount' => [
                'required',
                'string',
                'max:18',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/',
                'not_in:0,0.0,0.00',
            ],
            'adjustment_reason' => [
                Rule::requiredIf(fn (): bool => $this->isAdjustment()),
                'nullable',
                'string',
                'min:5',
                'max:200',
            ],
            'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'],
            'currency_code' => ['required', Rule::in((array) config('fiscal.currencies', ['AOA']))],
            /*
             * Kwanzas per one unit of the document's currency. Required as soon
             * as the document leaves the kwanza, because the AGT compares the
             * declared value against the converted one and a missing rate is
             * indistinguishable from a rate of one.
             */
            'exchange_rate' => [
                Rule::requiredIf(fn (): bool => $this->isForeignCurrency()),
                'nullable',
                'string',
                'max:20',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,6})?\z/',
                'not_in:0,0.0,0.00,0.000,0.0000,0.00000,0.000000',
            ],
            // Withholding is the buyer's affair, so at most one entry per tax.
            'withholdings' => ['nullable', 'array', 'max:3'],
            'withholdings.*' => ['required', 'array:type,rate_percentage'],
            'withholdings.*.type' => ['required', 'distinct', Rule::enum(WithholdingType::class)],
            'withholdings.*.rate_percentage' => [
                'required',
                'string',
                'max:6',
                'regex:/\A(?:100(?:\.0{1,2})?|(?:0|[1-9]\d?)(?:\.\d{1,2})?)\z/',
                'not_in:0,0.0,0.00',
            ],
            'establishment_public_id' => [
                'required',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0)
                        ->where('is_active', true),
                ),
            ],
            'customer_public_id' => [
                'nullable',
                'string',
                Rule::exists('customers', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0)
                        ->where('is_active', true),
                ),
            ],
            'customer' => ['required', 'array:name,tax_identification_number,country_code,address_line'],
            'customer.name' => ['required', 'string', 'min:2', 'max:255'],
            'customer.tax_identification_number' => [
                'required',
                'string',
                'regex:/\A[A-Z0-9]{9,32}\z/',
            ],
            'customer.country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'customer.address_line' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => [
                Rule::requiredIf(fn (): bool => $this->documentType()?->requiresLines() ?? true),
                'array',
                'max:500',
            ],
            'lines.*' => [
                'required',
                'array:operation_type,product_code,product_description,quantity,unit_of_measure,unit_price,discount_percentage,tax',
            ],
            'lines.*.operation_type' => ['required', Rule::enum(FiscalOperationType::class)],
            'lines.*.product_code' => ['required', 'string', 'max:60'],
            'lines.*.product_description' => ['required', 'string', 'min:2', 'max:255'],
            'lines.*.quantity' => ['required', 'string', 'max:20', 'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/', 'not_in:0,0.0,0.00,0.000,0.0000'],
            'lines.*.unit_of_measure' => ['required', 'string', 'max:32'],
            'lines.*.unit_price' => ['required', 'string', 'max:18', 'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/'],
            'lines.*.discount_percentage' => ['required', 'string', 'max:6', 'regex:/\A(?:100(?:\.0{1,2})?|(?:0|[1-9]\d?)(?:\.\d{1,2})?)\z/'],
            'lines.*.tax' => ['required', 'array:type,code,percentage,exemption_code'],
            'lines.*.tax.type' => ['required', Rule::in(['IVA', 'NS'])],
            'lines.*.tax.code' => ['nullable', Rule::in(['NOR', 'ISE'])],
            'lines.*.tax.percentage' => ['required', 'string', 'max:5', 'regex:/\A(?:0|[1-9]\d?)(?:\.\d{1,2})?\z/'],
            'lines.*.tax.exemption_code' => ['nullable', Rule::in(['M00', 'M02', 'M04'])],
        ];
    }

    /**
     * Credit and debit notes carry extra obligations the other types do not.
     */
    public function isAdjustment(): bool
    {
        return $this->documentType()?->isAdjustment() ?? false;
    }

    public function isReceipt(): bool
    {
        return $this->documentType()?->isReceipt() ?? false;
    }

    /** Whether this document is written in something other than kwanzas. */
    public function isForeignCurrency(): bool
    {
        return $this->input('currency_code') !== 'AOA'
            && is_string($this->input('currency_code'));
    }

    public function settlesOtherDocuments(): bool
    {
        return $this->documentType()?->settlesOtherDocuments() ?? false;
    }

    private function documentType(): ?FiscalDocumentType
    {
        return FiscalDocumentType::tryFrom((string) $this->input('document_type'));
    }

    /**
     * @return array{
     *     document_type: string,
     *     document_date: string,
     *     due_date: string|null,
     *     currency_code: string,
     *     exchange_rate_micro: int,
     *     withholdings: list<array{type: string, rate_basis_points: int}>,
     *     establishment_public_id: string,
     *     customer_public_id: string|null,
     *     customer: array{name: string, tax_identification_number: string, country_code: string, address_line: string|null},
     *     notes: string|null,
     *     references_document_public_id: string|null,
     *     adjustment_reason: string|null,
     *     payment_method: string|null,
     *     payment_amount_minor: int|null,
     *     payment_date: string|null,
     *     settlements: list<array{document_public_id: string, amount_minor: int}>,
     *     lines: list<array{
     *         operation_type: string,
     *         product_code: string,
     *         product_description: string,
     *         quantity: string,
     *         unit_of_measure: string,
     *         unit_price: string,
     *         discount_percentage: string,
     *         tax_type: string,
     *         tax_code: string|null,
     *         tax_percentage: string,
     *         tax_exemption_code: string|null
     *     }>
     * }
     */
    public function documentProfile(): array
    {
        $validated = $this->validated();
        $rawLines = is_array($validated['lines'] ?? null) ? $validated['lines'] : [];
        $lines = [];

        foreach ($rawLines as $rawLine) {
            if (! is_array($rawLine) || ! is_array($rawLine['tax'] ?? null)) {
                continue;
            }

            $lines[] = [
                'operation_type' => (string) ($rawLine['operation_type'] ?? ''),
                'product_code' => (string) ($rawLine['product_code'] ?? ''),
                'product_description' => (string) ($rawLine['product_description'] ?? ''),
                'quantity' => (string) ($rawLine['quantity'] ?? ''),
                'unit_of_measure' => (string) ($rawLine['unit_of_measure'] ?? ''),
                'unit_price' => (string) ($rawLine['unit_price'] ?? ''),
                'discount_percentage' => (string) ($rawLine['discount_percentage'] ?? ''),
                'tax_type' => (string) ($rawLine['tax']['type'] ?? ''),
                'tax_code' => $this->nullableString($rawLine['tax']['code'] ?? null),
                'tax_percentage' => (string) ($rawLine['tax']['percentage'] ?? ''),
                'tax_exemption_code' => $this->nullableString($rawLine['tax']['exemption_code'] ?? null),
            ];
        }

        return [
            'document_type' => (string) $validated['document_type'],
            'document_date' => (string) $validated['document_date'],
            'due_date' => $this->nullableString($validated['due_date'] ?? null),
            'currency_code' => (string) $validated['currency_code'],
            'exchange_rate_micro' => $this->exchangeRateMicro(),
            'withholdings' => $this->withholdingProfile(),
            'establishment_public_id' => (string) $validated['establishment_public_id'],
            'customer_public_id' => $this->nullableString($validated['customer_public_id'] ?? null),
            'customer' => [
                'name' => (string) $validated['customer']['name'],
                'tax_identification_number' => (string) $validated['customer']['tax_identification_number'],
                'country_code' => (string) $validated['customer']['country_code'],
                'address_line' => $this->nullableString($validated['customer']['address_line'] ?? null),
            ],
            'notes' => $this->nullableString($validated['notes'] ?? null),
            'references_document_public_id' => $this->nullableString(
                $validated['references_document_public_id'] ?? null,
            ),
            'adjustment_reason' => $this->nullableString($validated['adjustment_reason'] ?? null),
            'payment_method' => $this->nullableString($validated['payment_method'] ?? null),
            'payment_amount_minor' => $this->paymentAmountMinor(),
            'payment_date' => $this->nullableString($validated['payment_date'] ?? null),
            'settlements' => $this->settlementProfile(),
            'lines' => $lines,
        ];
    }

    /**
     * A receipt may not collect more than an invoice still owes. The balance is
     * the gross total less whatever earlier receipts already settled — and less
     * this receipt's own previous revision, which is about to be replaced.
     */
    private function validateSettlementAmounts(Validator $validator): void
    {
        if (! $this->settlesOtherDocuments()) {
            return;
        }

        $current = $this->route('fiscalDocument');
        $currentId = $current instanceof FiscalDocument ? $current->id : null;

        foreach ($this->settlementProfile() as $index => $settlement) {
            $invoice = FiscalDocument::query()
                ->where('public_id', $settlement['document_public_id'])
                ->first();

            if (! $invoice instanceof FiscalDocument) {
                continue;
            }

            $alreadySettled = (int) FiscalDocumentSettlement::query()
                ->where('settled_document_id', $invoice->id)
                ->when($currentId !== null, fn ($query) => $query->where('fiscal_document_id', '!=', $currentId))
                ->sum('amount_minor');
            $outstanding = $invoice->gross_total_minor - $alreadySettled;

            if ($settlement['amount_minor'] > $outstanding) {
                $validator->errors()->add(
                    "settlements.{$index}.amount",
                    sprintf(
                        'A factura %s tem apenas %s por liquidar.',
                        $invoice->document_no,
                        number_format($outstanding / 100, 2, ',', ' '),
                    ),
                );
            }
        }
    }

    /**
     * A receipt is paid for exactly what it settles; FR settles its own gross
     * total, which the action computes from the lines.
     */
    private function paymentAmountMinor(): ?int
    {
        if (! $this->isReceipt()) {
            return null;
        }

        $total = 0;

        foreach ($this->settlementProfile() as $settlement) {
            $total += $settlement['amount_minor'];
        }

        return $total > 0 ? $total : null;
    }

    /**
     * The rate as an integer scaled by a million.
     *
     * A kwanza document converts through exactly one. Storing that rather than
     * leaving the column null means every document answers the question the
     * same way, and the audit file never has to special-case the common one.
     */
    private function exchangeRateMicro(): int
    {
        $rate = $this->validated('exchange_rate');

        /*
         * A kwanza document is one to one whatever was submitted. The form
         * hides the field once the currency goes back to AOA, so a rate
         * arriving with one is a leftover value rather than a statement — and
         * storing it would silently multiply every total in the audit file.
         */
        if (! $this->isForeignCurrency() || ! is_string($rate) || $rate === '') {
            return 1_000_000;
        }

        $parts = explode('.', $rate);
        $fraction = str_pad(substr($parts[1] ?? '', 0, 6), 6, '0');

        return (int) $parts[0] * 1_000_000 + (int) $fraction;
    }

    /**
     * @return list<array{type: string, rate_basis_points: int}>
     */
    private function withholdingProfile(): array
    {
        $withholdings = $this->validated('withholdings');

        if (! is_array($withholdings)) {
            return [];
        }

        $resolved = [];

        foreach ($withholdings as $withholding) {
            if (! is_array($withholding)) {
                continue;
            }

            $resolved[] = [
                'type' => (string) ($withholding['type'] ?? ''),
                'rate_basis_points' => $this->toMinorUnits(
                    (string) ($withholding['rate_percentage'] ?? '0'),
                ),
            ];
        }

        return $resolved;
    }

    /**
     * @return list<array{document_public_id: string, amount_minor: int}>
     */
    private function settlementProfile(): array
    {
        $settlements = $this->validated('settlements');

        if (! is_array($settlements)) {
            return [];
        }

        $resolved = [];

        foreach ($settlements as $settlement) {
            if (! is_array($settlement)) {
                continue;
            }

            $resolved[] = [
                'document_public_id' => (string) ($settlement['document_public_id'] ?? ''),
                'amount_minor' => $this->toMinorUnits((string) ($settlement['amount'] ?? '0')),
            ];
        }

        return $resolved;
    }

    private function toMinorUnits(string $amount): int
    {
        $parts = explode('.', $amount);
        $cents = str_pad($parts[1] ?? '', 2, '0');

        return (int) $parts[0] * 100 + (int) $cents;
    }

    /** @return array<int, callable|ValidationRule> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateSettlementAmounts($validator);
            },
            function (Validator $validator): void {
                $lines = $this->input('lines', []);

                if (! is_array($lines)) {
                    return;
                }

                foreach ($lines as $index => $line) {
                    if (! is_array($line) || ! is_array($line['tax'] ?? null)) {
                        continue;
                    }

                    $this->validateTaxTreatment($validator, $index, $line['tax']);
                }
            },
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }

    protected function prepareForValidation(): void
    {
        $customer = $this->input('customer');
        $lines = $this->input('lines');

        if (is_array($customer)) {
            $customer['name'] = $this->trimString($customer['name'] ?? null);
            $customer['tax_identification_number'] = $this->normaliseCode($customer['tax_identification_number'] ?? null);
            $customer['country_code'] = $this->normaliseCode($customer['country_code'] ?? null);
            $customer['address_line'] = $this->trimString($customer['address_line'] ?? null);
        }

        if (is_array($lines)) {
            $lines = array_map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['product_code', 'product_description', 'unit_of_measure'] as $key) {
                    $line[$key] = $this->trimString($line[$key] ?? null);
                }

                foreach (['quantity', 'unit_price', 'discount_percentage'] as $key) {
                    $line[$key] = $this->normaliseDecimal($line[$key] ?? null);
                }

                if (is_array($line['tax'] ?? null)) {
                    foreach (['type', 'code', 'exemption_code'] as $key) {
                        $line['tax'][$key] = $this->normaliseCode($line['tax'][$key] ?? null);
                    }

                    $line['tax']['percentage'] = $this->normaliseDecimal($line['tax']['percentage'] ?? null);
                }

                return $line;
            }, $lines);
        }

        $this->merge([
            'document_type' => $this->normaliseCode($this->input('document_type')),
            'currency_code' => $this->normaliseCode($this->input('currency_code')),
            'customer' => $customer,
            'notes' => $this->trimString($this->input('notes')),
            'lines' => $lines,
        ]);
    }

    /** @param array<string, mixed> $tax */
    private function validateTaxTreatment(Validator $validator, int|string $index, array $tax): void
    {
        $type = $tax['type'] ?? null;
        $code = $tax['code'] ?? null;
        $percentage = $tax['percentage'] ?? null;
        $exemptionCode = $tax['exemption_code'] ?? null;
        $key = "lines.{$index}.tax";

        if ($type === 'IVA' && $code === 'NOR') {
            if (! in_array($percentage, ['14', '14.0', '14.00'], true)) {
                $validator->errors()->add("{$key}.percentage", 'A taxa normal de IVA deve ser 14%.');
            }

            if (filled($exemptionCode)) {
                $validator->errors()->add("{$key}.exemption_code", 'A taxa normal de IVA não aceita motivo de isenção.');
            }

            return;
        }

        $isExempt = $type === 'IVA' && $code === 'ISE';
        $isNotSubject = $type === 'NS' && blank($code);

        if (! $isExempt && ! $isNotSubject) {
            $validator->errors()->add("{$key}.type", 'Seleccione um tratamento fiscal suportado.');

            return;
        }

        if (! in_array($percentage, ['0', '0.0', '0.00'], true)) {
            $validator->errors()->add("{$key}.percentage", 'Uma isenção ou não sujeição deve usar taxa 0%.');
        }

        if (blank($exemptionCode)) {
            $validator->errors()->add("{$key}.exemption_code", 'Indique o motivo legal da isenção ou não sujeição.');
        }
    }

    private function normaliseCode(mixed $value): mixed
    {
        return is_string($value) ? mb_strtoupper(trim($value)) : $value;
    }

    private function normaliseDecimal(mixed $value): mixed
    {
        return is_string($value) ? str_replace(',', '.', trim($value)) : $value;
    }

    private function trimString(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
