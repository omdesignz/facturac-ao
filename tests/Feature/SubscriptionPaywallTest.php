<?php

use App\Actions\CompletePosSale;
use App\Actions\ConfirmEmisPayment;
use App\Actions\ConvertRecurringProfile;
use App\Actions\GenerateRecurringInvoices;
use App\Actions\IssueFiscalDocument;
use App\Actions\IssueTransportDocument;
use App\Actions\OpenPosSession;
use App\Actions\SaveFiscalDocumentDraft;
use App\Actions\SaveQuote;
use App\Actions\SaveTransportDocumentDraft;
use App\Actions\StartSubscriptionCheckout;
use App\Billing\SubscriptionAccess;
use App\Exceptions\SubscriptionRequired;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\RecurringInvoice;
use App\Models\SubscriptionPlan;
use App\Models\TransportDocument;
use App\Models\User;
use App\Models\WorkspaceSubscription;
use App\SubscriptionFeature;
use Carbon\CarbonImmutable;
use Database\Seeders\SubscriptionCatalogueSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function subscribedUser(string $tier = 'essencial'): User
{
    $user = User::factory()->withWorkspace()->create();
    WorkspaceSubscription::factory()->create([
        'workspace_id' => $user->current_workspace_id,
        'subscription_plan_id' => SubscriptionPlan::where('code', $tier.'-monthly')->firstOrFail()->id,
    ]);

    return $user;
}

beforeEach(function () {
    $this->seed(SubscriptionCatalogueSeeder::class);
    Queue::fake();
});

test('premium HTTP operations reject a basic plan before changing data or dispatching work', function (string $method, string $route, string $feature) {
    $user = subscribedUser();
    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])
        ->json($method, route($route), [])->assertForbidden()
        ->assertJsonPath('code', 'SUBSCRIPTION_REQUIRED')->assertJsonPath('feature', $feature);
    Queue::assertNothingPushed();
})->with([
    ['GET', 'pos.show', 'pos'],
    ['POST', 'pos.sessions.store', 'pos'],
    ['POST', 'pos.registers.store', 'pos'],
    ['POST', 'stock.movements.store', 'inventory'],
    ['POST', 'recurring.store', 'recurring'],
    ['POST', 'recurring.run', 'recurring'],
    ['POST', 'transport-documents.store', 'transport'],
    ['GET', 'transport-documents.create', 'transport'],
    ['GET', 'transaction-reports.preview', 'reports'],
    ['POST', 'transaction-reports.download', 'reports'],
]);

test('browser paywalls take the user to available plans with an explanation', function () {
    $this->actingAs(subscribedUser())->get(route('pos.show'))
        ->assertRedirect(route('billing.show'))->assertSessionHas('error');
});

test('an expired subscription blocks new fiscal work while preserving archives billing and report history', function () {
    $user = subscribedUser();
    WorkspaceSubscription::where('workspace_id', $user->current_workspace_id)->update(['current_period_ends_at' => now()->subSecond()]);
    LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user)->postJson(route('invoices.store'), [])->assertForbidden()->assertJsonPath('feature', 'invoicing');
    foreach (['documents.index', 'saft.index', 'billing.show', 'transport-documents.index', 'pos.sessions.index', 'transaction-reports.history'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('billing.show'))->assertInertia(fn (Assert $page) => $page->where('subscriptionAccess.status', 'expired')->where('subscription.status_label', 'Período expirado'));
});

test('a paid upgrade takes effect immediately and a downgrade removes premium access', function () {
    $user = subscribedUser();
    LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user)->getJson(route('transaction-reports.preview'))->assertForbidden();
    $subscription = WorkspaceSubscription::where('workspace_id', $user->current_workspace_id)->firstOrFail();
    $subscription->update(['subscription_plan_id' => SubscriptionPlan::where('code', 'avancado-monthly')->firstOrFail()->id]);
    // An invalid report now reaches its normal validation, proving the paywall is open.
    $this->getJson(route('transaction-reports.preview', ['from' => 'invalid']))->assertUnprocessable();
    $subscription->update(['subscription_plan_id' => SubscriptionPlan::where('code', 'essencial-monthly')->firstOrFail()->id]);
    $this->getJson(route('transaction-reports.preview'))->assertForbidden();
});

