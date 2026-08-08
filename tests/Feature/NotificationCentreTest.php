<?php

use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\NotificationDigest;
use App\Models\Quote;
use App\Models\User;
use App\Notifications\InvoiceFellOverdue;
use App\Notifications\QuoteWasAccepted;
use App\NotificationTopic;
use App\QuoteStatus;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function notificationFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Avisos')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Padaria Cazenga',
    ]);

    return compact('owner', 'legalEntity', 'establishment', 'customer');
}

/** @param array<string, mixed> $fixture */
function overdueInvoice(array $fixture, int $grossMinor = 500_000, int $daysAgo = 10): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'customer_name' => $fixture['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(100, 999),
        'gross_total_minor' => $grossMinor,
        'document_date' => now()->subDays($daysAgo + 30)->toDateString(),
        'due_date' => now()->subDays($daysAgo)->toDateString(),
    ]);
}

// ------------------------------------------------------------------- delivery

test('a notification reaches everyone who works in the company', function () {
    Notification::fake();

    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    QuoteWasAccepted::fromQuote(Quote::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'gross_total_minor' => 1_000_000,
    ]))->sendToWorkspace($workspace);

    Notification::assertSentTo($fixture['owner'], QuoteWasAccepted::class);
});

test('a stored notification carries its own words and link', function () {
    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);

    /** @var array<string, mixed> $data */
    $data = DatabaseNotification::query()->sole()->data;

    // The browser never has to know what an "invoice_overdue" is to render one.
    expect($data['topic'])->toBe('invoice_overdue')
        ->and($data['title'])->toContain('venceu há 10 dia(s)')
        ->and($data['body'])->toContain('Padaria Cazenga')
        ->and($data['url'])->toBeString()
        ->and($data['tone'])->toBe('warning')
        ->and($data['workspace_id'])->toBe($workspace->id);
});

// ---------------------------------------------------------------- preferences

test('a topic switched off does not arrive', function () {
    Notification::fake();

    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    $fixture['owner']->forceFill([
        'notification_preferences' => [
            NotificationTopic::InvoiceOverdue->value => [
                'database' => false,
                'mail' => false,
            ],
        ],
    ])->save();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);

    Notification::assertNothingSentTo($fixture['owner']);
});

test('support access cannot be silenced', function () {
    $user = User::factory()->create([
        'notification_preferences' => [
            NotificationTopic::SupportAccess->value => [
                'database' => false,
                'mail' => false,
            ],
        ],
    ]);

    // Knowing when someone else opened your account is not a preference.
    expect($user->wantsNotification(NotificationTopic::SupportAccess, 'database'))
        ->toBeTrue();
});

test('a topic the user never answered keeps its own default', function () {
    $user = User::factory()->create(['notification_preferences' => []]);

    expect($user->wantsNotification(NotificationTopic::DocumentAccepted, 'database'))
        ->toBeTrue()
        // Accepted is the expected outcome, so it does not also fill an inbox.
        ->and($user->wantsNotification(NotificationTopic::DocumentAccepted, 'mail'))
        ->toBeFalse()
        ->and($user->wantsNotification(NotificationTopic::DocumentRejected, 'mail'))
        ->toBeTrue();
});

test('the security page offers every topic a user may change', function () {
    $fixture = notificationFixture();

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('settings.security'))
        ->assertOk()
        ->assertInertia(function (Assert $page) {
            $topics = collect($page->toArray()['props']['notificationTopics']);

            expect($topics)->toHaveCount(count(NotificationTopic::configurable()))
                // Support access is not offered, because it cannot be refused.
                ->and($topics->pluck('value'))
                ->not->toContain(NotificationTopic::SupportAccess->value)
                ->and($topics->firstWhere('value', NotificationTopic::DocumentRejected->value))
                ->toMatchArray(['database' => true, 'mail' => true]);
        });
});

test('preferences are saved from the security page', function () {
    $fixture = notificationFixture();

    $this->actingAs($fixture['owner'])
        ->withSession(['auth.password_confirmed_at' => time()])
        ->put(route('settings.notifications.update'), [
            'topics' => [
                NotificationTopic::StockLow->value => [
                    'database' => false,
                    'mail' => true,
                ],
            ],
        ])->assertRedirect();

    $user = $fixture['owner']->fresh();

    expect($user->wantsNotification(NotificationTopic::StockLow, 'database'))
        ->toBeFalse()
        ->and($user->wantsNotification(NotificationTopic::StockLow, 'mail'))
        ->toBeTrue();
});

// ---------------------------------------------------------------- the feed

test('the bell shows only this company plus the account itself', function () {
    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);

    // A notification stamped with another company must not leak across.
    $fixture['owner']->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => InvoiceFellOverdue::class,
        'data' => ['topic' => 'invoice_overdue', 'title' => 'Outra empresa', 'workspace_id' => 999999],
        'read_at' => null,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Notifications/Index')
            ->has('notifications.data', 1)
            ->where('notifications.data.0.title', fn (string $title): bool => $title !== 'Outra empresa')
        );
});

