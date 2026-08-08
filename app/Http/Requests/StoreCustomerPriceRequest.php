<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerPriceRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'catalogue_item' => ['required', 'string'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** The agreed price in minor units, parsed without touching a float. */
    public function unitPriceMinor(): int
    {
        [$whole, $fraction] = array_pad(
            explode('.', trim((string) $this->validated('unit_price')), 2),
            2,
            '',
        );

        return (int) ($whole.substr(str_pad($fraction, 2, '0'), 0, 2));
    }
}
