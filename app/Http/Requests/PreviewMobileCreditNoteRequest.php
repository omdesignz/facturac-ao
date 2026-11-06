<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A credit note as the phone may describe it: which lines of the invoice, and
 * how much of each. Prices and taxes are the invoice's own.
 */
class PreviewMobileCreditNoteRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', 'array:line_number,quantity'],
            'lines.*.line_number' => ['required', 'integer', 'min:1', 'max:4294967295', 'distinct'],
            'lines.*.quantity' => [
                'required',
                'string',
                'max:20',
                'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,4})?\z/',
                'not_in:0,0.0,0.00,0.000,0.0000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Escolha pelo menos uma linha a creditar.',
            'lines.min' => 'Escolha pelo menos uma linha a creditar.',
            'lines.*.line_number.distinct' => 'Cada linha só pode aparecer uma vez.',
            'lines.*.quantity.regex' => 'A quantidade usa até quatro casas decimais.',
            'lines.*.quantity.not_in' => 'A quantidade tem de ser superior a zero.',
        ];
    }

    /**
     * @return list<array{line_number: int, quantity: string}>
     */
    public function creditLines(): array
    {
        return array_values(array_map(fn (array $line): array => [
            'line_number' => (int) $line['line_number'],
            'quantity' => (string) $line['quantity'],
        ], (array) $this->validated('lines')));
    }
}
