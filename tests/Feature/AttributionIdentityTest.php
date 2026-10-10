<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

test('attribution is generated once and hidden independently of human authentication identity', function () {
    $user = User::factory()->create();
    $identity = $user->attribution_id;
    expect($identity)->toMatch('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/')
        ->and($user->toArray())->not->toHaveKey('attribution_id')->and($user->getAuthIdentifier())->toBe($user->id);
    $user->update(['name' => 'Renamed', 'email' => 'changed@example.com']);
    expect($user->fresh()->attribution_id)->toBe($identity);
    expect(fn () => $user->forceFill(['attribution_id' => (string) Str::uuid()])->save())->toThrow(DomainException::class);
});

test('raw updates cannot mutate a human attribution identity', function () {
    $user = User::factory()->create();
    expect(fn () => DB::table('users')->where('id', $user->id)->update(['attribution_id' => (string) Str::uuid()]))->toThrow(QueryException::class);
});

test('new user attribution does not accept a client supplied identity', function () {
    $supplied = (string) Str::uuid();
    $user = User::factory()->create(['attribution_id' => $supplied]);
    expect($user->attribution_id)->not->toBe($supplied);
});

test('registration and Google creation or linking generate or retain attribution exactly once', function () {
    $registered = app(CreateNewUser::class)->create(['name' => 'Identity', 'email' => 'identity@example.com', 'workspace_name' => 'Identity Company', 'password' => 'password-password', 'password_confirmation' => 'password-password']);
    $identity = $registered->attribution_id;
    config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test', 'services.google.redirect' => 'https://localhost/auth/google/callback']);
    Socialite::fake('google', Laravel\Socialite\Two\User::fake(['id' => 'identity-link', 'email' => $registered->email, 'email_verified' => true]));
    $this->get(route('social.google.callback'))->assertRedirect();
    expect($registered->fresh()->attribution_id)->toBe($identity);
    $this->post(route('logout'));
    Socialite::fake('google', Laravel\Socialite\Two\User::fake(['id' => 'identity-new', 'email' => 'new-identity@example.com', 'email_verified' => true]));
    $this->get(route('social.google.callback'))->assertRedirect();
    expect(User::where('email', 'new-identity@example.com')->firstOrFail()->attribution_id)->not->toBe($identity)->toMatch('/^[a-f0-9-]{36}$/');
});

test('event-suppressed insertion still generates an opaque attribution identity and integer primary key', function () {
    $user = User::withoutEvents(fn () => User::factory()->create());
    expect($user->attribution_id)->toMatch('/^[a-f0-9-]{36}$/')->and($user->id)->toBeInt()->and($user->getKeyType())->toBe('int');
});
