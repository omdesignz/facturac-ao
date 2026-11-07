<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A count from the shelves: which establishment, and how many of each item were
 * found. Zero is a count too: the item was not there.
 */
class PreviewMobileStockCountRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'establishment_public_id' => ['required', 'string', 'max:40'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*' => ['required', 'array:catalogue_item_public_id,counted_quantity'],
            'lines.*.catalogue_item_public_id' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.counted_quantity' => ['required', 'string', 'max:15', 'regex:/\A(?:0|[1-9]\d{0,9})(?:\.\d{1,4})?\z/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lines.required' => 'Conte pelo menos um artigo.',
            'lines.min' => 'Conte pelo menos um artigo.',
            'lines.max' => 'Uma contagem não pode ter mais de 500 artigos. Confirme esta e comece outra.',
            'lines.*.catalogue_item_public_id.distinct' => 'Este artigo já está na contagem.',
            'lines.*.counted_quantity.regex' => 'A quantidade contada é um número de 0 para cima, com até quatro casas decimais.',
        ];
    }

    /**
     * @return list<array{catalogue_item_public_id: string, counted_quantity: string}>
     */
    public function countLines(): array
    {
        /** @var list<array{catalogue_item_public_id: string, counted_quantity: string}> $lines */
        $lines = array_values((array) $this->validated('lines'));

        return $lines;
    }
}
