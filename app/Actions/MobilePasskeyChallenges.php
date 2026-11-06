<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Exceptions\InvalidPasskeyException;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * The passkey challenges the phone answers, kept where the web keeps them in the
 * session.
 *
 * A device has no session, so each challenge is stored under a random id the
 * phone sends back with its answer. A challenge serves one purpose and, when it
 * was made for someone, only that person; it is taken out as it is read, so an
 * answer can never be played twice.
 */
final readonly class MobilePasskeyChallenges
{
    public const string SIGN_IN = 'sign-in';

    public const string CONFIRM = 'confirm';

    public const string REGISTER = 'register';

    public function __construct(
        private GenerateVerificationOptions $verification,
        private GenerateRegistrationOptions $registration,
    ) {}

    /**
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function verification(string $purpose, ?User $user = null): array
    {
        $options = ($this->verification)($user);

        return ['challenge_id' => $this->keep($purpose, $user, WebAuthn::toJson($options)), 'options' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function registration(User $user): array
    {
        $options = ($this->registration)($user);

        return ['challenge_id' => $this->keep(self::REGISTER, $user, WebAuthn::toJson($options)), 'options' => WebAuthn::toBrowserArray($options)];
    }

    /**
     * @throws InvalidPasskeyException
     */
    public function takeVerification(string $purpose, string $challengeId, ?User $user = null): PublicKeyCredentialRequestOptions
    {
        return WebAuthn::fromJson($this->take($purpose, $challengeId, $user), PublicKeyCredentialRequestOptions::class);
    }

    /**
     * @throws InvalidPasskeyException
     */
    public function takeRegistration(string $challengeId, User $user): PublicKeyCredentialCreationOptions
    {
        return WebAuthn::fromJson($this->take(self::REGISTER, $challengeId, $user), PublicKeyCredentialCreationOptions::class);
    }

    private function keep(string $purpose, ?User $user, string $options): string
    {
        $challengeId = Str::random(40);

        Cache::put(
            $this->key($purpose, $challengeId),
            ['user_id' => $user?->getKey(), 'options' => $options],
            now()->addMilliseconds(Passkeys::timeout())->addSeconds(30),
        );

        return $challengeId;
    }

    /**
     * @throws InvalidPasskeyException
     */
    private function take(string $purpose, string $challengeId, ?User $user): string
    {
        /** @var array{user_id: int|null, options: string}|null $kept */
        $kept = Cache::pull($this->key($purpose, $challengeId));

        if ($kept === null || $kept['user_id'] !== $user?->getKey()) {
            throw InvalidPasskeyException::make('O pedido da chave de acesso expirou. Tente outra vez.');
        }

        return $kept['options'];
    }

    private function key(string $purpose, string $challengeId): string
    {
        return "mobile-passkey:{$purpose}:".hash('sha256', $challengeId);
    }
}
