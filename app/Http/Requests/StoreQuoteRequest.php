<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQuoteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'establishment_public_id' => ['required', 'string'],
            'customer_public_id' => ['nullable', 'string'],
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.tax_identification_number' => ['nullable', 'string', 'max:32'],
            'customer.country_code' => ['required', 'string', 'size:2'],
            'customer.address_line' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date'],
            'valid_until' => ['required', 'date', 'after_or_equal:issue_date'],
            'notes' => ['nullable', 'string', 'max:2000'],

            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.product_code' => ['nullable', 'string', 'max:60'],
            'lines.*.product_description' => ['required', 'string', 'max:255'],
            'lines.*.unit_of_measure' => ['required', 'string', 'max:20'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'lines.*.discount_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'lines.*.tax_type' => ['required', 'string', 'max:10'],
            'lines.*.tax_code' => ['nullable', 'string', 'max:10'],
            'lines.*.tax_exemption_code' => ['nullable', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Um orçamento precisa de pelo menos uma linha.',
            'valid_until.after_or_equal' => 'A validade não pode terminar antes da data do orçamento.',
        ];
    }

    /**
     * The shape the save action expects, with every amount already in integers.
     *
     * @return array{
     *     customer_public_id: string|null,
     *     customer: array{name: string, tax_identification_number: string|null, country_code: string, address_line: string|null},
     *     establishment_public_id: string,
     *     issue_date: string,
     *     valid_until: string,
     *     notes: string|null,
     *     lines: list<array{
     *         product_code: string|null,
     *         product_description: string,
     *         unit_of_measure: string,
     *         quantity_units: int,
     *         unit_price_minor: int,
     *         discount_rate_basis_points: int,
     *         tax_type: string,
     *         tax_code: string|null,
     *         tax_percentage: string,
     *         tax_exemption_code: string|null
     *     }>
     * }
     */
    public function profile(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'establishment_public_id' => (string) $validated['establishment_public_id'],
            'customer_public_id' => $validated['customer_public_id'] ?? null,
            'customer' => [
                'name' => (string) $validated['customer']['name'],
                'tax_identification_number' => $validated['customer']['tax_identification_number'] ?? null,
                'country_code' => strtoupper((string) $validated['customer']['country_code']),
                'address_line' => $validated['customer']['address_line'] ?? null,
            ],
            'issue_date' => (string) $validated['issue_date'],
            'valid_until' => (string) $validated['valid_until'],
            'notes' => $validated['notes'] ?? null,
            'lines' => array_values(array_map(
                fn (array $line): array => [
                    'product_code' => $line['product_code'] ?? null,
                    'product_description' => (string) $line['product_description'],
                    'unit_of_measure' => (string) $line['unit_of_measure'],
                    'quantity_units' => $this->scaled((string) $line['quantity'], 3),
                    'unit_price_minor' => $this->scaled((string) $line['unit_price'], 2),
                    'discount_rate_basis_points' => $this->scaled(
                        (string) ($line['discount_rate'] ?? '0'),
                        2,
                    ),
                    'tax_type' => (string) $line['tax_type'],
                    'tax_code' => $line['tax_code'] ?? null,
                    'tax_percentage' => (string) $line['tax_percentage'],
                    'tax_exemption_code' => $line['tax_exemption_code'] ?? null,
                ],
                $validated['lines'],
            )),
        ];
    }

    /** Parses a typed decimal into scaled integers without touching a float. */
    private function scaled(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, $scale, '0'), 0, $scale));
    }
}
