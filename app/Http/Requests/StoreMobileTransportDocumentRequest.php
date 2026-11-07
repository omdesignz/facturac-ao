<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ConfirmsIssuer;

/**
 * A guide the phone issues at once: the phone never leaves a draft behind, so
 * every request carries the proof that the account's owner is holding it.
 */
class StoreMobileTransportDocumentRequest extends PreviewMobileTransportDocumentRequest
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
            'expected_total_minor' => ['required', 'integer', 'min:0', 'max:999999999999999'],
            ...$this->issuerRules(),
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
