<?php

namespace App\Http\Requests;

class StoreMobileInvoiceRequest extends PreviewMobileInvoiceRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_key' => ['required', 'uuid'],
            'expected_total_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'issue' => ['required', 'boolean'],
            'password' => ['required_if:issue,true', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'password.required_if' => 'Confirme a palavra-passe para emitir.',
        ];
    }
}
