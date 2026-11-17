<?php

use App\Models\User;
use App\Notifications\SubscriptionActivated;
use App\Notifications\WelcomeToFacturac;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('transactional emails render the Facturac identity in HTML and plain text', function (string $kind) {
    $user = User::factory()->unverified()->create();
    $notification = match ($kind) {
        'verification' => new VerifyEmail,
        'reset' => new ResetPassword('synthetic-reset-token'),
        'welcome' => new WelcomeToFacturac,
        'subscription' => new SubscriptionActivated('sub-demo', 'Avançado', '2026-12-17', 'pay-demo'),
    };
    $message = $notification->toMail($user);
    $markdown = app(Markdown::class);
    $html = (string) $markdown->render($message->markdown, $message->data());
    $text = (string) $markdown->renderText($message->markdown, $message->data());

    expect($html)->toContain('Facturação electrónica para Angola', '#f9b233', 'images/mail/wordmark.png', 'A equipa facturac.ao', 'mailto:geral@vapsolucoes.ao', 'Se o botão')
        ->not->toContain('laravel.com/img', 'Hello!', 'Regards,', 'All rights reserved')
        ->and($text)->toContain('facturac.ao', 'geral@vapsolucoes.ao', $message->actionUrl)
        ->not->toContain('Hello!', 'Regards,', 'All rights reserved');
})->with(['verification', 'reset', 'welcome', 'subscription']);

test('verification keeps a signed expiring link and password reset keeps its token and email', function () {
    $user = User::factory()->unverified()->create();
    $verification = (new VerifyEmail)->toMail($user);
    expect(Request::create($verification->actionUrl)->hasValidSignature())->toBeTrue()
        ->and($verification->subject)->toBe('Confirme o seu email — facturac.ao');
    $this->travel(61)->minutes();
    expect(Request::create($verification->actionUrl)->hasValidSignature())->toBeFalse();

    $reset = (new ResetPassword('synthetic-reset-token'))->toMail($user);
    expect($reset->actionUrl)->toBe(route('password.reset', ['token' => 'synthetic-reset-token', 'email' => $user->email]))
        ->and($reset->subject)->toBe('Repor a palavra-passe — facturac.ao');
});

test('welcome is queued after commit and replies reach the monitored inbox', function () {
    config(['mail.default' => 'array']);
    $notification = new WelcomeToFacturac;
    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->afterCommit)->toBeTrue();

    app(MailChannel::class)->send(User::factory()->create(), $notification);
    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
    expect($email->getReplyTo()[0]->getAddress())->toBe('geral@vapsolucoes.ao')
        ->and($email->getSubject())->toBe('Boas-vindas ao facturac.ao')
        ->and($email->getHtmlBody())->toContain(route('onboarding'), route('settings.security'));
});

test('registration waits for verification and repeated verification does not repeat the welcome', function () {
    Notification::fake();
    $this->post(route('register.store'), [
        'name' => 'Maria José', 'email' => 'welcome@example.com', 'workspace_name' => 'Empresa de exemplo',
        'password' => 'StrongPassword!2026', 'password_confirmation' => 'StrongPassword!2026',
    ])->assertRedirect();
    $user = User::query()->where('email', 'welcome@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
    Notification::assertNotSentTo($user, WelcomeToFacturac::class);
    $url = (new VerifyEmail)->toMail($user)->actionUrl;
    $this->get($url)->assertRedirect();
    $this->get($url)->assertRedirect();
    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentToTimes($user, WelcomeToFacturac::class, 1);
});

test('Google welcomes newly verified users but not returning verified users', function (bool $alreadyVerified) {
    Notification::fake();
    config([
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'https://vap.test/auth/google/callback',
    ]);
    $user = User::factory()->create(['email_verified_at' => $alreadyVerified ? now() : null]);
    Socialite::fake('google', SocialiteUser::fake([
        'id' => 'google-welcome', 'email' => $user->email, 'email_verified' => true,
    ]));
    $this->get(route('social.google.callback'))->assertRedirect();
    $this->get(route('social.google.callback'))->assertRedirect();
    Notification::assertSentToTimes($user, WelcomeToFacturac::class, $alreadyVerified ? 0 : 1);
})->with([true, false]);
