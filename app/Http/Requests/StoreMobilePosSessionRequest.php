<?php

namespace App\Http\Requests;

/**
 * The web's rules for opening a shift. The web asks for the password on a page of
 * its own; the phone sends it with the request, and the controller checks it.
 */
class StoreMobilePosSessionRequest extends StorePosSessionRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'password' => ['required', 'string', 'max:255'],
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
