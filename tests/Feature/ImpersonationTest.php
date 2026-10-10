<?php

use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\ImpersonationSession;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\TransportDocument;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AccountAccessedBySupport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function impersonatorKey(): string
{
    return (string) config('impersonation.session.impersonator');
}

function reason(): string
{
    return 'Cliente não consegue guardar o rascunho da factura FT 2026/14.';
}

/**
 * Puts a support user inside a customer's account and hands back both users
 * plus the session state, so tests can assert on what happens next.
 *
 * @return array{0: User, 1: User}
 */
function startSession(?User $staff = null, ?User $customer = null): array
{
    $staff ??= User::factory()->supportStaff()->withWorkspace()->create();
    $customer ??= User::factory()->withWorkspace()->create();

    test()->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $customer), ['reason' => reason()])
        ->assertRedirect(route('dashboard'));

    return [$staff, $customer];
}

test('the customer is told the moment support enters their account', function () {
    Notification::fake();

    [$staff, $customer] = startSession();

    Notification::assertSentTo(
        $customer,
        AccountAccessedBySupport::class,
        function (AccountAccessedBySupport $notification) use ($staff): bool {
            return $notification->reason === reason()
                && $notification->supportName === $staff->name
                && $notification->maxMinutes === 30;
        },
    );
});

test('nobody but the customer is told', function () {
    Notification::fake();

    [$staff] = startSession();

    Notification::assertNotSentTo($staff, AccountAccessedBySupport::class);
    Notification::assertCount(1);
});

test('the notice names the account, the reason and the way out', function () {
    [, $customer] = startSession();

    $session = ImpersonationSession::query()->sole();
    $mail = AccountAccessedBySupport::fromModel($session)->toMail($customer);
    $body = implode(' ', [...$mail->introLines, ...$mail->outroLines]);

    expect($mail->subject)->toBe('A nossa equipa de apoio entrou na sua conta')
        ->and($body)->toContain(reason())
        ->and($body)->toContain('30 minutos')
        // The customer needs to know what to do if this was not them.
        ->and($body)->toContain('altere a palavra-passe')
        ->and($mail->actionUrl)->toBe(route('settings.security'));
});

test('a failed attempt tells nobody', function () {
    Notification::fake();

    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $colleague = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $colleague), ['reason' => reason()]);

    Notification::assertNothingSent();
});

test('the record shows whether the notice actually went out', function () {
    startSession();

    $session = ImpersonationSession::query()->sole();

    // Marked from the delivery event, not from dispatch.
    expect($session->subject_notified_at)->not->toBeNull();
});

test('a notice that never leaves the queue leaves the record honest', function () {
    Notification::fake();

    startSession();

    expect(ImpersonationSession::query()->sole()->subject_notified_at)->toBeNull();
});

test('the console shows whether each customer was told', function () {
    startSession();

    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->get(route('support.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('sessions.0.subject_notified', true)
        );
});

test('the support console is hidden from customers', function () {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->get(route('support.index'))
        ->assertNotFound();
});

test('support staff can reach the console', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->get(route('support.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Support/Index')
            ->where('maxMinutes', 30)
        );
});

test('the console only lists customers, never other staff', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $colleague = User::factory()->supportStaff()->create(['email' => 'colega@vapsolucoes.ao']);
    $customer = User::factory()->create(['email' => 'cliente@empresa.ao']);

    $this->actingAs($staff)
        ->get(route('support.index', ['search' => 'a']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use ($customer, $colleague) {
            $emails = collect($page->toArray()['props']['results'])->pluck('email');

            expect($emails)->toContain($customer->email)
                ->and($emails)->not->toContain($colleague->email);
        });
});

test('starting an impersonation switches the signed-in user and records it', function () {
    [$staff, $customer] = startSession();

    expect(Auth::id())->toBe($customer->id)
        ->and(session()->get(impersonatorKey()))->toBe($staff->id);

    $session = ImpersonationSession::query()->sole();

    expect($session->impersonator_id)->toBe($staff->id)
        ->and($session->subject_id)->toBe($customer->id)
        ->and($session->reason)->toBe(reason())
        ->and($session->ended_at)->toBeNull()
        ->and($session->ip_address)->not->toBeNull();
});

test('the start is written to the activity log', function () {
    [$staff, $customer] = startSession();

    $activity = Activity::query()->where('log_name', 'impersonation')->sole();

    expect($activity->event)->toBe('started')
        ->and($activity->causer_id)->toBe($staff->id)
        ->and($activity->subject_id)->toBe($customer->id)
        ->and($activity->properties['reason'])->toBe(reason());
});

