<?php

namespace App\Http\Requests;

use App\Fiscal\SupportedTaxTreatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An invoice as the phone may describe it: who, which items and how many, a
 * discount on a line and a note for the customer.
 *
 * A line is either an item from the catalogue, whose price and tax are read on
 * the server, or a one-off line the person describes and prices themselves.
 *
 * @phpstan-type MobileLine array{catalogue_item_public_id?: string, quantity: string, discount_percentage?: string, description?: string, unit_price_minor?: int, tax_treatment?: string, service?: bool}
 */
class PreviewMobileInvoiceRequest extends FormRequest
{
    /** The most a price can be: 9 999 999 999 999,99, as on the web's editor. */
    public const MAXIMUM_UNIT_PRICE_MINOR = 999_999_999_999_999;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rich = $this->linesMayCarryAdjustments();

        return [
            'customer_public_id' => ['required', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', $rich
                ? 'array:catalogue_item_public_id,quantity,discount_percentage,description,unit_price_minor,tax_treatment,service'
                : 'array:catalogue_item_public_id,quantity'],
            'lines.*.catalogue_item_public_id' => [$rich ? 'nullable' : 'required', 'string', 'max:40'],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/',
                'not_in:0,0.0,0.00,0.000,0.0000',
            ],
            ...($rich ? [
                'lines.*.discount_percentage' => ['nullable', 'string', 'max:6', 'regex:/\A(?:100(?:\.0{1,2})?|(?:0|[1-9]\d?)(?:\.\d{1,2})?)\z/'],
                'lines.*.description' => ['nullable', 'string', 'min:2', 'max:200'],
                'lines.*.unit_price_minor' => ['nullable', 'integer', 'min:1', 'max:'.self::MAXIMUM_UNIT_PRICE_MINOR],
                'lines.*.tax_treatment' => ['nullable', Rule::enum(SupportedTaxTreatment::class)],
                'lines.*.service' => ['nullable', 'boolean'],
                'notes' => ['nullable', 'string', 'max:1000'],
            ] : []),
        ];
    }

    /**
     * An invoice line may carry a discount and may be a one-off; a quote's lines
     * stay plain catalogue lines.
     */
    protected function linesMayCarryAdjustments(): bool
    {
        return true;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        if (! $this->linesMayCarryAdjustments()) {
            return [];
        }

        return [
            function (Validator $validator): void {
                foreach ((array) $this->input('lines', []) as $index => $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $fromCatalogue = filled($line['catalogue_item_public_id'] ?? null);
                    $oneOff = filled($line['description'] ?? null);

                    if ($fromCatalogue === $oneOff) {
                        $validator->errors()->add(
                            "lines.{$index}",
                            'Cada linha é um artigo do catálogo ou uma descrição com preço, não as duas.',
                        );

                        continue;
                    }

                    if ($oneOff && (blank($line['unit_price_minor'] ?? null) || blank($line['tax_treatment'] ?? null))) {
                        $validator->errors()->add(
                            "lines.{$index}.unit_price_minor",
                            'Uma linha avulsa precisa do preço e do imposto.',
                        );
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Adicione pelo menos um artigo à factura.',
            'lines.min' => 'Adicione pelo menos um artigo à factura.',
            'lines.max' => 'Uma factura não pode ter mais de 200 linhas.',
            'lines.*.quantity.regex' => 'A quantidade usa até quatro casas decimais.',
            'lines.*.quantity.not_in' => 'A quantidade tem de ser superior a zero.',
            'lines.*.discount_percentage.regex' => 'O desconto vai de 0 a 100 %, com até duas casas decimais.',
            'lines.*.description.min' => 'Descreva a linha com pelo menos duas letras.',
            'lines.*.unit_price_minor.max' => 'O preço não pode passar de 9 999 999 999 999,99.',
            'lines.*.unit_price_minor.min' => 'O preço tem de ser superior a zero.',
            'notes.max' => 'A nota não pode passar de 1000 caracteres.',
        ];
    }

    /**
     * @return list<MobileLine>
     */
    public function invoiceLines(): array
    {
        return array_values(array_map(function (array $line): array {
            $clean = ['quantity' => (string) $line['quantity']];

            if (filled($line['catalogue_item_public_id'] ?? null)) {
                $clean['catalogue_item_public_id'] = (string) $line['catalogue_item_public_id'];
            } else {
                $clean['description'] = trim((string) $line['description']);
                $clean['unit_price_minor'] = (int) $line['unit_price_minor'];
                $clean['tax_treatment'] = (string) $line['tax_treatment'];
                $clean['service'] = (bool) ($line['service'] ?? false);
            }

            if (filled($line['discount_percentage'] ?? null)) {
                $clean['discount_percentage'] = (string) $line['discount_percentage'];
            }

            return $clean;
        }, (array) $this->validated('lines')));
    }

    /** Whether any line is described and priced by the person rather than the catalogue. */
    public function hasOneOffLines(): bool
    {
        return array_any($this->invoiceLines(), fn (array $line): bool => isset($line['description']));
    }

    public function notes(): ?string
    {
        $notes = trim((string) $this->validated('notes', ''));

        return $notes === '' ? null : $notes;
    }
}
