<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\PushDevice;
use App\Models\User;
use App\Notifications\DailyCloseReady;
use App\Notifications\PushChannel;
use App\NotificationTopic;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function closeToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

function wantsDailyClose(User $user, string $channel = 'database'): User
{
    $user->forceFill(['notification_preferences' => ['daily_close' => [$channel => true]]])->save();

    return $user;
}

/**
 * A company with one till sale today (114,00 paid on the spot) and one invoice
 * from last month still owed (5 000,00, overdue).
 *
 * @return array<string, mixed>
 */
function closingCompany($test): array
{
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);

    $test->withToken(closeToken($company['owner']))->postJson(
        route('mobile.v1.till.sales.store', $session),
        posSalePayload([[$item, '1']], 11400, ['client_key' => (string) Str::uuid()]),
    )->assertCreated();
    app('auth')->forgetGuards();

    FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_name' => 'Mavinga & Filhos',
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT LOJA2026/1',
        'document_date' => now()->subDays(40)->toDateString(),
        'due_date' => now()->subDays(10)->toDateString(),
        'gross_total_minor' => 500_000,
        'net_total_minor' => 500_000,
        'issued_at' => now()->subDays(40),
    ]);

    return $company;
}

beforeEach(function (): void {
    $this->travelTo(now('Africa/Luanda')->setTime(19, 5));
});

it('tells owners and administrators who asked for it how the day closed, from Hoje\'s own figures', function (): void {
    $company = closingCompany($this);
    $owner = wantsDailyClose($company['owner']);
    $administrator = wantsDailyClose(posMember($company, WorkspaceRole::Administrator));
    $billing = wantsDailyClose(posMember($company, WorkspaceRole::Billing));
    $quietAdministrator = posMember($company, WorkspaceRole::Administrator);
    Notification::fake();

    $this->artisan('notifications:daily-close')->assertSuccessful();

    Notification::assertSentTo([$owner, $administrator], DailyCloseReady::class, function (DailyCloseReady $notification) use ($company): bool {
        return $notification->summary['billed_minor'] === 11400
            && $notification->summary['issued_count'] === 1
            && $notification->summary['received_minor'] === 11400
            && $notification->summary['outstanding_minor'] === 500_000
            && $notification->summary['overdue_minor'] === 500_000
            && $notification->workspace === $company['legalEntity']->workspace_id
            && $notification->body() === 'Vendeu 114 Kz · recebeu 114 Kz · faltam receber 5 000 Kz, 5 000 Kz em atraso.';
    });
    Notification::assertNotSentTo([$billing, $quietAdministrator], DailyCloseReady::class);
});

it('says it once a day, however often the hourly run comes round', function (): void {
    $company = closingCompany($this);
    $owner = wantsDailyClose($company['owner']);
    Notification::fake();

    $this->artisan('notifications:daily-close')->assertSuccessful();
    $this->artisan('notifications:daily-close')->assertSuccessful();
    $this->artisan('notifications:daily-close', ['--force' => true])->assertSuccessful();

    Notification::assertSentToTimes($owner, DailyCloseReady::class, 1);
});

it('waits for the company\'s closing hour', function (): void {
    $company = closingCompany($this);
    $owner = wantsDailyClose($company['owner']);
    Notification::fake();
    $this->travelTo(now('Africa/Luanda')->setTime(15, 0));

    $this->artisan('notifications:daily-close')->assertSuccessful();

    Notification::assertNothingSent();

    $this->artisan('notifications:daily-close', ['--force' => true])->assertSuccessful();

    Notification::assertSentToTimes($owner, DailyCloseReady::class, 1);
});

it('is off for everyone until they turn it on, and mail stays off', function (): void {
    $company = closingCompany($this);
    Notification::fake();

    $this->artisan('notifications:daily-close')->assertSuccessful();

    Notification::assertNothingSent();
    expect($company['owner']->notificationChannels(NotificationTopic::DailyClose))->toBe([]);
});

it('goes by mail only to someone who chose mail', function (): void {
    $company = closingCompany($this);
    $owner = wantsDailyClose($company['owner'], 'mail');

    expect($owner->notificationChannels(NotificationTopic::DailyClose))->toBe(['mail']);
});

it('buzzes the phone of someone who turned it on there, with the figures in the push', function (): void {
    $company = closingCompany($this);
    $owner = wantsDailyClose($company['owner'], 'push');
    config([
        'services.apns.key_id' => 'KEY1234567',
        'services.apns.team_id' => 'TEAM123456',
        'services.apns.private_key' => 'test-key',
    ]);
    $token = $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY], now()->addDays(30));
    PushDevice::factory()->for($owner)->create(['personal_access_token_id' => $token->accessToken->getKey()]);
    Notification::fake();

    $this->artisan('notifications:daily-close')->assertSuccessful();

    Notification::assertSentTo($owner, DailyCloseReady::class, function (DailyCloseReady $notification, array $channels) use ($owner): bool {
        $push = $notification->toPush($owner);

        expect($channels)->toBe([PushChannel::class]);

        return $push['topic'] === 'daily_close'
            && $push['aps']['alert']['title'] === 'Fecho do dia'
            && $push['aps']['alert']['body'] === "Vendas: 114 Kz · 1 documento\nRecebido: 114 Kz\nPor receber: 5 000 Kz (5 000 Kz em atraso)"
            && $push['daily_close']['received_minor'] === 11400
            && $push['daily_close']['date'] === now()->toDateString();
    });
});

it('offers the daily close in the phone\'s settings only to those who run a company', function (): void {
    $company = posCompany();
    $billing = posMember($company, WorkspaceRole::Billing);

    $keys = fn (User $user): array => array_column(
        $this->withToken(closeToken($user))->getJson(route('mobile.v1.notification-preferences.show'))->assertOk()->json('data.topics'),
        'key',
    );

    expect($keys($company['owner']))->toContain('daily_close');
    app('auth')->forgetGuards();
    expect($keys($billing))->not->toContain('daily_close');
});

it('gives Hoje and its widget the money received today', function (): void {
    $company = closingCompany($this);

    $this->withToken(closeToken($company['owner']))->getJson(route('mobile.v1.today'))
        ->assertOk()
        ->assertJsonPath('data.sales_today.billed_minor', 11400)
        ->assertJsonPath('data.sales_today.received_minor', 11400)
        ->assertJsonPath('data.receivables.outstanding_minor', 500_000);
});