test('a reason is required and has to say something', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $customer), ['reason' => 'erro'])
        ->assertSessionHasErrors('reason');

    expect(ImpersonationSession::query()->count())->toBe(0);
});

test('a customer cannot impersonate anyone', function () {
    $customer = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $other), ['reason' => reason()])
        ->assertNotFound();

    expect(Auth::id())->toBe($customer->id);
});

test('support staff cannot impersonate one another', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $colleague = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $colleague), ['reason' => reason()])
        ->assertSessionHas('error');

    expect(Auth::id())->toBe($staff->id)
        ->and(ImpersonationSession::query()->count())->toBe(0);
});

test('impersonation cannot be nested', function () {
    [, $customer] = startSession();
    $another = User::factory()->withWorkspace()->create();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $another), ['reason' => reason()])
        ->assertNotFound();

    expect(Auth::id())->toBe($customer->id);
});

test('confirming their own password does not unlock the customer account', function () {
    startSession();

    // Support confirmed *their* password to start; that must not carry over.
    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
});

test('stopping hands the account back and closes the record', function () {
    [$staff] = startSession();

    $this->delete(route('support.impersonation.destroy'))
        ->assertRedirect(route('support.index'));

    expect(Auth::id())->toBe($staff->id)
        ->and(session()->has(impersonatorKey()))->toBeFalse();

    $session = ImpersonationSession::query()->sole();

    expect($session->ended_at)->not->toBeNull()
        ->and($session->ended_by)->toBe('support');
});

test('the stop is written to the activity log', function () {
    startSession();

    $this->delete(route('support.impersonation.destroy'));

    $events = Activity::query()->where('log_name', 'impersonation')->pluck('event');

    expect($events)->toContain('started')->toContain('stopped');
});

test('stopping without an impersonation is harmless', function () {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->delete(route('support.impersonation.destroy'))
        ->assertRedirect(route('dashboard'));

    expect(Auth::id())->toBe($customer->id);
});

test('the banner data is shared with every page', function () {
    [$staff, $customer] = startSession();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('impersonation.subject_name', $customer->name)
            ->where('impersonation.subject_email', $customer->email)
            ->where('impersonation.impersonator_name', $staff->name)
            ->where('impersonation.reason', reason())
            // A range, not an instant: the countdown is wall-clock, and a slow
            // run can legitimately cross a second between setup and assertion.
            ->where(
                'impersonation.remaining_seconds',
                fn (int $remaining): bool => $remaining > 1790 && $remaining <= 1800,
            )
        );
});

test('a normal session carries no banner', function () {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('impersonation', null));
});

test('writes made while impersonating are counted and logged', function () {
    [, $customer] = startSession();

    $this->put(route('onboarding.update'), [])->assertRedirect();

    $session = ImpersonationSession::query()->sole();

    expect($session->write_count)->toBe(1);

    $activity = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'write')
        ->sole();

    expect($activity->properties['route'])->toBe('onboarding.update')
        ->and($activity->properties['method'])->toBe('PUT')
        ->and($activity->subject_id)->toBe($customer->id);
});

test('ending the session is not counted as a change to the account', function () {
    startSession();

    $this->put(route('onboarding.update'), [])->assertRedirect();
    $this->delete(route('support.impersonation.destroy'));

    $session = ImpersonationSession::query()->sole();

    // The one write is the onboarding update, not the act of leaving.
    expect($session->write_count)->toBe(1);

    $routes = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'write')
        ->get()
        ->map(fn (Activity $activity): mixed => $activity->properties['route']);

    expect($routes)->not->toContain('support.impersonation.destroy');
});

test('reads are not counted as changes', function () {
    startSession();

    $this->get(route('dashboard'))->assertOk();

    expect(ImpersonationSession::query()->sole()->write_count)->toBe(0);
});

test('irreversible actions are refused and the attempt is recorded', function () {
    startSession();

    $this->post(route('billing.checkout'), [])
        ->assertRedirect()
        ->assertSessionHas('error');

    $session = ImpersonationSession::query()->sole();

    expect($session->blocked_count)->toBe(1)
        ->and($session->write_count)->toBe(0);

    $activity = Activity::query()
        ->where('log_name', 'impersonation')
        ->where('event', 'blocked')
        ->sole();

    expect($activity->properties['route'])->toBe('billing.checkout');
});

