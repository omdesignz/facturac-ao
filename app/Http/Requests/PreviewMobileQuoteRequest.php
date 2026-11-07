<?php

namespace App\Http\Requests;

/**
 * A quote as the phone may describe it: who, which items and how many.
 */
class PreviewMobileQuoteRequest extends PreviewMobileInvoiceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        $rules['lines.*.quantity'] = [...$rules['lines.*.quantity'], 'numeric', 'max:1000000'];

        return $rules;
    }

    /**
     * The quote's lines: always catalogue items, since a quote takes no
     * discount and no one-off line.
     *
     * @return list<array{catalogue_item_public_id: string, quantity: string}>
     */
    public function quoteLines(): array
    {
        return array_values(array_map(fn (array $line): array => [
            'catalogue_item_public_id' => (string) $line['catalogue_item_public_id'],
            'quantity' => (string) $line['quantity'],
        ], (array) $this->validated('lines')));
    }

    /** A quote stays plain catalogue lines, priced on the server. */
    protected function linesMayCarryAdjustments(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'lines.required' => 'Adicione pelo menos um artigo ao orçamento.',
            'lines.min' => 'Adicione pelo menos um artigo ao orçamento.',
            'lines.max' => 'Um orçamento não pode ter mais de 200 linhas.',
            'lines.*.quantity.max' => 'A quantidade não pode passar de 1 000 000.',
        ];
    }
}
