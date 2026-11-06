<?php

namespace App\Http\Requests;

class StoreMobileQuoteRequest extends PreviewMobileQuoteRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_key' => ['required', 'uuid'],
            'valid_for_days' => ['required', 'integer', 'between:1,365'],
            'expected_total_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
        ];
    }
}