test('the customer security controls cannot be touched', function () {
    startSession();

    $this->delete(route('two-factor.disable'))->assertRedirect();
    $this->put(route('user-password.update'), [])->assertRedirect();

    expect(ImpersonationSession::query()->sole()->blocked_count)->toBe(2);
});

test('a blocked action answers JSON callers with 403', function () {
    startSession();

    $this->postJson(route('billing.checkout'), [])->assertForbidden();
});

test('the server ends a session that has run past its limit', function () {
    startSession();

    $session = ImpersonationSession::query()->sole();
    $session->forceFill(['started_at' => now()->subMinutes(31)])->save();

    $this->get(route('dashboard'))->assertRedirect(route('support.index'));

    expect($session->fresh()->ended_by)->toBe('expired')
        ->and(session()->has(impersonatorKey()))->toBeFalse();
});

test('the impersonator gets their own work block back, not a fresh one', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $customer = User::factory()->withWorkspace()->create();
    $startedAt = now()->getTimestamp() - 600;

    $this->actingAs($staff)
        ->withSession([
            'auth.password_confirmed_at' => time(),
            (string) config('work_session.started_at_key') => $startedAt,
        ])
        ->post(route('support.impersonation.store', $customer), ['reason' => reason()]);

    $this->delete(route('support.impersonation.destroy'));

    // Ten minutes already spent, so 15 of a 25-minute block remain.
    expect(session()->get((string) config('work_session.started_at_key')))->toBe($startedAt);
});

test('the work session does not sign support out mid-diagnosis', function () {
    $staff = User::factory()->supportStaff()->withWorkspace()->create();
    $customer = User::factory()->withWorkspace()->create(['work_session_minutes' => 5]);

    $this->actingAs($staff)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('support.impersonation.store', $customer), ['reason' => reason()]);

    // Well past the customer's own five-minute block: only the impersonation
    // clock should govern here.
    $this->withSession([
        (string) config('work_session.started_at_key') => now()->getTimestamp() - 9999,
    ])->get(route('dashboard'))->assertOk();
});

test('impersonation can be switched off entirely', function () {
    config(['impersonation.enabled' => false]);

    $staff = User::factory()->supportStaff()->withWorkspace()->create();

    $this->actingAs($staff)
        ->get(route('support.index'))
        ->assertNotFound();
});

test('support access is granted and revoked from the command line', function () {
    $user = User::factory()->create(['email' => 'tecnico@vapsolucoes.ao']);

    $this->artisan('support:staff', ['email' => 'tecnico@vapsolucoes.ao'])
        ->assertSuccessful();

    expect($user->fresh()->is_support_staff)->toBeTrue();

    $this->artisan('support:staff', ['email' => 'tecnico@vapsolucoes.ao', '--revoke' => true])
        ->assertSuccessful();

    expect($user->fresh()->is_support_staff)->toBeFalse();
});

test('granting support access to an unknown email fails loudly', function () {
    $this->artisan('support:staff', ['email' => 'ninguem@vapsolucoes.ao'])
        ->assertFailed();
});

test('support cannot trigger delivery recurring authority or fiscal transport actions', function (string $routeName, string $method) {
    startSession();
    $route = app('router')->getRoutes()->getByName($routeName);
    $parameters = [];
    foreach ($route->parameterNames() as $parameter) {
        $parameters[$parameter] = match ($parameter) {
            'fiscalDocument' => FiscalDocument::factory()->create(),
            'recurringInvoice' => RecurringInvoice::factory()->create([
                'workspace_id' => Workspace::factory(),
                'legal_entity_id' => LegalEntity::factory(),
                'establishment_id' => Establishment::factory(),
                'customer_id' => Customer::factory(),
                'created_by_user_id' => User::factory(),
            ]),
            'transportDocument' => TransportDocument::factory()->create(),
        };
    }

    $this->json($method, route($routeName, $parameters), [])->assertForbidden();

    expect(ImpersonationSession::query()->sole()->blocked_count)->toBe(1)
        ->and(Activity::query()->where('log_name', 'impersonation')->where('event', 'blocked')->sole()->properties['route'])->toBe($routeName);
})->with([
    ['invoices.send', 'POST'],
    ['recurring.store', 'POST'],
    ['recurring.update', 'PUT'],
    ['recurring.run', 'POST'],
    ['transport-documents.issue', 'POST'],
    ['transport-documents.cancel', 'POST'],
    ['agt.series.store', 'POST'],
]);
