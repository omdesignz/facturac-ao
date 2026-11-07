<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Notifications\RecurringInvoiceDue;
use App\Notifications\RecurringInvoiceGenerated;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../PosFixtures.php';

function recurringToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

/**
 * A monthly avença of 50 000,00 + 14 % IVA (57 000,00) whose next run is tomorrow.
 *
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 */
function eveProfile(array $company, array $overrides = []): RecurringInvoice
{
    return RecurringInvoice::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => Customer::factory()->create([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
            'name' => 'Hotel Baía Azul',
        ])->id,
        'created_by_user_id' => $company['owner']->id,
        'name' => 'Manutenção',
        'starts_on' => now()->subMonths(3)->toDateString(),
        'next_run_on' => now()->addDay()->toDateString(),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->travelTo(now('Africa/Luanda')->setTime(18, 0));
});

it('tells those who may act on an avença, the evening before, what it will raise', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);
    $billing = posMember($company, WorkspaceRole::Billing);
    $viewer = posMember($company, WorkspaceRole::Viewer);
    eveProfile($company, ['next_run_on' => now()->addDays(2)->toDateString(), 'name' => 'Depois']);
    Notification::fake();

    $this->artisan('recurring:remind-due')->assertSuccessful();
    $this->artisan('recurring:remind-due')->assertSuccessful();

    Notification::assertSentToTimes($company['owner'], RecurringInvoiceDue::class, 1);
    Notification::assertSentTo($billing, RecurringInvoiceDue::class, function (RecurringInvoiceDue $notification) use ($profile, $billing): bool {
        $push = $notification->toPush($billing);

        return $notification->profilePublicId === $profile->public_id
            && $notification->title() === 'Amanhã: avença Manutenção'
            && str_contains($notification->body(), 'Hotel Baía Azul · 57 000 Kz')
            && $push['aps']['category'] === 'RECURRING_DUE'
            && $push['target'] === ['type' => 'recurring', 'public_id' => $profile->public_id, 'label' => 'Manutenção']
            && $push['run_on'] === now()->addDay()->toDateString();
    });
    Notification::assertNotSentTo($viewer, RecurringInvoiceDue::class);
});

it('lists avenças with their next run and shows one with what the phone may do', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);
    $token = recurringToken($company['owner']);

    $this->withToken($token)->getJson(route('mobile.v1.recurring.index'))
        ->assertOk()
        ->assertJsonPath('data.0.public_id', $profile->public_id)
        ->assertJsonPath('data.0.next_run_on', now()->addDay()->toDateString())
        ->assertJsonPath('data.0.estimated_total_minor', 5_700_000)
        ->assertJsonPath('data.0.frequency_label', 'Mensal');

    $this->withToken($token)->getJson(route('mobile.v1.recurring.show', $profile->public_id))
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Hotel Baía Azul')
        ->assertJsonPath('data.following_run_on', now()->addDay()->addMonthNoOverflow()->toDateString())
        ->assertJsonPath('data.can_skip', true)
        ->assertJsonPath('data.can_run', true)
        ->assertJsonPath('data.run_needs_proof', false)
        ->assertJsonPath('data.lines.0.description', 'Avença mensal de manutenção');
});

it('lets a viewer read avenças but not act on them', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);
    $token = recurringToken(posMember($company, WorkspaceRole::Viewer));

    $this->withToken($token)->getJson(route('mobile.v1.recurring.show', $profile->public_id))
        ->assertOk()
        ->assertJsonPath('data.can_skip', false)
        ->assertJsonPath('data.can_run', false);

    $this->withToken($token)->postJson(route('mobile.v1.recurring.skip', $profile->public_id), ['next_run_on' => now()->addDay()->toDateString()])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'RECURRING_NOT_ALLOWED');

    expect($profile->fresh()->next_run_on->toDateString())->toBe(now()->addDay()->toDateString());
});

it('skips the coming run once, and a repeated request skips nothing more', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);
    $token = recurringToken($company['owner']);
    $body = ['next_run_on' => now()->addDay()->toDateString()];
    $following = now()->addDay()->addMonthNoOverflow()->toDateString();

    foreach ([1, 2] as $attempt) {
        $this->withToken($token)->postJson(route('mobile.v1.recurring.skip', $profile->public_id), $body)
            ->assertOk()
            ->assertJsonPath('data.next_run_on', $following);
    }

    expect($profile->fresh()->next_run_on->toDateString())->toBe($following)
        ->and(FiscalDocument::query()->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'skipped')->count())->toBe(1);

    // Tomorrow's morning run now has nothing to raise.
    $this->artisan('billing:generate-recurring-invoices')->assertSuccessful();
    expect(FiscalDocument::query()->exists())->toBeFalse();
});

