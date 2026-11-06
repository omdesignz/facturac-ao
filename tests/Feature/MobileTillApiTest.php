<?php

use App\Actions\SignInMobileDevice;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\PosCashMovement;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use App\PosSessionStatus;
use App\WorkspaceRole;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function tillToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

/**
 * @param  array<string, mixed>  $company
 * @return array<string, mixed>
 */
function tillSale(CatalogueItem $item, int $total, array $overrides = []): array
{
    return posSalePayload([[$item, '1']], $total, ['client_key' => (string) Str::uuid(), ...$overrides]);
}

it('tells the phone which tills there are and that no shift is open', function (): void {
    $company = posCompany();

    $this->withToken(tillToken($company['owner']))->getJson(route('mobile.v1.till.show'))
        ->assertOk()
        ->assertJsonPath('data.can_sell', true)
        ->assertJsonPath('data.session', null)
        ->assertJsonPath('data.registers.0.public_id', $company['register']->public_id)
        ->assertJsonPath('data.registers.0.has_series', true)
        ->assertJsonPath('data.final_consumer.tax_identification_number', '999999999')
        ->assertJsonPath('data.payment_methods.0.value', 'NU');
});

it('says why a viewer cannot sell, and refuses them', function (): void {
    $company = posCompany();
    $viewer = posMember($company, WorkspaceRole::Viewer);
    $token = tillToken($viewer);

    $this->withToken($token)->getJson(route('mobile.v1.till.show'))
        ->assertOk()
        ->assertJsonPath('data.can_sell', false)
        ->assertJsonPath('data.reason', 'A sua função nesta empresa não permite vender.');

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.store'), [
        'pos_register_public_id' => $company['register']->public_id,
        'opening_float' => '0',
        'password' => 'password',
    ])->assertForbidden();

    expect(PosSession::query()->exists())->toBeFalse();
});

it('opens a shift only with the right password', function (): void {
    $company = posCompany();
    $token = tillToken($company['owner']);
    $body = ['pos_register_public_id' => $company['register']->public_id, 'opening_float' => '5000,50'];

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.store'), [...$body, 'password' => 'errada'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    expect(PosSession::query()->exists())->toBeFalse();

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.store'), [...$body, 'password' => 'password'])
        ->assertCreated()
        ->assertJsonPath('data.session.opening_float_minor', 500050)
        ->assertJsonPath('data.session.summary.expected_cash_minor', 500050);
});

it('needs two-factor authentication to open a shift or sell', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company);
    $company['owner']->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $token = tillToken($company['owner']);

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.store'), [
        'pos_register_public_id' => $company['register']->public_id,
        'opening_float' => '0',
        'password' => 'password',
    ])->assertForbidden()->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_SELL');

    $this->withToken($token)->postJson(route('mobile.v1.till.sales.store', $session), tillSale($item, 11400))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_SELL');
});

it('shows what a sale comes to, from the catalogue and not the phone', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);

    $this->withToken(tillToken($company['owner']))->postJson(route('mobile.v1.till.sales.preview', $session), [
        'lines' => [['catalogue_item_public_id' => $item->public_id, 'quantity' => '2']],
    ])
        ->assertOk()
        ->assertJsonPath('data.customer_name', 'Consumidor final')
        ->assertJsonPath('data.gross_total_minor', 22800)
        ->assertJsonPath('data.tax_total_minor', 2800);
});

it('rings up a cash sale as a factura-recibo, with its change', function (): void {
    $company = posCompany();
    $session = posOpenSession($company, float: 100000);
    $item = posItem($company, ['unit_price_minor' => 10000]);

    $response = $this->withToken(tillToken($company['owner']))->postJson(
        route('mobile.v1.till.sales.store', $session),
        tillSale($item, 11400, ['tendered_minor' => 20000]),
    );

    $response->assertCreated()
        ->assertJsonPath('data.change_minor', 8600)
        ->assertJsonPath('data.receipt.is_issued', true)
        ->assertJsonPath('data.receipt.customer_name', 'Consumidor final')
        ->assertJsonPath('data.receipt.gross_total_minor', 11400)
        ->assertJsonPath('data.session.summary.sales_count', 1)
        ->assertJsonPath('data.session.summary.expected_cash_minor', 111400);

    expect(FiscalDocument::query()->sole()->document_type)->toBe(FiscalDocumentType::InvoiceReceipt)
        ->and($response->json('data.receipt.share_url'))->not->toBeNull();
});

