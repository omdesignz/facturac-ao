<?php

use App\Models\SocialIdentity;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config()->set([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'https://vap.test/auth/google/callback',
    ]);
});

test('Google login is unavailable until credentials are configured', function () {
    config()->set([
        'services.google.client_id' => null,
        'services.google.client_secret' => null,
        'services.google.redirect' => null,
    ]);

    $this->get(route('social.google.redirect'))->assertNotFound();
});

test('Google redirect uses the official Socialite driver', function () {
    Socialite::fake('google');

    $this->get(route('social.google.redirect'))
        ->assertRedirect('https://socialite.fake/google/authorize');
});

test('a verified Google identity creates a verified user without persisting OAuth tokens', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-user-100',
        'name' => 'Joana Neto',
        'email' => 'JOANA@EXAMPLE.COM',
        'email_verified' => true,
        'token' => 'must-never-be-stored',
        'refreshToken' => 'also-must-never-be-stored',
    ]));

    $this->get(route('social.google.callback'))
        ->assertRedirect(route('workspace.setup'));

    $user = User::query()->where('email', 'joana@example.com')->firstOrFail();
    $identity = $user->socialIdentities()->firstOrFail();

    $this->assertAuthenticatedAs($user);
    expect($user->hasVerifiedEmail())->toBeTrue()
        ->and($identity->provider)->toBe('google')
        ->and($identity->provider_user_id)->toBe('google-user-100')
        ->and(Schema::hasColumn('social_identities', 'token'))->toBeFalse()
        ->and(Schema::hasColumn('social_identities', 'refresh_token'))->toBeFalse()
        ->and(json_encode($identity->getAttributes()))->not->toContain('must-never-be-stored')
        ->and(Activity::query()->where('event', 'social-login')->exists())->toBeTrue();
});

test('a verified Google email links to the existing local account', function () {
    $user = User::factory()->withWorkspace()->create([
        'email' => 'existing@example.com',
    ]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-existing-100',
        'email' => 'existing@example.com',
        'email_verified' => true,
    ]));

    $this->get(route('social.google.callback'))
        ->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->where('email', 'existing@example.com')->count())->toBe(1)
        ->and($user->socialIdentities()->where('provider_user_id', 'google-existing-100')->exists())->toBeTrue();
});

test('an unverified provider email is rejected', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-unverified-100',
        'email' => 'unverified@example.com',
        'email_verified' => false,
    ]));

    $this->get(route('social.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect(User::query()->where('email', 'unverified@example.com')->doesntExist())->toBeTrue();
});

test('a provider response without a stable identity is rejected', function () {
    Socialite::fake('google', SocialiteUser::fake([
        'id' => '',
        'email' => 'missing-id@example.com',
        'email_verified' => true,
    ]));

    $this->get(route('social.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect(User::query()->where('email', 'missing-id@example.com')->doesntExist())->toBeTrue();
});

test('an existing Google connection cannot be silently replaced by another identity', function () {
    $user = User::factory()->withWorkspace()->create([
        'email' => 'protected@example.com',
    ]);
    SocialIdentity::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'original-google-id',
        'provider_email' => $user->email,
    ]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'different-google-id',
        'email' => $user->email,
        'email_verified' => true,
    ]));

    $this->get(route('social.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
    expect($user->socialIdentities()->count())->toBe(1);
});
