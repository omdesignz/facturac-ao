<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function sessionKey(): string
{
    return (string) config('work_session.started_at_key');
}

test('a work session defaults to 25 minutes', function () {
    expect(config('work_session.default_minutes'))->toBe(25);

    $user = User::factory()->withWorkspace()->create();

    expect($user->work_session_minutes)->toBe(25);
});

test('signing in starts a fresh block', function () {
    $user = User::factory()->withWorkspace()->create([
        'email' => 'bloco@vap.ao',
        'password' => Hash::make('VapInvoice!2026'),
    ]);

    $this->post(route('login.store'), [
        'email' => 'bloco@vap.ao',
        'password' => 'VapInvoice!2026',
    ])->assertRedirect();

    expect(session()->get(sessionKey()))->toBeInt();
});

test('the remaining time is reported by the server, not the browser', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 600])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('workSession', fn (Assert $session) => $session
                ->where('enabled', true)
                ->where('total_seconds', 1500)
                // 25 minutes less the 10 already spent.
                ->where('remaining_seconds', 900)
                ->where('warning_seconds', 60)
            )
        );
});

test('refreshing the page does not reset the countdown', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);
    $startedAt = now()->getTimestamp() - 1200;

    $first = $this->actingAs($user)
        ->withSession([sessionKey() => $startedAt])
        ->get(route('dashboard'));

    $second = $this->actingAs($user)
        ->withSession([sessionKey() => $startedAt])
        ->get(route('dashboard'));

    $remaining = fn ($response): int => $response
        ->viewData('page')['props']['workSession']['remaining_seconds'];

    // The guarantee is that the second read does not hand back a fresh block.
    // Pinning an exact second instead would fail on a slow run for the very
    // reason the feature works: the clock keeps running rather than restarting.
    expect($remaining($first))->toBeLessThanOrEqual(300)
        ->and($remaining($first))->toBeGreaterThan(290)
        ->and($remaining($second))->toBeLessThanOrEqual($remaining($first))
        ->and($remaining($second))->toBeGreaterThan(290);
});

test('an expired block is ended by the server', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 1501])
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('a block that has not expired is left alone', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 1499])
        ->get(route('dashboard'))
        ->assertOk();
});

test('an expired block returns 401 to a JSON caller rather than a redirect', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 3000])
        ->getJson(route('dashboard'))
        ->assertUnauthorized();
});

test('a session predating the feature adopts the current moment', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();

    expect(session()->get(sessionKey()))->toBeInt();
});

test('renewing starts a new block', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);
    $stale = now()->getTimestamp() - 1400;

    // An Inertia visit, not postJson: the button calls router.post, so a plain
    // JSON body here is a response Inertia cannot read — it showed the payload
    // to the user in a debug modal instead of applying it.
    $this->actingAs($user)
        ->withSession([sessionKey() => $stale])
        ->post(route('session.renew'), [], [
            'X-Inertia' => 'true',
            'Referer' => route('dashboard'),
        ])
        ->assertRedirect(route('dashboard'));

    expect(session()->get(sessionKey()))->toBeGreaterThan($stale);
});

test('the page a renewal lands on reports the fresh countdown', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 1400])
        ->post(route('session.renew'), [], [
            'X-Inertia' => 'true',
            'Referer' => route('dashboard'),
        ]);

    // The client takes the new window from this prop rather than guessing, so
    // the redirect target has to carry a full block, not the 100 seconds that
    // were left before.
    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workSession.total_seconds', 1500)
            ->where(
                'workSession.remaining_seconds',
                fn (int $remaining): bool => $remaining > 1490 && $remaining <= 1500,
            )
        );
});

test('a user can change how long their sessions last', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('settings.work-session.update'), ['work_session_minutes' => 90])
        ->assertRedirect();

    expect($user->fresh()->work_session_minutes)->toBe(90);
});

test('the chosen length is bounded', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->put(route('settings.work-session.update'), ['work_session_minutes' => 1])
        ->assertSessionHasErrors('work_session_minutes');

    $this->actingAs($user)
        ->put(route('settings.work-session.update'), ['work_session_minutes' => 100000])
        ->assertSessionHasErrors('work_session_minutes');
});

test('changing the length restarts the block immediately', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);
    $stale = now()->getTimestamp() - 1400;

    $this->actingAs($user)
        ->withSession([sessionKey() => $stale])
        ->put(route('settings.work-session.update'), ['work_session_minutes' => 45]);

    expect(session()->get(sessionKey()))->toBeGreaterThan($stale);
});

test('the security page exposes the current preference', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 45]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('workSessionPreference.minutes', 45)
            ->where('workSessionPreference.min_minutes', 5)
        );
});

test('the security page does not shadow the running countdown', function () {
    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 45]);

    $this->actingAs($user)
        ->withSession([
            sessionKey() => now()->getTimestamp() - 600,
            'auth.password_confirmed_at' => time(),
        ])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The shared countdown and the page's own settings must not collide:
            // when they did, the timer read a missing remaining_seconds as zero
            // and signed the user out on arrival.
            ->where('workSession.remaining_seconds', 2100)
            ->where('workSession.total_seconds', 2700)
            ->where('workSessionPreference.minutes', 45)
        );
});

test('a guest gets no work session props', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('workSession', null));
});

test('the feature can be switched off', function () {
    config(['work_session.enabled' => false]);

    $user = User::factory()->withWorkspace()->create(['work_session_minutes' => 25]);

    $this->actingAs($user)
        ->withSession([sessionKey() => now()->getTimestamp() - 99999])
        ->get(route('dashboard'))
        ->assertOk();
});
