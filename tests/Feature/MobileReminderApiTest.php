<?php

use App\Actions\PreparePaymentReminder;
use App\Actions\SignInMobileDevice;
use App\Analytics\DashboardQuery;
use App\Analytics\ReminderHistory;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Notifications\PaymentReminder;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{owner: User, token: string, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function reminderCompanyForPhone(?string $email = 'contas@mavinga.ao'): array
{
    $owner = User::factory()->withWorkspace('Kwanza Mercantil')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Mavinga & Filhos',
        'email' => $email,
    ]);

    return [
        'owner' => $owner,
        'token' => $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'legalEntity' => $legalEntity,
        'establishment' => $establishment,
        'customer' => $customer,
    ];
}

/**
 * @param  array{owner: User, token: string, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}  $company
 */
function reminderInvoiceForPhone(array $company, int $daysLate, int $grossMinor = 1_000_000): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => $company['customer']->id,
        'customer_name' => $company['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(1, 9999),
        'document_date' => now()->subDays($daysLate + 30)->toDateString(),
        'due_date' => now()->subDays($daysLate)->toDateString(),
        'gross_total_minor' => $grossMinor,
    ]);
}

/**
 * @return array{subject: string, body: string, client_key: string}
 */
function reminderWords(?string $clientKey = null): array
{
    return [
        'subject' => 'Lembrete de pagamento',
        'body' => 'Bom dia, segue o lembrete de pagamento.',
        'client_key' => $clientKey ?? (string) Str::uuid(),
    ];
}

beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

test('the draft reaches the phone and nothing is sent', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);

    $this->withToken($company['token'])
        ->getJson(route('mobile.v1.reminders.show', $company['customer']->public_id))
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Mavinga & Filhos')
        ->assertJsonPath('data.to', 'contas@mavinga.ao')
        ->assertJsonPath('data.tone', 'cordial')
        ->assertJsonPath('data.total_minor', 1_000_000)
        ->assertJsonPath('data.last_sent_at', null);

    Notification::assertNothingSent();
});

test('confirming sends the approved words and records who sent them', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    $words = reminderWords();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), $words)
        ->assertCreated()
        ->assertJsonPath('data.to', 'contas@mavinga.ao')
        ->assertJsonPath('data.replayed', false);

    Notification::assertSentOnDemand(
        PaymentReminder::class,
        fn (PaymentReminder $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'contas@mavinga.ao'
            && $notification->subject === $words['subject']
            && $notification->body === $words['body'],
    );

    $activity = Activity::query()->where('event', PreparePaymentReminder::EVENT)->firstOrFail();

    expect($activity->causer_id)->toBe($company['owner']->id)
        ->and($activity->properties['client_key'])->toBe($words['client_key']);
});

test('a retry with the same key answers with the first result and sends nothing more', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    $words = reminderWords();
    $url = route('mobile.v1.reminders.store', $company['customer']->public_id);

    $first = $this->withToken($company['token'])->postJson($url, $words)->assertCreated();

    // A day later, past the one-minute guard, and with a send now impossible.
    $this->travel(1)->days();
    Cache::flush();
    $company['customer']->update(['email' => null]);

    $this->withToken($company['token'])->postJson($url, $words)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.to', 'contas@mavinga.ao')
        ->assertJsonPath('data.sent_at', $first->json('data.sent_at'));

    Notification::assertSentOnDemandTimes(PaymentReminder::class, 1);
});

test('the same words under a new key within a minute are refused', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    $url = route('mobile.v1.reminders.store', $company['customer']->public_id);

    $this->withToken($company['token'])->postJson($url, reminderWords())->assertCreated();

    $this->withToken($company['token'])->postJson($url, reminderWords())
        ->assertConflict()
        ->assertJsonPath('error.code', 'REMINDER_REFUSED');

    Notification::assertSentOnDemandTimes(PaymentReminder::class, 1);
});