it('comes back with the same sale when one is repeated, spending one number', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);
    $token = tillToken($company['owner']);
    $body = tillSale($item, 11400);

    $first = $this->withToken($token)->postJson(route('mobile.v1.till.sales.store', $session), $body)->assertCreated();
    $second = $this->withToken($token)->postJson(route('mobile.v1.till.sales.store', $session), $body)->assertOk();

    expect($second->json('data.receipt.document_no'))->toBe($first->json('data.receipt.document_no'))
        ->and($second->json('data.receipt.replayed'))->toBeTrue()
        ->and(PosSale::query()->count())->toBe(1)
        ->and(FiscalDocument::query()->count())->toBe(1);
});

it('refuses a sale whose total moved, and too little or too much cash', function (array $overrides, string $field): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);

    $this->withToken(tillToken($company['owner']))
        ->postJson(route('mobile.v1.till.sales.store', $session), tillSale($item, 11400, $overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(PosSale::query()->exists())->toBeFalse();
})->with([
    'total moved' => [['expected_total_minor' => 999, 'tendered_minor' => 999], 'expected_total_minor'],
    'too little' => [['tendered_minor' => 100], 'tendered_minor'],
    'a barcode in the amount' => [['tendered_minor' => 560123456789], 'tendered_minor'],
]);

it('only lets the person who opened a shift sell on it', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);
    $colleague = posMember($company, WorkspaceRole::Billing);

    $this->withToken(tillToken($colleague))
        ->postJson(route('mobile.v1.till.sales.store', $session), tillSale($item, 11400))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'SALE_NOT_ALLOWED');
});

it('bills a saved customer at what they pay', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);
    $customer = Customer::factory()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'name' => 'Pastelaria Kianda',
    ]);

    $this->withToken(tillToken($company['owner']))
        ->postJson(route('mobile.v1.till.sales.store', $session), tillSale($item, 11400, ['customer_public_id' => $customer->public_id]))
        ->assertCreated()
        ->assertJsonPath('data.receipt.customer_name', 'Pastelaria Kianda');
});

it('moves cash in and out once, however often the request is repeated', function (): void {
    $company = posCompany();
    $session = posOpenSession($company, float: 100000);
    $token = tillToken($company['owner']);
    $body = ['client_key' => (string) Str::uuid(), 'type' => 'out', 'amount' => '100,00', 'reason' => 'Troco para o banco'];

    $this->withToken($token)->postJson(route('mobile.v1.till.movements.store', $session), $body)
        ->assertCreated()
        ->assertJsonPath('data.summary.expected_cash_minor', 90000);

    $this->withToken($token)->postJson(route('mobile.v1.till.movements.store', $session), $body)
        ->assertOk()
        ->assertJsonPath('data.summary.expected_cash_minor', 90000);

    expect(PosCashMovement::query()->count())->toBe(1);
});

it('refuses to take more out than the drawer should hold', function (): void {
    $company = posCompany();
    $session = posOpenSession($company, float: 1000);

    $this->withToken(tillToken($company['owner']))->postJson(route('mobile.v1.till.movements.store', $session), [
        'client_key' => (string) Str::uuid(),
        'type' => 'out',
        'amount' => '100,00',
        'reason' => 'Troco para o banco',
    ])->assertConflict()->assertJsonPath('error.code', 'TILL_REFUSED');
});

it('closes a shift against the cash counted', function (): void {
    $company = posCompany();
    $session = posOpenSession($company, float: 100000);
    $token = tillToken($company['owner']);

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.close', $session), ['counted_cash' => '990,00'])
        ->assertOk()
        ->assertJsonPath('data.expected_cash_minor', 100000)
        ->assertJsonPath('data.cash_difference_minor', -1000);

    expect($session->fresh()->status)->toBe(PosSessionStatus::Closed);

    $this->withToken($token)->postJson(route('mobile.v1.till.sessions.close', $session), ['counted_cash' => '990,00'])
        ->assertConflict()
        ->assertJsonPath('error.code', 'TILL_REFUSED');

    $this->withToken($token)->getJson(route('mobile.v1.till.show'))->assertJsonPath('data.session', null);
});

it('does not reach another company\'s shift', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $other = posCompany();
    $item = posItem($other, ['unit_price_minor' => 10000]);

    $this->withToken(tillToken($other['owner']))
        ->postJson(route('mobile.v1.till.sales.store', $session), tillSale($item, 11400))
        ->assertNotFound();

    auth()->forgetGuards();

    $this->withToken(tillToken($other['owner']))
        ->postJson(route('mobile.v1.till.sessions.close', $session), ['counted_cash' => '0'])
        ->assertNotFound();

    expect($session->fresh()->isOpen())->toBeTrue();
});
