<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

/**
 * The proof a phone sends when it issues: the password, or a passkey's answer
 * to a confirmation challenge. One of the two, never neither.
 */
trait ConfirmsIssuer
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function issuerRules(bool $issuing = true): array
    {
        return [
            'password' => [Rule::requiredIf(fn (): bool => $issuing && ! $this->has('passkey')), 'nullable', 'string', 'max:255'],
            'passkey' => ['nullable', 'array:challenge_id,credential'],
            'passkey.challenge_id' => ['required_with:passkey', 'string', 'max:64'],
            'passkey.credential' => ['required_with:passkey', 'array'],
        ];
    }
}
