<?php

use App\Actions\PreparePaymentReminder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\PaymentReminder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function reminderFixture(?string $email = 'contas@mavinga.ao'): array
{
    $owner = User::factory()->withWorkspace('VAP Lembretes')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Lembretes, Lda.',
        'trade_name' => null,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Mavinga & Filhos, Lda.',
        'email' => $email,
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'customer');
}

/**
 * @param  array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}  $fixture
 */
function reminderInvoice(array $fixture, string $dueDate, int $grossMinor, ?string $number = null): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => $number ?? 'FT 2026/'.fake()->unique()->numberBetween(1, 9999),
        'document_date' => now()->subDays(40)->toDateString(),
        'due_date' => $dueDate,
        'gross_total_minor' => $grossMinor,
        'net_total_minor' => $grossMinor,
        'tax_payable_minor' => 0,
    ]);
}

test('the draft lists what is overdue, oldest first, and nothing that is not', function () {
    $fixture = reminderFixture();

    reminderInvoice($fixture, now()->subDays(3)->toDateString(), 9_240_000, 'FT 2026/301');
    reminderInvoice($fixture, now()->subDays(40)->toDateString(), 15_000_000, 'FT 2026/250');
    reminderInvoice($fixture, now()->addDays(10)->toDateString(), 5_000_000, 'FT 2026/399');

    $draft = app(PreparePaymentReminder::class)->execute($fixture['customer']);

    expect(array_column($draft['documents'], 'document_no'))->toBe(['FT 2026/250', 'FT 2026/301'])
        ->and($draft['total_minor'])->toBe(24_240_000)
        ->and($draft['to'])->toBe('contas@mavinga.ao')
        ->and($draft['body'])->toContain('FT 2026/250')
        ->toContain('FT 2026/301')
        ->not->toContain('FT 2026/399')
        ->toContain('242 400,00 Kz')
        ->toContain('VAP Lembretes, Lda.');
});

test('a debt past a month, or one already chased, gets the firmer wording', function () {
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000, 'FT 2026/11');

    $recent = app(PreparePaymentReminder::class)->execute($fixture['customer']);

    reminderInvoice($fixture, now()->subDays(45)->toDateString(), 2_000_000, 'FT 2026/12');

    $old = app(PreparePaymentReminder::class)->execute($fixture['customer']);

    expect($recent['tone'])->toBe('cordial')
        ->and($recent['body'])->toContain('Agradecemos que regularize')
        ->and($old['tone'])->toBe('firm')
        ->and($old['body'])->toContain('com a maior brevidade');
});

test('the preview endpoint returns the draft as json and sends nothing', function () {
    Notification::fake();
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000, 'FT 2026/10');

    $this->actingAs($fixture['owner'])
        ->getJson(route('debts.reminder.show', $fixture['customer']))
        ->assertOk()
        ->assertJsonPath('customer_name', 'Mavinga & Filhos, Lda.')
        ->assertJsonPath('documents.0.document_no', 'FT 2026/10')
        ->assertJsonPath('last_sent_at', null);

    Notification::assertNothingSent();
});

test('a customer with nothing overdue gets an empty draft', function () {
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->addDays(3)->toDateString(), 1_000_000);

    $draft = app(PreparePaymentReminder::class)->execute($fixture['customer']);

    expect($draft['documents'])->toBe([])->and($draft['body'])->toBe('');
});

test('confirming sends the words the person approved, to the customer, and records it', function () {
    Notification::fake();
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000, 'FT 2026/10');

    $this->actingAs($fixture['owner'])
        ->post(route('debts.reminder.store', $fixture['customer']), [
            'subject' => 'Um aviso amigável',
            'body' => "Bom dia,\n\nSegue o nosso lembrete, com carinho.",
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Notification::assertSentOnDemand(
        PaymentReminder::class,
        fn (PaymentReminder $notification, array $channels, object $notifiable): bool => $notifiable->routes['mail'] === 'contas@mavinga.ao'
            && $notification->subject === 'Um aviso amigável'
            && str_contains($notification->body, 'com carinho'),
    );

    $activity = Activity::query()->where('event', PreparePaymentReminder::EVENT)->firstOrFail();

    expect($activity->subject_id)->toBe($fixture['customer']->id)
        ->and($activity->causer_id)->toBe($fixture['owner']->id)
        ->and($activity->properties['documents'])->toBe(['FT 2026/10']);
});

test('the same reminder cannot be sent twice in a minute', function () {
    Notification::fake();
    Cache::flush();
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000);

    $payload = ['subject' => 'Lembrete', 'body' => 'Bom dia, segue o lembrete de pagamento.'];

    $this->actingAs($fixture['owner'])->post(route('debts.reminder.store', $fixture['customer']), $payload)
        ->assertSessionHas('success');
    $this->actingAs($fixture['owner'])->post(route('debts.reminder.store', $fixture['customer']), $payload)
        ->assertSessionHas('error');

    Notification::assertSentOnDemandTimes(PaymentReminder::class, 1);
});

test('a customer with no email is refused with a way forward', function () {
    Notification::fake();
    $fixture = reminderFixture(email: null);
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000);

    $this->actingAs($fixture['owner'])
        ->post(route('debts.reminder.store', $fixture['customer']), [
            'subject' => 'Lembrete',
            'body' => 'Bom dia, segue o lembrete de pagamento.',
        ])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'não tem email'));

    Notification::assertNothingSent();
});

test('a debt that has since been paid is not reminded about', function () {
    Notification::fake();
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->addDays(2)->toDateString(), 1_000_000);

    $this->actingAs($fixture['owner'])
        ->post(route('debts.reminder.store', $fixture['customer']), [
            'subject' => 'Lembrete',
            'body' => 'Bom dia, segue o lembrete de pagamento.',
        ])
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'nada vencido'));

    Notification::assertNothingSent();
});

test('the words are required and bounded', function () {
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000);

    $this->actingAs($fixture['owner'])
        ->post(route('debts.reminder.store', $fixture['customer']), ['subject' => '', 'body' => 'curto'])
        ->assertSessionHasErrors(['subject', 'body']);
});

test('nobody outside the company can read or send a customer’s reminder', function () {
    Notification::fake();
    $fixture = reminderFixture();
    reminderInvoice($fixture, now()->subDays(5)->toDateString(), 1_000_000);

    $stranger = User::factory()->withWorkspace('Outra Empresa')->create();

    $this->actingAs($stranger)
        ->getJson(route('debts.reminder.show', $fixture['customer']))
        ->assertForbidden();

    $this->actingAs($stranger)
        ->post(route('debts.reminder.store', $fixture['customer']), [
            'subject' => 'Lembrete',
            'body' => 'Bom dia, segue o lembrete de pagamento.',
        ])
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('the email keeps paragraphs and the list of documents', function () {
    $notification = new PaymentReminder('Assunto', "Bom dia,\n\n- FT 1: 10,00 Kz\n- FT 2: 20,00 Kz\n\nObrigado.");

    $mail = $notification->toMail((object) []);
    $lines = $mail->introLines;

    expect($mail->subject)->toBe('Assunto')
        ->and($lines[0])->toBe('Bom dia,')
        ->and($lines[1]->toHtml())->toContain('FT 1: 10,00 Kz<br>')
        ->toContain('- FT 2: 20,00 Kz')
        ->and($lines[2])->toBe('Obrigado.');
});
