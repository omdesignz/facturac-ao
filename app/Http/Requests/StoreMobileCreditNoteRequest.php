<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ConfirmsIssuer;

class StoreMobileCreditNoteRequest extends PreviewMobileCreditNoteRequest
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
            // The AGT will not take a note without a reason, nor one past 60 characters.
            'reason' => ['required', 'string', 'min:5', 'max:60'],
            'expected_total_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
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
            'reason.required' => 'Diga porque corrige a factura.',
            'reason.min' => 'Descreva o motivo em pelo menos 5 caracteres.',
            'reason.max' => 'O motivo tem no máximo 60 caracteres.',
            'password.required' => 'Confirme a palavra-passe para emitir.',
        ];
    }
}
