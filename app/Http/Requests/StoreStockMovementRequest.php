<?php

namespace App\Http\Requests;

use App\StockMovementType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStockMovementRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'catalogue_item' => ['required', 'string'],
            'establishment' => ['required', 'string'],
            'type' => [
                'required',
                Rule::enum(StockMovementType::class)->only(StockMovementType::manual()),
            ],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:500'],

            /** Only used by a transfer, where the stock has to land somewhere. */
            'destination_establishment' => [
                'required_if:type,transfer_out',
                'nullable',
                'string',
                'different:establishment',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'quantity.gt' => 'A quantidade tem de ser maior que zero.',
            'destination_establishment.required_if' => 'Escolha o estabelecimento de destino.',
            'destination_establishment.different' => 'O destino tem de ser diferente da origem.',
        ];
    }
}
