<?php

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Spatie\Activitylog\Models\Activity;

test('the security page requires a recent password confirmation', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('settings.security'))
        ->assertRedirect(route('password.confirm'));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk();
});

test('a user can enable and confirm MFA and each change is audited without secrets', function () {
    $user = User::factory()->withWorkspace()->create();
    $provider = $this->mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('generateSecretKey')
        ->once()
        ->andReturn('phase-one-totp-secret');
    $provider->shouldReceive('verify')
        ->once()
        ->with('phase-one-totp-secret', '123456')
        ->andReturnTrue();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.enable'))
        ->assertSuccessful();

    $user->refresh();

    expect($user->two_factor_secret)->not->toBeNull()
        ->and($user->two_factor_secret)->not->toContain('phase-one-totp-secret')
        ->and(Fortify::currentEncrypter()->decrypt($user->two_factor_secret))->toBe('phase-one-totp-secret')
        ->and($user->two_factor_recovery_codes)->not->toBeNull()
        ->and(Activity::query()->where('event', 'mfa-enabled')->exists())->toBeTrue();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->postJson(route('two-factor.confirm'), ['code' => '123456'])
        ->assertSuccessful();

    expect($user->refresh()->hasEnabledTwoFactorAuthentication())->toBeTrue()
        ->and(Activity::query()->where('event', 'mfa-confirmed')->exists())->toBeTrue();

    $securityActivities = Activity::query()
        ->where('log_name', 'security')
        ->get();

    expect($securityActivities)->toHaveCount(2)
        ->and($securityActivities->pluck('properties')->flatten()->implode(' '))
        ->not->toContain('phase-one-totp-secret')
        ->not->toContain('123456');
});

test('a confirmed MFA user is challenged on the next password login', function () {
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('challenge-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

test('disabling MFA clears credentials and records an audit event', function () {
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill([
        'two_factor_secret' => Crypt::encryptString('disable-secret'),
        'two_factor_recovery_codes' => Crypt::encryptString(json_encode(['one-code'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson(route('two-factor.disable'))
        ->assertSuccessful();

    $user->refresh();

    expect($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_recovery_codes)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(Activity::query()->where('event', 'mfa-disabled')->exists())->toBeTrue();
});