test('domain actions cannot bypass the premium paywalls', function () {
    $user = subscribedUser();
    $company = LegalEntity::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $register = new PosRegister(['workspace_id' => $company->workspace_id]);
    $session = new PosSession(['workspace_id' => $company->workspace_id]);
    $guide = new TransportDocument(['workspace_id' => $company->workspace_id]);
    $profile = new RecurringInvoice(['workspace_id' => $company->workspace_id]);
    $tv = new FiscalDocument(['workspace_id' => $company->workspace_id, 'document_type' => FiscalDocumentType::SalesTicket]);
    foreach ([
        fn () => app(OpenPosSession::class)->execute($register, $user, 0),
        fn () => app(CompletePosSale::class)->execute($session, $user, []),
        fn () => app(SaveTransportDocumentDraft::class)->execute($company, $user, []),
        fn () => app(IssueTransportDocument::class)->execute($guide, $user, 1),
        fn () => app(ConvertRecurringProfile::class)->execute($profile, CarbonImmutable::now()),
        fn () => app(IssueFiscalDocument::class)->execute($tv, $user, 'unused', 1),
    ] as $operation) {
        expect($operation)->toThrow(SubscriptionRequired::class);
    }
});

test('expired domain actions cannot create invoices or quotes', function () {
    $user = subscribedUser();
    WorkspaceSubscription::where('workspace_id', $user->current_workspace_id)->update(['current_period_ends_at' => now()]);
    $company = LegalEntity::factory()->create(['workspace_id' => $user->current_workspace_id]);
    expect(fn () => app(SaveFiscalDocumentDraft::class)->execute($company, $user, []))->toThrow(SubscriptionRequired::class);
    expect(fn () => app(SaveQuote::class)->execute($company, $user, []))->toThrow(SubscriptionRequired::class);
    expect(FiscalDocument::count())->toBe(0);
});

test('scheduled recurring invoices pause without consuming occurrences on a lower tier', function () {
    $user = subscribedUser();
    $company = LegalEntity::factory()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->create(['workspace_id' => $company->workspace_id, 'legal_entity_id' => $company->id]);
    $customer = Customer::factory()->create(['workspace_id' => $company->workspace_id, 'legal_entity_id' => $company->id]);
    $profile = RecurringInvoice::factory()->create(['workspace_id' => $user->current_workspace_id, 'legal_entity_id' => $company->id, 'establishment_id' => $establishment->id, 'customer_id' => $customer->id, 'created_by_user_id' => $user->id, 'starts_on' => today()->subWeek(), 'next_run_on' => today()->subDay()]);
    $before = $profile->getAttributes();
    $result = app(GenerateRecurringInvoices::class)->execute();
    expect($result['generated'])->toBe(0)->and($result['failed'])->toBe(0)
        ->and($profile->fresh()->next_run_on->toDateString())->toBe($profile->next_run_on->toDateString())
        ->and($profile->fresh()->generated_count)->toBe((int) ($before['generated_count'] ?? 0));
    $this->assertDatabaseCount('recurring_occurrences', 0);
});

test('paying a catalogue plan activates exactly its advertised rights and price', function (string $tier, int $amount, bool $pos, bool $reports) {
    config(['billing.gateway' => 'pay4all', 'billing.pay4all.environment' => 'simulation']);
    Notification::fake();
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $workspace->forceFill(['created_at' => now()->subMonth()])->save();
    $plan = SubscriptionPlan::where('code', $tier.'-monthly')->firstOrFail();
    $access = app(SubscriptionAccess::class);
    $reference = app(StartSubscriptionCheckout::class)->execute($workspace, $plan, $user);
    expect($reference->amount_minor)->toBe($amount)->and($access->allows($workspace->id, SubscriptionFeature::Invoicing))->toBeFalse();
    app(ConfirmEmisPayment::class)->execute($reference, 'catalogue-payment-'.$tier, $amount, 'AOA', CarbonImmutable::now(), hash('sha256', $tier), $user);
    expect($access->allows($workspace->id, SubscriptionFeature::Invoicing))->toBeTrue()
        ->and($access->allows($workspace->id, SubscriptionFeature::Pos))->toBe($pos)
        ->and($access->allows($workspace->id, SubscriptionFeature::Reports))->toBe($reports);
})->with([
    ['essencial', 450_000, false, false],
    ['negocio', 950_000, true, false],
    ['avancado', 1_750_000, true, true],
]);
