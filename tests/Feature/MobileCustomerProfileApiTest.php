<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../PosFixtures.php';

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $attributes
 */
function profileDocument(array $company, Customer $customer, FiscalDocumentType $type, CarbonImmutable $date, int $grossMinor, array $attributes = []): FiscalDocument
{
    static $number = 0;
    $number++;

    return FiscalDocument::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['owner']->id,
        'updated_by_user_id' => $company['owner']->id,
        'customer_id' => $customer->id,
        'customer_name' => $customer->name,
        'document_type' => $type,
        'document_no' => "{$type->value} TESTE/{$number}",
        'status' => FiscalDocumentStatus::Issued,
        'document_date' => $date->toDateString(),
        'due_date' => $type === FiscalDocumentType::Invoice ? $date->addDays(30)->toDateString() : null,
        'gross_total_minor' => $grossMinor,
        'net_total_minor' => $grossMinor,
        'tax_payable_minor' => 0,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $company
 */
function profilePayment(array $company, Customer $customer, FiscalDocument $invoice, CarbonImmutable $paidOn, int $amountMinor, FiscalDocumentStatus $status = FiscalDocumentStatus::Issued): void
{
    $receipt = profileDocument($company, $customer, FiscalDocumentType::Receipt, $paidOn, $amountMinor, [
        'status' => $status,
        'payment_date' => $paidOn->toDateString(),
    ]);

    FiscalDocumentSettlement::query()->create([
        'workspace_id' => $receipt->workspace_id,
        'legal_entity_id' => $receipt->legal_entity_id,
        'fiscal_document_id' => $receipt->id,
        'settled_document_id' => $invoice->id,
        'settled_document_no' => $invoice->document_no,
        'amount_minor' => $amountMinor,
    ]);
}

/**
 * @return array<string, mixed>
 */
function profileCompany(): array
{
    $company = posCompany();

    return [
        ...$company,
        'token' => $company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
        'customer' => Customer::factory()->create([
            'workspace_id' => $company['legalEntity']->workspace_id,
            'legal_entity_id' => $company['legalEntity']->id,
            'name' => 'Mavinga & Filhos',
            'payment_terms_days' => 30,
        ]),
    ];
}

test('a customer shows a year of billing against payments, by month', function () {
    $company = profileCompany();
    $customer = $company['customer'];
    $month = CarbonImmutable::now('Africa/Luanda')->startOfMonth();

    $paidInFull = profileDocument($company, $customer, FiscalDocumentType::Invoice, $month->subMonthsNoOverflow(2)->addDay(), 100_000);
    profilePayment($company, $customer, $paidInFull, $month->subMonthNoOverflow()->addDays(4), 100_000);
    profileDocument($company, $customer, FiscalDocumentType::InvoiceReceipt, $month, 30_000);
    $partlyPaid = profileDocument($company, $customer, FiscalDocumentType::Invoice, $month, 50_000);
    profilePayment($company, $customer, $partlyPaid, $month, 20_000);
    profilePayment($company, $customer, $partlyPaid, $month, 10_000, FiscalDocumentStatus::Draft);
    profileDocument($company, $customer, FiscalDocumentType::Invoice, $month->subMonthsNoOverflow(12), 999_000);

    $history = $this->withToken($company['token'])
        ->getJson(route('mobile.v1.customers.show', ['customerPublicId' => $customer->public_id]))
        ->assertOk()
        ->assertJsonPath('data.name', 'Mavinga & Filhos')
        ->assertJsonPath('data.outstanding_minor', 999_000 + 30_000)
        ->assertJsonCount(12, 'data.history')
        ->json('data.history');

    $byMonth = collect($history)->keyBy('month');

    expect($history[0]['month'])->toBe($month->subMonthsNoOverflow(11)->format('Y-m'))
        ->and($history[11]['month'])->toBe($month->format('Y-m'))
        ->and($byMonth[$month->subMonthsNoOverflow(2)->format('Y-m')])->toMatchArray(['billed_minor' => 100_000, 'paid_minor' => 0])
        ->and($byMonth[$month->subMonthNoOverflow()->format('Y-m')])->toMatchArray(['billed_minor' => 0, 'paid_minor' => 100_000])
        ->and($byMonth[$month->format('Y-m')])->toMatchArray(['billed_minor' => 80_000, 'paid_minor' => 50_000])
        ->and(collect($history)->sum('billed_minor'))->toBe(180_000);
});

test('how long a customer takes to pay counts only what they finished paying', function () {
    $company = profileCompany();
    $customer = $company['customer'];
    $issuedOn = CarbonImmutable::now('Africa/Luanda')->subDays(40)->startOfDay();

    $invoice = profileDocument($company, $customer, FiscalDocumentType::Invoice, $issuedOn, 100_000);
    profilePayment($company, $customer, $invoice, $issuedOn->addDays(20), 100_000);
    profileDocument($company, $customer, FiscalDocumentType::InvoiceReceipt, $issuedOn, 30_000);
    profileDocument($company, $customer, FiscalDocumentType::Invoice, $issuedOn, 50_000);

    $this->withToken($company['token'])
        ->getJson(route('mobile.v1.customers.show', ['customerPublicId' => $customer->public_id]))
        ->assertJsonPath('data.average_days_to_settle', 10);
});

test('a customer with nothing yet has a quiet year and no payment speed', function () {
    $company = profileCompany();

    $response = $this->withToken($company['token'])
        ->getJson(route('mobile.v1.customers.show', ['customerPublicId' => $company['customer']->public_id]))
        ->assertOk()
        ->assertJsonPath('data.average_days_to_settle', null)
        ->assertJsonPath('data.outstanding_minor', 0);

    expect(collect($response->json('data.history'))->sum(fn (array $month): int => $month['billed_minor'] + $month['paid_minor']))->toBe(0);
});

test('another customer\'s documents stay off the profile', function () {
    $company = profileCompany();
    $other = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
    ]);
    $theirs = profileDocument($company, $other, FiscalDocumentType::Invoice, CarbonImmutable::now('Africa/Luanda'), 70_000);
    profilePayment($company, $other, $theirs, CarbonImmutable::now('Africa/Luanda'), 70_000);

    $history = $this->withToken($company['token'])
        ->getJson(route('mobile.v1.customers.show', ['customerPublicId' => $company['customer']->public_id]))
        ->json('data.history');

    expect(collect($history)->sum('billed_minor'))->toBe(0)
        ->and(collect($history)->sum('paid_minor'))->toBe(0);
});

test('another company\'s customer cannot be opened', function () {
    $company = profileCompany();
    $stranger = Customer::factory()->create();

    $this->withToken($company['token'])
        ->getJson(route('mobile.v1.customers.show', ['customerPublicId' => $stranger->public_id]))
        ->assertNotFound();
});
