<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ConfirmsIssuer;

/**
 * The web's rules for opening a shift. The web asks for the password on a page of
 * its own; the phone sends it with the request, and the controller checks it.
 */
class StoreMobilePosSessionRequest extends StorePosSessionRequest
{
    use ConfirmsIssuer;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
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
            'password.required' => 'Confirme a palavra-passe para abrir o turno.',
        ];
    }
}
