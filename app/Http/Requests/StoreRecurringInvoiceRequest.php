<?php

namespace App\Http\Requests;

use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentType;
use App\RecurrenceFrequency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRecurringInvoiceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'customer_public_id' => ['required', 'string'],
            'establishment_public_id' => ['required', 'string'],
            'document_type' => [
                'required',
                Rule::in([
                    FiscalDocumentType::Invoice->value,
                    FiscalDocumentType::InvoiceReceipt->value,
                ]),
            ],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after:starts_on'],
            'is_active' => ['required', 'boolean'],
            'auto_issue' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_code' => ['nullable', 'string', 'max:60'],
            'lines.*.product_description' => ['required', 'string', 'max:255'],
            'lines.*.unit_of_measure' => ['required', 'string', 'max:20'],
            'lines.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'max:1000000',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/',
            ],
            'lines.*.unit_price' => [
                'required',
                'numeric',
                'min:0',
                'max:1000000000',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/',
            ],
            'lines.*.tax_percentage' => [
                'required',
                'numeric',
                'min:0',
                'max:100',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/',
            ],
            'lines.*.tax_type' => ['required', Rule::in(['IVA', 'NS'])],
            'lines.*.tax_code' => ['nullable', Rule::in(['NOR', 'ISE'])],
            'lines.*.tax_exemption_code' => ['nullable', Rule::in(['M00', 'M02', 'M04'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Uma avença precisa de pelo menos uma linha.',
            'ends_on.after' => 'O fim tem de ser posterior ao início.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (is_array($lines)) {
            $lines = array_map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                foreach (['quantity', 'unit_price', 'tax_percentage'] as $field) {
                    $line[$field] = $this->normaliseDecimal($line[$field] ?? null);
                }

                $taxType = $this->normaliseCode($line['tax_type'] ?? 'IVA') ?? 'IVA';
                $taxCode = $this->normaliseCode($line['tax_code'] ?? null);
                $taxExemptionCode = $this->normaliseCode($line['tax_exemption_code'] ?? null);
                $taxTreatment = SupportedTaxTreatment::fromLegacyComponents(
                    $taxType,
                    $taxCode,
                    $line['tax_percentage'] ?? null,
                    $taxExemptionCode,
                );
                $taxProfile = $taxTreatment?->profile();

                $line['tax_type'] = $taxProfile['type'] ?? $taxType;
                $line['tax_code'] = $taxProfile !== null ? $taxProfile['code'] : $taxCode;
                $line['tax_percentage'] = $taxProfile['percentage'] ?? ($line['tax_percentage'] ?? null);
                $line['tax_exemption_code'] = $taxProfile !== null
                    ? $taxProfile['exemption_code']
                    : $taxExemptionCode;

                return $line;
            }, $lines);
        }

        $this->merge([
            'is_active' => $this->boolean('is_active'),
            'auto_issue' => $this->boolean('auto_issue'),
            'lines' => $lines,
        ]);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $lines = $this->input('lines', []);

                if (! is_array($lines)) {
                    return;
                }

                foreach ($lines as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    if (SupportedTaxTreatment::fromComponents(
                        $line['tax_type'] ?? null,
                        $line['tax_code'] ?? null,
                        $line['tax_percentage'] ?? null,
                        $line['tax_exemption_code'] ?? null,
                    ) === null) {
                        $validator->errors()->add(
                            "lines.{$index}.tax_percentage",
                            'Seleccione um tratamento fiscal suportado.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * The stored line shape, with amounts already scaled to integers.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(): array
    {
        /** @var list<array<string, mixed>> $lines */
        $lines = $this->validated('lines');

        return array_map(
            fn (array $line): array => [
                'product_code' => $line['product_code'] ?? null,
                'operation_type' => 'SG',
                'product_description' => (string) $line['product_description'],
                'unit_of_measure' => (string) $line['unit_of_measure'],
                'quantity_units' => $this->scaled((string) $line['quantity'], 4),
                'quantity_scale' => 4,
                'unit_price_minor' => $this->scaled((string) $line['unit_price'], 2),
                'discount_rate_basis_points' => 0,
                'tax_type' => (string) $line['tax_type'],
                'tax_code' => $line['tax_code'] ?? null,
                'tax_percentage' => (string) $line['tax_percentage'],
                'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
            ],
            $lines,
        );
    }

    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }

    private function normaliseCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtoupper(trim($value));

        return $value === '' ? null : $value;
    }

    private function normaliseDecimal(mixed $value): mixed
    {
        return is_string($value) ? str_replace(',', '.', trim($value)) : $value;
    }
}
