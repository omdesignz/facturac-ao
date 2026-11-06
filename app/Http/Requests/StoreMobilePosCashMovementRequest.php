<?php

namespace App\Http\Requests;

/**
 * The web's rules for a drawer movement, with the key that makes a repeat safe.
 */
class StoreMobilePosCashMovementRequest extends StorePosCashMovementRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'client_key' => ['required', 'uuid'],
        ];
    }
}
