<?php

namespace App\Actions;

use App\Exceptions\MobileSignInRefused;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\NewAccessToken;

/**
 * Gives one device its own token, after the same checks as the web sign-in.
 *
 * A device has no session for Fortify's two-step challenge to live in, so the
 * password and the code arrive together: the first call, without a code, tells
 * the app that one is needed, and only after the password has proved right.
 */
class SignInMobileDevice
{
    public const TOKEN_ABILITY = 'mobile';

    public const TOKEN_LIFETIME_DAYS = 30;

    public function __construct(private readonly TwoFactorAuthenticationProvider $twoFactor) {}

    /**
     * @throws MobileSignInRefused
     */
    public function handle(string $email, string $password, string $deviceName, ?string $code = null, ?string $recoveryCode = null): NewAccessToken
    {
        $user = User::query()->where('email', Str::lower($email))->first();

        if (! $this->passwordMatches($user, $password)) {
            throw MobileSignInRefused::invalidCredentials();
        }

        if (! $user->hasVerifiedEmail()) {
            throw MobileSignInRefused::emailNotVerified();
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $this->passTwoFactor($user, $code, $recoveryCode);
        }

        return $this->issue($user, $deviceName, 'password');
    }

    /**
     * A passkey the server has just verified stands for the password and the
     * second step together, as it does on the web: it is something the person
     * has, unlocked by something only they can do.
     *
     * @throws MobileSignInRefused
     */
    public function handlePasskey(User $user, string $deviceName): NewAccessToken
    {
        if (! $user->hasVerifiedEmail()) {
            throw MobileSignInRefused::emailNotVerified();
        }

        return $this->issue($user, $deviceName, 'passkey');
    }

    private function issue(User $user, string $deviceName, string $method): NewAccessToken
    {
        $token = $user->createToken($deviceName, [self::TOKEN_ABILITY], now()->addDays(self::TOKEN_LIFETIME_DAYS));

        activity('security')
            ->event('mobile-session-created')
            ->causedBy($user)
            ->withProperties([
                'workspace_id' => $user->current_workspace_id,
                'token_id' => $token->accessToken->getKey(),
                'method' => $method,
            ])
            ->log('mobile device signed in');

        return $token;
    }

    /**
     * An unknown address still costs one hash, so the answer's timing does not
     * say which addresses have accounts.
     *
     * @phpstan-assert-if-true User $user
     */
    private function passwordMatches(?User $user, string $password): bool
    {
        if ($user === null) {
            Hash::make($password);

            return false;
        }

        return Hash::check($password, $user->password);
    }

    private function passTwoFactor(User $user, ?string $code, ?string $recoveryCode): void
    {
        if (blank($code) && blank($recoveryCode)) {
            throw MobileSignInRefused::twoFactorRequired();
        }

        if (filled($code) && $this->twoFactor->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code)) {
            return;
        }

        $matchingRecoveryCode = filled($recoveryCode)
            ? collect($user->recoveryCodes())->first(fn (string $candidate): bool => hash_equals($candidate, $recoveryCode))
            : null;

        if ($matchingRecoveryCode === null) {
            throw MobileSignInRefused::invalidTwoFactorCode();
        }

        $user->replaceRecoveryCode($matchingRecoveryCode);
    }
}
