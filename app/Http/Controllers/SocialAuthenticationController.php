<?php

namespace App\Http\Controllers;

use App\Exceptions\SocialIdentityConflictException;
use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class SocialAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless($this->googleIsConfigured(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($this->googleIsConfigured(), 404);

        try {
            $providerUser = Socialite::driver('google')->user();
        } catch (Throwable $exception) {
            Log::warning('Google social authentication failed.', [
                'exception_type' => $exception::class,
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'Não foi possível concluir a entrada com Google. Tente novamente.');
        }

        $email = $providerUser->getEmail();
        $providerUserId = trim((string) $providerUser->getId());

        if ($providerUserId === '') {
            return redirect()
                ->route('login')
                ->with('error', 'A identidade devolvida pela Google é inválida. Tente novamente.');
        }

        if (! is_string($email) || ! $this->hasVerifiedEmail($providerUser)) {
            return redirect()
                ->route('login')
                ->with('error', 'A conta Google precisa de um endereço de email verificado.');
        }

        try {
            [$user, $wasCreated] = DB::transaction(function () use ($providerUser, $providerUserId, $email): array {
                $identity = SocialIdentity::query()
                    ->with('user')
                    ->where('provider', 'google')
                    ->where('provider_user_id', $providerUserId)
                    ->lockForUpdate()
                    ->first();

                if ($identity !== null) {
                    return [$identity->user, false];
                }

                $normalisedEmail = Str::lower($email);
                $user = User::query()->where('email', $normalisedEmail)->lockForUpdate()->first();
                $wasCreated = $user === null;

                if ($user === null) {
                    $user = User::query()->create([
                        'name' => $providerUser->getName() ?: Str::before($normalisedEmail, '@'),
                        'email' => $normalisedEmail,
                        'password' => Hash::make(Str::random(64)),
                    ]);
                    $user->forceFill(['email_verified_at' => now()])->save();
                } else {
                    $hasDifferentGoogleIdentity = $user->socialIdentities()
                        ->where('provider', 'google')
                        ->lockForUpdate()
                        ->exists();

                    if ($hasDifferentGoogleIdentity) {
                        throw new SocialIdentityConflictException;
                    }

                    if (! $user->hasVerifiedEmail()) {
                        $user->markEmailAsVerified();
                    }
                }

                $user->socialIdentities()->create([
                    'provider' => 'google',
                    'provider_user_id' => $providerUserId,
                    'provider_email' => $normalisedEmail,
                ]);

                return [$user, $wasCreated];
            });
        } catch (SocialIdentityConflictException) {
            return redirect()
                ->route('login')
                ->with('error', 'Esta conta já está ligada a outra identidade Google. Entre com a ligação existente.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        activity('authentication')
            ->event('social-login')
            ->causedBy($user)
            ->withProperties(['provider' => 'google'])
            ->log('social login');

        return $wasCreated
            ? redirect()->route('workspace.setup')
            : redirect()->intended(route('dashboard'));
    }

    private function hasVerifiedEmail(ProviderUser $providerUser): bool
    {
        $rawUser = method_exists($providerUser, 'getRaw') ? $providerUser->getRaw() : [];
        $verified = $rawUser['email_verified'] ?? $rawUser['verified_email'] ?? false;

        return filter_var($verified, FILTER_VALIDATE_BOOL) === true;
    }

    private function googleIsConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
