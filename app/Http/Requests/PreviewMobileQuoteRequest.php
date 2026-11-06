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
