<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\SignInMobileDevice;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Activitylog\Models\Activity;

function mobileSignIn(User $user, array $overrides = []): array
{
    return [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'iPhone de Ana',
        ...$overrides,
    ];
}

function userWithTwoFactor(): User
{
    return User::factory()->withWorkspace()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('mobile-totp-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

test('a device signs in and gets a token that expires in thirty days', function () {
    $user = User::factory()->withWorkspace()->create();

    $response = $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user))
        ->assertCreated()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    $token = PersonalAccessToken::findToken($response->json('data.token'));

    expect($token->tokenable->is($user))->toBeTrue()
        ->and($token->name)->toBe('iPhone de Ana')
        ->and($token->abilities)->toBe([SignInMobileDevice::TOKEN_ABILITY])
        ->and($token->expires_at->isSameDay(now()->addDays(30)))->toBeTrue();

    $audit = Activity::query()->where('event', 'mobile-session-created')->sole();

    expect($audit->log_name)->toBe('security')
        ->and(json_encode($audit->properties))->not->toContain($response->json('data.token'));
});

test('a wrong password and an unknown address get the same answer', function () {
    $user = User::factory()->withWorkspace()->create();

    $wrongPassword = $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['password' => 'wrong']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $unknown = $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['email' => 'ninguem@example.com']))
        ->assertUnprocessable();

    expect($unknown->json())->toBe($wrongPassword->json())
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

test('an unverified address gets no token', function () {
    $user = User::factory()->unverified()->withWorkspace()->create();

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('sign-in is rate limited by the same login limiter as the web', function () {
    $user = User::factory()->withWorkspace()->create();

    foreach (range(1, 5) as $attempt) {
        $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['password' => 'wrong']))->assertUnprocessable();
    }

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user))->assertTooManyRequests();
});

test('two-step accounts are asked for a code only after the right password', function () {
    $user = userWithTwoFactor();

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['password' => 'wrong']))
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED');

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

test('a two-step code must verify before a token is given', function () {
    $user = userWithTwoFactor();
    $provider = $this->mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->with('mobile-totp-secret', '000000')->once()->andReturnFalse();
    $provider->shouldReceive('verify')->with('mobile-totp-secret', '123456')->once()->andReturnTrue();

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['code' => '000000']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_TWO_FACTOR_CODE');

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['code' => '123456']))
        ->assertCreated();
});

test('a recovery code works once', function () {
    $user = userWithTwoFactor();

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['recovery_code' => 'recovery-one']))
        ->assertCreated();

    $this->postJson(route('mobile.v1.sessions.store'), mobileSignIn($user, ['recovery_code' => 'recovery-one']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'INVALID_TWO_FACTOR_CODE');

    expect($user->refresh()->recoveryCodes())->not->toContain('recovery-one');
});

test('the token reads the profile and active workspaces', function () {
    $user = User::factory()->withWorkspace('Kwanza Mercantil')->create();
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;

    $this->withToken($token)->getJson(route('mobile.v1.me'))
        ->assertOk()
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonPath('data.two_factor_enabled', false)
        ->assertJsonPath('data.workspaces.0.name', 'Kwanza Mercantil');
});

test('mobile routes refuse no token, a token without the ability and an expired token', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->getJson(route('mobile.v1.me'))->assertUnauthorized();

    $this->withToken($user->createToken('other', ['something-else'])->plainTextToken)
        ->getJson(route('mobile.v1.me'))->assertForbidden();

    $this->app['auth']->forgetGuards();

    $this->withToken($user->createToken('old', [SignInMobileDevice::TOKEN_ABILITY], now()->subMinute())->plainTextToken)
        ->getJson(route('mobile.v1.me'))->assertUnauthorized();
});

test('a web session does not open the mobile routes', function () {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user, 'web')->getJson(route('mobile.v1.me'))->assertUnauthorized();
});

test('a token does not open the cookie-based api', function () {
    $user = User::factory()->withWorkspace()->create();
    $token = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;

    $this->withToken($token)
        ->getJson('/api/v1/workspaces/x/legal-entities/y/environments/sandbox/documents')
        ->assertUnauthorized();
});

test('a device lists sessions and signs out another device or itself', function () {
    $user = User::factory()->withWorkspace()->create();
    $current = $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY]);
    $other = $user->createToken('iPad', [SignInMobileDevice::TOKEN_ABILITY]);

    $this->withToken($current->plainTextToken)->getJson(route('mobile.v1.sessions.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $this->withToken($current->plainTextToken)
        ->deleteJson(route('mobile.v1.sessions.destroy', $other->accessToken->id))
        ->assertNoContent();

    expect(PersonalAccessToken::find($other->accessToken->id))->toBeNull();

    $this->withToken($current->plainTextToken)
        ->deleteJson(route('mobile.v1.sessions.destroy-current'))
        ->assertNoContent();

    expect(PersonalAccessToken::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'mobile-session-revoked')->count())->toBe(2);
});

test('nobody can sign out a device of another person', function () {
    $user = User::factory()->withWorkspace()->create();
    $stranger = User::factory()->withWorkspace()->create();
    $strangerToken = $stranger->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY]);

    $this->withToken($user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken)
        ->deleteJson(route('mobile.v1.sessions.destroy', $strangerToken->accessToken->id))
        ->assertNotFound();

    expect(PersonalAccessToken::find($strangerToken->accessToken->id))->not->toBeNull();
});

test('changing or resetting the password signs out every device', function () {
    $user = User::factory()->withWorkspace()->create();
    $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY]);

    $this->actingAs($user);
    app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'password',
        'password' => 'novA-senha-2026!x',
        'password_confirmation' => 'novA-senha-2026!x',
    ]);

    expect($user->tokens()->count())->toBe(0);

    $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY]);

    app(ResetUserPassword::class)->reset($user, [
        'password' => 'outrA-senha-2026!y',
        'password_confirmation' => 'outrA-senha-2026!y',
    ]);

    expect($user->tokens()->count())->toBe(0)
        ->and(Hash::check('outrA-senha-2026!y', $user->refresh()->password))->toBeTrue();
});
