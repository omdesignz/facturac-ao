<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

/**
 * Proves, at the moment of issuing, that the person holding the phone is the
 * account's owner: with the password, or with one of their passkeys.
 *
 * A passkey is checked here, by the server, against the challenge it was given.
 * Face ID on its own only unlocks the passkey on the phone; it is the signature
 * that comes back which proves anything, so it is the only form of Face ID the
 * server takes.
 */
final readonly class ConfirmMobileIssuer
{
    public function __construct(
        private MobilePasskeyChallenges $challenges,
        private VerifyPasskey $verify,
    ) {}

    /**
     * Null when the person is confirmed, or why not.
     *
     * @param  array<string, mixed>  $validated
     * @return array{code: string, message: string}|null
     */
    public function refusal(User $user, array $validated): ?array
    {
        $passkey = $validated['passkey'] ?? null;

        if (is_array($passkey)) {
            try {
                ($this->verify)(
                    $this->credential((array) $passkey['credential']),
                    $this->challenges->takeVerification(MobilePasskeyChallenges::CONFIRM, (string) $passkey['challenge_id'], $user),
                    $user,
                );
            } catch (InvalidPasskeyException) {
                return ['code' => 'PASSKEY_REFUSED', 'message' => 'A chave de acesso não confirmou. Tente outra vez ou use a palavra-passe.'];
            }

            return null;
        }

        return Hash::check((string) ($validated['password'] ?? ''), $user->password)
            ? null
            : ['code' => 'PASSWORD_INCORRECT', 'message' => 'A palavra-passe não está certa.'];
    }

    /**
     * The phone writes every key of a request in snake_case, the passkey's
     * answer included, so its keys are put back as WebAuthn names them.
     */
    private const array WEBAUTHN_KEYS = [
        'raw_id' => 'rawId',
        'client_data_json' => 'clientDataJSON',
        'authenticator_data' => 'authenticatorData',
        'user_handle' => 'userHandle',
        'attestation_object' => 'attestationObject',
        'authenticator_attachment' => 'authenticatorAttachment',
    ];

    /**
     * @param  array<mixed>  $credential
     *
     * @throws InvalidPasskeyException
     */
    public function credential(array $credential): PublicKeyCredential
    {
        try {
            return WebAuthn::fromJson(json_encode($this->webAuthnKeys($credential), JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            throw InvalidPasskeyException::make('A resposta da chave de acesso não é válida.');
        }
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function webAuthnKeys(array $values): array
    {
        $named = [];

        foreach ($values as $key => $value) {
            $named[self::WEBAUTHN_KEYS[$key] ?? $key] = is_array($value) ? $this->webAuthnKeys($value) : $value;
        }

        return $named;
    }
}
