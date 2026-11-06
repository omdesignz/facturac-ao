<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ConfirmsIssuer;

class StoreMobileInvoiceRequest extends PreviewMobileInvoiceRequest
{
    use ConfirmsIssuer;

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
            ...$this->issuerRules($this->boolean('issue')),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'password.required' => 'Confirme a palavra-passe para emitir.',
        ];
    }
}