test('a key cannot replay a reminder sent to another customer', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    $words = reminderWords();

    $other = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'email' => 'geral@kizomba.ao',
    ]);
    reminderInvoiceForPhone([...$company, 'customer' => $other], 5);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), $words)
        ->assertCreated();

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $other->public_id), $words)
        ->assertCreated()
        ->assertJsonPath('data.to', 'geral@kizomba.ao');

    Notification::assertSentOnDemandTimes(PaymentReminder::class, 2);
});

test('a debt no longer late is refused by name', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, -3);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertConflict()
        ->assertJsonPath('error.code', 'REMINDER_REFUSED')
        ->assertJsonPath('error.message', 'Este cliente já não tem nada vencido. Actualize a lista.');

    Notification::assertNothingSent();
});

test('a customer with no email is refused by name', function () {
    $company = reminderCompanyForPhone(email: null);
    reminderInvoiceForPhone($company, 5);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertConflict()
        ->assertJsonPath('error.code', 'REMINDER_REFUSED');

    Notification::assertNothingSent();
});

test('a viewer can neither read the draft nor send', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    WorkspaceMembership::query()->where('user_id', $company['owner']->id)->update(['role' => WorkspaceRole::Viewer]);

    $this->withToken($company['token'])
        ->getJson(route('mobile.v1.reminders.show', $company['customer']->public_id))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'REMINDER_NOT_ALLOWED');

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertForbidden()
        ->assertJsonPath('error.code', 'REMINDER_NOT_ALLOWED');

    Notification::assertNothingSent();
});

test('another company\'s customer is not found', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);
    $stranger = reminderCompanyForPhone();

    $this->withToken($stranger['token'])
        ->getJson(route('mobile.v1.reminders.show', $company['customer']->public_id))
        ->assertNotFound();

    $this->withToken($stranger['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertNotFound();

    Notification::assertNothingSent();
});

test('the words and the client key are required', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), [
            'subject' => '',
            'body' => 'curto',
            'client_key' => 'not-a-uuid',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['subject', 'body', 'client_key']);

    Notification::assertNothingSent();
});

test('reminders need a token', function () {
    $company = reminderCompanyForPhone();

    $this->getJson(route('mobile.v1.reminders.show', $company['customer']->public_id))->assertUnauthorized();
    $this->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())->assertUnauthorized();
});

test('cobrar says when each customer was last reminded', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);

    $this->withToken($company['token'])->getJson(route('mobile.v1.collections'))
        ->assertJsonPath('data.customers.0.last_reminder_sent_at', null);

    $receipt = $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertCreated();

    $this->withToken($company['token'])->getJson(route('mobile.v1.collections'))
        ->assertJsonPath('data.customers.0.last_reminder_sent_at', $receipt->json('data.sent_at'));
});

test('someone just reminded leaves hoje for a week, and the next customer takes their place', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 40, 2_000_000);

    $next = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cooperativa Kizomba',
    ]);
    reminderInvoiceForPhone([...$company, 'customer' => $next], 5);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.decisions.0.customer.name', 'Mavinga & Filhos');

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertCreated();

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.decisions.0.customer.name', 'Cooperativa Kizomba')
        ->assertJsonCount(2, 'data.debtors');

    expect(app(DashboardQuery::class)->collections($company['legalEntity'])['customers'][0]['recently_reminded'])->toBeTrue();

    $this->travel(ReminderHistory::QUIET_DAYS)->days();
    $this->travel(1)->minutes();

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.decisions.0.customer.name', 'Mavinga & Filhos');
});

test('a reminded customer comes back early when they owe more late', function () {
    $company = reminderCompanyForPhone();
    reminderInvoiceForPhone($company, 5);

    $this->withToken($company['token'])
        ->postJson(route('mobile.v1.reminders.store', $company['customer']->public_id), reminderWords())
        ->assertCreated();

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.decisions', []);

    reminderInvoiceForPhone($company, 2, 500_000);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.decisions.0.customer.name', 'Mavinga & Filhos')
        ->assertJsonPath('data.decisions.0.amount_minor', 1_500_000);
});
