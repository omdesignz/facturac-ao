<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;

/**
 * @return array{owner: User, token: string, legalEntity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function mobileCompany(string $workspaceName = 'Kwanza Mercantil'): array
{
    $owner = User::factory()->withWorkspace($workspaceName)->create();
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
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 */
function mobileInvoice(array $company, int $daysLate, int $grossMinor, array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => $company['customer']->id,
        'customer_name' => $company['customer']->name,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_date' => now()->subDays($daysLate + 30)->toDateString(),
        'due_date' => now()->subDays($daysLate)->toDateString(),
        'gross_total_minor' => $grossMinor,
        ...$overrides,
    ]);
}

test('hoje gives the money owed and the decision to make', function () {
    $company = mobileCompany();
    mobileInvoice($company, 49, 37_800_000);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.currency_code', $company['legalEntity']->currency_code)
        ->assertJsonPath('data.receivables.outstanding_minor', 37_800_000)
        ->assertJsonPath('data.receivables.overdue_minor', 37_800_000)
        ->assertJsonPath('data.debtors.0.name', 'Mavinga & Filhos')
        ->assertJsonPath('data.decisions.0.key', 'collection')
        ->assertJsonPath('data.decisions.0.amount_minor', 37_800_000)
        ->assertJsonPath('data.decisions.0.customer.public_id', $company['customer']->public_id);
});

test('hoje agrees with the web dashboard', function () {
    $company = mobileCompany();
    mobileInvoice($company, 10, 500_000);
    mobileInvoice($company, -5, 200_000);

    $web = $this->actingAs($company['owner'])->get(route('dashboard'))
        ->viewData('page')['props']['kpis'];

    $this->app['auth']->forgetGuards();

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertJsonPath('data.receivables.outstanding_minor', $web['outstanding_minor'])
        ->assertJsonPath('data.receivables.overdue_minor', $web['overdue_minor']);
});

test('a quiet company has no decisions', function () {
    $company = mobileCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertOk()
        ->assertJsonPath('data.receivables.outstanding_minor', 0)
        ->assertJsonPath('data.debtors', [])
        ->assertJsonPath('data.decisions', []);
});

test('cobrar lists who owes, oldest debt first, and when they opened it', function () {
    $company = mobileCompany();
    mobileInvoice($company, 49, 24_800_000, ['last_opened_by_customer_at' => '2026-10-20 09:14:00']);
    mobileInvoice($company, 21, 13_000_000, ['last_opened_by_customer_at' => '2026-10-30 09:14:00']);

    $younger = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Cooperativa Kizomba',
    ]);
    mobileInvoice($company, 34, 13_110_000, ['customer_id' => $younger->id, 'customer_name' => $younger->name]);

    $response = $this->withToken($company['token'])->getJson(route('mobile.v1.collections'))
        ->assertOk()
        ->assertJsonPath('data.outstanding_minor', 50_910_000)
        ->assertJsonPath('data.overdue_minor', 50_910_000)
        ->assertJsonPath('data.customers.0.name', 'Mavinga & Filhos')
        ->assertJsonPath('data.customers.0.document_count', 2)
        ->assertJsonPath('data.customers.0.oldest_days_past_due', 49)
        ->assertJsonPath('data.customers.1.name', 'Cooperativa Kizomba')
        ->assertJsonPath('data.customers.1.last_opened_by_customer_at', null);

    expect($response->json('data.customers.0.last_opened_by_customer_at'))->toStartWith('2026-10-30');
});

test('another company\'s figures never reach the token', function () {
    $company = mobileCompany();
    mobileInvoice($company, 49, 37_800_000);
    $stranger = mobileCompany('Outra Empresa');

    $this->withToken($stranger['token'])->getJson(route('mobile.v1.today'))
        ->assertOk()
        ->assertJsonPath('data.receivables.outstanding_minor', 0)
        ->assertJsonPath('data.debtors', []);

    $this->withToken($stranger['token'])->getJson(route('mobile.v1.collections'))
        ->assertOk()
        ->assertJsonPath('data.customers', []);
});

test('a membership that has ended closes the figures', function () {
    $company = mobileCompany();
    $company['owner']->workspaceMemberships()->update(['is_active' => false]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'NO_WORKSPACE');
});

test('a workspace with no company set up gets a code, not a redirect', function () {
    $owner = User::factory()->withWorkspace()->create();
    $token = $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;

    $this->withToken($token)->getJson(route('mobile.v1.collections'))
        ->assertConflict()
        ->assertJsonPath('error.code', 'COMPANY_NOT_CONFIGURED');
});

test('the figures need a token', function () {
    $this->getJson(route('mobile.v1.today'))->assertUnauthorized();
    $this->getJson(route('mobile.v1.collections'))->assertUnauthorized();
});

test('hoje gives sixty days of billing, oldest first, with quiet days at zero', function () {
    $company = mobileCompany();
    mobileInvoice($company, 0, 1_000_000, ['document_date' => now()->toDateString()]);
    mobileInvoice($company, 0, 500_000, ['document_date' => now()->subDays(3)->toDateString()]);
    mobileInvoice($company, 0, 900_000, ['document_date' => now()->subDays(60)->toDateString()]);

    $trend = $this->withToken($company['token'])->getJson(route('mobile.v1.today'))
        ->assertOk()
        ->json('data.billing_trend');

    expect($trend)->toHaveCount(60)
        ->and($trend[59])->toBe(['date' => now()->toDateString(), 'billed_minor' => 1_000_000])
        ->and($trend[56])->toBe(['date' => now()->subDays(3)->toDateString(), 'billed_minor' => 500_000])
        ->and($trend[58]['billed_minor'])->toBe(0)
        ->and($trend[0]['date'])->toBe(now()->subDays(59)->toDateString())
        ->and(array_sum(array_column($trend, 'billed_minor')))->toBe(1_500_000);
});

test('the billing trend never shows another company', function () {
    $company = mobileCompany();
    mobileInvoice($company, 0, 1_000_000, ['document_date' => now()->toDateString()]);
    $stranger = mobileCompany('Outra Empresa');

    $trend = $this->withToken($stranger['token'])->getJson(route('mobile.v1.today'))->json('data.billing_trend');

    expect(array_sum(array_column($trend, 'billed_minor')))->toBe(0);
});

test('cobrar gives the age of the debt, and each customer\'s share of it', function () {
    $company = mobileCompany();
    mobileInvoice($company, 10, 1_000_000);
    mobileInvoice($company, 45, 500_000);

    $response = $this->withToken($company['token'])->getJson(route('mobile.v1.collections'))->assertOk();
    $aging = collect($response->json('data.aging'))->keyBy('key');

    expect($aging->keys()->all())->toBe(['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'])
        ->and($aging['d1_30']['total_minor'])->toBe(1_000_000)
        ->and($aging['d1_30']['label'])->toBe('1–30 dias')
        ->and($aging['d31_60']['total_minor'])->toBe(500_000)
        ->and($aging['current']['total_minor'])->toBe(0)
        ->and($response->json('data.customers.0.buckets.d1_30'))->toBe(1_000_000)
        ->and($response->json('data.customers.0.buckets.d31_60'))->toBe(500_000);
});
