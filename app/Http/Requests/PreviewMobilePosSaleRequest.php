<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What a sale would come to. A sale may have no customer at all, which is the only
 * difference from an invoice's preview besides its document type.
 */
class PreviewMobilePosSaleRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'customer_public_id' => ['nullable', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['required', 'array:catalogue_item_public_id,quantity'],
            'lines.*.catalogue_item_public_id' => ['required', 'string', 'max:40'],
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
            'lines.required' => 'Adicione pelo menos um artigo à venda.',
            'lines.min' => 'Adicione pelo menos um artigo à venda.',
            'lines.max' => 'Uma venda não pode ter mais de 200 linhas.',
            'lines.*.quantity.regex' => 'A quantidade usa até quatro casas decimais.',
            'lines.*.quantity.not_in' => 'A quantidade tem de ser superior a zero.',
        ];
    }

    /**
     * @return list<array{catalogue_item_public_id: string, quantity: string}>
     */
    public function saleLines(): array
    {
        /** @var list<array{catalogue_item_public_id: string, quantity: string}> $lines */
        $lines = array_values((array) $this->validated('lines'));

        return $lines;
    }
}