test('opening a notification marks it read and follows it', function () {
    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);

    $row = DatabaseNotification::query()->sole();

    $this->actingAs($fixture['owner'])
        ->post(route('notifications.read', $row->id))
        ->assertRedirect(route('debts.index'));

    expect($row->fresh()->read_at)->not->toBeNull();
});

test('clearing keeps what has not been read', function () {
    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);
    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 700_000, 20)
        ->sendToWorkspace($workspace);

    DatabaseNotification::query()->first()->markAsRead();

    $this->actingAs($fixture['owner'])
        ->delete(route('notifications.clear-read'))
        ->assertRedirect();

    // Taking the unread ones too would quietly discard what nobody has seen.
    expect(DatabaseNotification::query()->count())->toBe(1)
        ->and(DatabaseNotification::query()->sole()->read_at)->toBeNull();
});

test('the unread count rides on every page', function () {
    $fixture = notificationFixture();
    $workspace = $fixture['owner']->currentWorkspace()->firstOrFail();

    InvoiceFellOverdue::fromDocument(overdueInvoice($fixture), 500_000, 10)
        ->sendToWorkspace($workspace);

    $this->actingAs($fixture['owner'])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.unread', 1)
            ->has('notifications.recent', 1)
        );
});

// --------------------------------------------------------------- the sweep

test('the sweep raises an overdue invoice once, not every morning', function () {
    $fixture = notificationFixture();
    overdueInvoice($fixture, 500_000, 10);

    $this->artisan('notifications:scan')->assertSuccessful();
    $first = DatabaseNotification::query()->count();

    $this->artisan('notifications:scan')->assertSuccessful();

    expect($first)->toBe(1)
        ->and(DatabaseNotification::query()->count())->toBe(1);
});

test('the sweep decides before delivery, so a slow queue cannot duplicate', function () {
    $fixture = notificationFixture();
    overdueInvoice($fixture, 500_000, 10);

    $this->artisan('notifications:scan')->assertSuccessful();

    // Delivery is queued; a decision that waited for the delivered row would
    // let the next sweep say the same thing again.
    DatabaseNotification::query()->delete();

    $this->artisan('notifications:scan')->assertSuccessful();

    expect(DatabaseNotification::query()->count())->toBe(0)
        ->and(NotificationDigest::query()->count())->toBe(1);
});

test('a debt still owed after the cooling-off is mentioned again', function () {
    $fixture = notificationFixture();
    overdueInvoice($fixture, 500_000, 10);

    $this->artisan('notifications:scan')->assertSuccessful();

    NotificationDigest::query()->update([
        'last_sent_at' => now()->subDays(config('notifications.cooling_off_days') + 1),
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();

    expect(DatabaseNotification::query()->count())->toBe(2);
});

test('a paid invoice past its due date raises nothing', function () {
    $fixture = notificationFixture();
    $document = overdueInvoice($fixture, 500_000, 10);

    // Paid the way the app pays it: a receipt allocated against the invoice.
    $receipt = FiscalDocument::factory()->create([
        'workspace_id' => $document->workspace_id,
        'legal_entity_id' => $document->legal_entity_id,
        'establishment_id' => $document->establishment_id,
        'customer_id' => $document->customer_id,
        'document_type' => FiscalDocumentType::Receipt,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'RC 2026/1',
        'gross_total_minor' => 500_000,
    ]);

    DB::table('fiscal_document_settlements')->insert([
        'workspace_id' => $document->workspace_id,
        'legal_entity_id' => $document->legal_entity_id,
        'fiscal_document_id' => $receipt->id,
        'settled_document_id' => $document->id,
        'settled_document_no' => (string) $document->document_no,
        'amount_minor' => 500_000,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();

    expect(DatabaseNotification::query()->count())->toBe(0);
});

test('a quote about to lapse is flagged before it does', function () {
    $fixture = notificationFixture();

    Quote::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'customer_id' => $fixture['customer']->id,
        'status' => QuoteStatus::Sent,
        'valid_until' => now()->addDays(2)->toDateString(),
    ]);

    $this->artisan('notifications:scan')->assertSuccessful();

    /** @var array<string, mixed> $data */
    $data = DatabaseNotification::query()->sole()->data;

    expect($data['topic'])->toBe(NotificationTopic::QuoteExpiring->value)
        ->and($data['title'])->toContain('expira em 2 dia(s)');
});

test('a customer past their limit is reported once', function () {
    $fixture = notificationFixture();
    $fixture['customer']->forceFill(['credit_limit_minor' => 100_000])->save();
    overdueInvoice($fixture, 500_000, 5);

    $this->artisan('notifications:scan')->assertSuccessful();

    $topics = DatabaseNotification::query()->get()
        ->map(fn (DatabaseNotification $row): string => $row->data['topic'])
        ->all();

    expect($topics)->toContain(NotificationTopic::CreditLimitExceeded->value);
});
