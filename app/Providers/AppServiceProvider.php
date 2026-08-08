<?php

namespace App\Providers;

use App\Models\ImpersonationSession;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountAccessedBySupport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Contracts\Activity as ActivityContract;

class AppServiceProvider extends ServiceProvider
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
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuditContext();
        $this->configureSecurityAuditEvents();
        $this->configureWorkSession();
        $this->configureImpersonationNotices();
    }

    /**
     * Record when the customer was actually told support entered their account.
     *
     * Stamped from the delivery event rather than at dispatch: if the queue
     * never drains, the column stays null and the gap between "we sent it" and
     * "they received it" stays visible instead of being papered over.
     */
    protected function configureImpersonationNotices(): void
    {
        Event::listen(NotificationSent::class, function (NotificationSent $event): void {
            if (! $event->notification instanceof AccountAccessedBySupport || $event->channel !== 'mail') {
                return;
            }

            ImpersonationSession::query()
                ->where('public_id', $event->notification->sessionPublicId)
                ->whereNull('subject_notified_at')
                ->update(['subject_notified_at' => now()]);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Add tenant and request evidence to every model or explicit activity event.
     */
    protected function configureAuditContext(): void
    {
        LogActivityAction::beforeLogging(function (ActivityContract $activity): void {
            if (! $activity instanceof Model) {
                return;
            }

            $subject = $activity->getRelationValue('subject');
            $workspaceId = Context::get('workspace_id');

            if ($workspaceId === null && $subject instanceof Workspace) {
                $workspaceId = $subject->id;
            } elseif ($workspaceId === null && $subject instanceof Model) {
                $workspaceId = $subject->getAttribute('workspace_id');
            }

            $existingProperties = $activity->getAttribute('properties');
            $properties = $existingProperties instanceof Collection
                ? $existingProperties->all()
                : [];

            $auditContext = array_filter([
                'workspace_id' => $workspaceId,
                'request_id' => Context::get('request_id'),
                'ip_address' => Context::get('request_ip'),
                'user_agent' => app()->runningInConsole()
                    ? null
                    : mb_substr((string) request()->userAgent(), 0, 512),
            ], fn (mixed $value): bool => $value !== null && $value !== '');

            $activity->setAttribute('properties', collect([
                ...$properties,
                ...$auditContext,
            ]));
        });
    }

    /**
     * Start a fresh work block whenever a user signs in, by any route.
     *
     * Listening to the framework's Login event covers password, passkey,
     * two-factor and social sign-in without each having to remember.
     */
    protected function configureWorkSession(): void
    {
        Event::listen(Login::class, function (): void {
            session()->put((string) config('work_session.started_at_key'), now()->getTimestamp());
        });
    }

    /**
     * Record security-sensitive account changes without logging any secrets.
     */
    protected function configureSecurityAuditEvents(): void
    {
        Event::listen(
            TwoFactorAuthenticationEnabled::class,
            fn (TwoFactorAuthenticationEnabled $event) => $this->logSecurityActivity(
                $event->user,
                'mfa-enabled',
                'multi-factor authentication enabled',
            ),
        );
        Event::listen(
            TwoFactorAuthenticationConfirmed::class,
            fn (TwoFactorAuthenticationConfirmed $event) => $this->logSecurityActivity(
                $event->user,
                'mfa-confirmed',
                'multi-factor authentication confirmed',
            ),
        );
        Event::listen(
            TwoFactorAuthenticationDisabled::class,
            fn (TwoFactorAuthenticationDisabled $event) => $this->logSecurityActivity(
                $event->user,
                'mfa-disabled',
                'multi-factor authentication disabled',
            ),
        );
        Event::listen(
            RecoveryCodesGenerated::class,
            fn (RecoveryCodesGenerated $event) => $this->logSecurityActivity(
                $event->user,
                'mfa-recovery-codes-regenerated',
                'multi-factor recovery codes regenerated',
            ),
        );
        Event::listen(
            PasswordUpdatedViaController::class,
            fn (PasswordUpdatedViaController $event) => $this->logSecurityActivity(
                $event->user,
                'password-updated',
                'account password updated',
            ),
        );
    }

    protected function logSecurityActivity(User $user, string $event, string $description): void
    {
        activity('security')
            ->event($event)
            ->causedBy($user)
            ->withProperties([
                'workspace_id' => $user->current_workspace_id,
            ])
            ->log($description);
    }
}
