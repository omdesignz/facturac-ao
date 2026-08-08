<?php

use App\Models\User;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\Features;

test('the passkeys feature is enabled and requires password confirmation', function () {
    expect(Features::enabled(Features::passkeys()))->toBeTrue()
        ->and(Features::optionEnabled(Features::passkeys(), 'confirmPassword'))->toBeTrue();
});

test('the user model satisfies the passkey contract', function () {
    $user = User::factory()->withWorkspace()->create();

    expect($user)->toBeInstanceOf(PasskeyUser::class)
        ->and($user->passkeys()->count())->toBe(0);
});

test('passkeys are scoped to their owner', function () {
    $owner = User::factory()->withWorkspace()->create();
    $other = User::factory()->withWorkspace()->create();

    $owner->passkeys()->create([
        'name' => 'MacBook do Manuel',
        'credential_id' => 'credential-owner',
        'credential' => ['id' => 'credential-owner'],
    ]);

    expect($owner->passkeys()->count())->toBe(1)
        ->and($other->passkeys()->count())->toBe(0);
});

test('the security page lists the passkeys belonging to the signed in user', function () {
    $user = User::factory()->withWorkspace()->create();

    $user->passkeys()->create([
        'name' => 'iPhone da Ana',
        'credential_id' => 'credential-ana',
        'credential' => ['id' => 'credential-ana'],
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Settings/Security')
            ->has('passkeys', 1)
            ->where('passkeys.0.name', 'iPhone da Ana')
            ->missing('passkeys.0.credential')
            ->missing('passkeys.0.credential_id')
        );
});

test('the passkey registration and login endpoints are routable', function () {
    expect(route('passkey.registration-options'))->toEndWith('/user/passkeys/options')
        ->and(route('passkey.store'))->toEndWith('/user/passkeys')
        ->and(route('passkey.login-options'))->toEndWith('/passkeys/login/options')
        ->and(route('passkey.login'))->toEndWith('/passkeys/login');
});

test('registering a passkey requires an authenticated user', function () {
    $this->getJson(route('passkey.registration-options'))
        ->assertUnauthorized();
});

test('a passkey cannot be deleted by a different user', function () {
    $owner = User::factory()->withWorkspace()->create();
    $attacker = User::factory()->withWorkspace()->create();

    $passkey = $owner->passkeys()->create([
        'name' => 'Chave do proprietário',
        'credential_id' => 'credential-protected',
        'credential' => ['id' => 'credential-protected'],
    ]);

    $this->actingAs($attacker)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('passkey.destroy', $passkey));

    expect($owner->passkeys()->whereKey($passkey->getKey())->exists())->toBeTrue();
});