it('refuses a skip for a run the phone did not see', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);

    $this->withToken(recurringToken($company['owner']))
        ->postJson(route('mobile.v1.recurring.skip', $profile->public_id), ['next_run_on' => now()->addDays(5)->toDateString()])
        ->assertConflict()
        ->assertJsonPath('error.code', 'RECURRING_MOVED');
});

it('raises the coming run now as a draft dated today, once, and the morning run then has nothing to do', function (): void {
    $company = posCompany();
    $profile = eveProfile($company);
    $token = recurringToken($company['owner']);
    $body = ['next_run_on' => now()->addDay()->toDateString()];
    Notification::fake();

    $first = $this->withToken($token)->postJson(route('mobile.v1.recurring.run', $profile->public_id), $body)
        ->assertCreated()
        ->assertJsonPath('data.is_issued', false)
        ->assertJsonPath('data.gross_total_minor', 5_700_000)
        ->assertJsonPath('data.customer_name', 'Hotel Baía Azul');

    $this->withToken($token)->postJson(route('mobile.v1.recurring.run', $profile->public_id), $body)
        ->assertOk()
        ->assertJsonPath('data.public_id', $first->json('data.public_id'))
        ->assertJsonPath('data.replayed', true);

    $document = FiscalDocument::query()->sole();

    expect($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($document->document_date->toDateString())->toBe(now()->toDateString())
        ->and($profile->fresh()->next_run_on->toDateString())->toBe(now()->addDay()->addMonthNoOverflow()->toDateString())
        ->and($profile->fresh()->generated_count)->toBe(1);
    Notification::assertNotSentTo($company['owner'], RecurringInvoiceGenerated::class);

    $this->travelTo(now()->addDay()->setTime(6, 0));
    $this->artisan('billing:generate-recurring-invoices')->assertSuccessful();
    expect(FiscalDocument::query()->count())->toBe(1);
});

it('does not raise a run more than a day early', function (): void {
    $company = posCompany();
    $profile = eveProfile($company, ['next_run_on' => now()->addDays(3)->toDateString()]);

    $this->withToken(recurringToken($company['owner']))->getJson(route('mobile.v1.recurring.show', $profile->public_id))
        ->assertJsonPath('data.can_run', false)
        ->assertJsonPath('data.can_skip', true);

    $this->withToken(recurringToken($company['owner']))
        ->postJson(route('mobile.v1.recurring.run', $profile->public_id), ['next_run_on' => now()->addDays(3)->toDateString()])
        ->assertConflict()
        ->assertJsonPath('error.code', 'RECURRING_NOT_DUE');

    expect(FiscalDocument::query()->exists())->toBeFalse();
});

it('asks for the password before raising an avença that issues by itself', function (): void {
    $company = posCompany();
    $company['owner']->forceFill(['password' => Hash::make('a-very-good-password')])->save();
    $profile = eveProfile($company, ['auto_issue' => true]);
    $token = recurringToken($company['owner']);
    $body = ['next_run_on' => now()->addDay()->toDateString()];

    $this->withToken($token)->getJson(route('mobile.v1.recurring.show', $profile->public_id))
        ->assertJsonPath('data.run_needs_proof', true);

    $this->withToken($token)->postJson(route('mobile.v1.recurring.run', $profile->public_id), $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->withToken($token)->postJson(route('mobile.v1.recurring.run', $profile->public_id), [...$body, 'password' => 'errada'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    expect(FiscalDocument::query()->exists())->toBeFalse();

    $this->withToken($token)->postJson(route('mobile.v1.recurring.run', $profile->public_id), [...$body, 'password' => 'a-very-good-password'])
        ->assertCreated();

    expect(FiscalDocument::query()->count())->toBe(1);
});

it('has nothing for another company\'s avença', function (): void {
    $company = posCompany();
    $profile = eveProfile(posCompany());

    $this->withToken(recurringToken($company['owner']))
        ->getJson(route('mobile.v1.recurring.show', $profile->public_id))
        ->assertNotFound();
});
