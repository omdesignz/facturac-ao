<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\ResolveAndroidPasskeyAssociation;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Notifications\WelcomeToFacturac;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ResolveAndroidPasskeyAssociation $androidAssociation): void
    {
        config(['passkeys.allowed_origins' => array_values(array_unique([
            ...config('fortify.passkeys.allowed_origins', [config('app.url')]),
            ...$androidAssociation->origins(),
        ]))]);

        VerifyEmail::toMailUsing(fn (User $notifiable, string $url): MailMessage => (new MailMessage)
            ->subject('Confirme o seu email — facturac.ao')
            ->greeting('Bem-vindo ao facturac.ao')
            ->line('Confirme o seu endereço de email para começar a configurar a sua empresa e aceder à sua conta com segurança.')
            ->action('Confirmar o meu email', $url)
            ->line('Esta ligação é válida durante '.config('auth.verification.expire', 60).' minutos.')
            ->line('Se não criou esta conta, pode ignorar este email.'));

        ResetPassword::toMailUsing(fn (User $notifiable, string $token): MailMessage => (new MailMessage)
            ->subject('Repor a palavra-passe — facturac.ao')
            ->greeting('Vamos recuperar o acesso à sua conta')
            ->line('Recebemos um pedido para repor a palavra-passe da sua conta no facturac.ao.')
            ->action('Repor a palavra-passe', route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]))
            ->line('Esta ligação é válida durante '.config('auth.passwords.'.config('auth.defaults.passwords').'.expire').' minutos.')
            ->line('Se não fez este pedido, ignore este email. A sua palavra-passe continuará igual.'));

        Event::listen(Verified::class, function (Verified $event): void {
            if ($event->user instanceof User) {
                $event->user->notify(new WelcomeToFacturac);
            }
        });

        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
            'googleEnabled' => $this->googleIsConfigured(),
        ]));
        Fortify::registerView(fn () => Inertia::render('Auth/Register', [
            'googleEnabled' => $this->googleIsConfigured(),
        ]));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->toString(),
            'token' => $request->route('token'),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail'));
        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));
        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }

    private function googleIsConfigured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }
}
