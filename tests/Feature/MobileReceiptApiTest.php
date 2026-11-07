<?php

use App\Actions\SignInMobileDevice;
use App\Models\FiscalDocument;
use App\Models\PosSale;
use App\Models\User;
use App\WorkspaceRole;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

function receiptToken(User $user): string
{
    return $user->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
}

it('gives the phone a till sale as the web receipt prints it, with the AGT QR', function (): void {
    $company = posCompany();
    $session = posOpenSession($company, float: 100000);
    $item = posItem($company, ['unit_price_minor' => 10000]);
    $token = receiptToken($company['owner']);

    $sale = $this->withToken($token)->postJson(
        route('mobile.v1.till.sales.store', $session),
        posSalePayload([[$item, '1']], 11400, ['client_key' => (string) Str::uuid(), 'tendered_minor' => 20000]),
    )->assertCreated();

    $response = $this->withToken($token)
        ->getJson(route('mobile.v1.documents.receipt', $sale->json('data.receipt.public_id')))
        ->assertOk()
        ->assertJsonPath('data.document.document_no', $sale->json('data.receipt.document_no'))
        ->assertJsonPath('data.document.document_type', 'FR')
        ->assertJsonPath('data.document.customer.tax_identification_number', '999999999')
        ->assertJsonPath('data.document.totals.gross_minor', 11400)
        ->assertJsonPath('data.document.lines.0.quantity', '1.0000')
        ->assertJsonPath('data.sale.tendered_minor', 20000)
        ->assertJsonPath('data.sale.change_minor', 8600)
        ->assertJsonPath('data.sale.payment_method_label', 'Numerário')
        ->assertJsonPath('data.sale.register_name', $company['register']->name)
        ->assertJsonPath('data.sale.cashier_name', $company['owner']->name)
        ->assertJsonMissingPath('data.document.authenticity.qr_svg');

    $png = base64_decode((string) $response->json('data.document.authenticity.qr_png'), true);
    $size = getimagesizefromstring((string) $png);

    expect($size[0] ?? null)->toBe(350)->and($size[1] ?? null)->toBe(350);
});

it('leaves out the till block for a document that was not a till sale', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);
    $token = receiptToken($company['owner']);

    $sale = $this->withToken($token)->postJson(
        route('mobile.v1.till.sales.store', $session),
        posSalePayload([[$item, '1']], 11400, ['client_key' => (string) Str::uuid()]),
    )->assertCreated();

    PosSale::query()->delete();

    $this->withToken($token)
        ->getJson(route('mobile.v1.documents.receipt', $sale->json('data.receipt.public_id')))
        ->assertOk()
        ->assertJsonPath('data.sale', null);
});

it('has no receipt for a draft or for another company', function (): void {
    $company = posCompany();
    $other = posCompany();
    $session = posOpenSession($other);
    $item = posItem($other, ['unit_price_minor' => 10000]);

    $sale = $this->withToken(receiptToken($other['owner']))->postJson(
        route('mobile.v1.till.sales.store', $session),
        posSalePayload([[$item, '1']], 11400, ['client_key' => (string) Str::uuid()]),
    )->assertCreated();
    app('auth')->forgetGuards();

    $this->withToken(receiptToken($company['owner']))
        ->getJson(route('mobile.v1.documents.receipt', $sale->json('data.receipt.public_id')))
        ->assertNotFound();
    app('auth')->forgetGuards();

    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $other['legalEntity']->workspace_id,
        'legal_entity_id' => $other['legalEntity']->id,
        'establishment_id' => $other['establishment']->id,
    ]);

    $this->withToken(receiptToken($other['owner']))
        ->getJson(route('mobile.v1.documents.receipt', $draft->public_id))
        ->assertNotFound();
});

it('lets a viewer reprint a receipt', function (): void {
    $company = posCompany();
    $session = posOpenSession($company);
    $item = posItem($company, ['unit_price_minor' => 10000]);

    $sale = $this->withToken(receiptToken($company['owner']))->postJson(
        route('mobile.v1.till.sales.store', $session),
        posSalePayload([[$item, '1']], 11400, ['client_key' => (string) Str::uuid()]),
    )->assertCreated();
    app('auth')->forgetGuards();

    $this->withToken(receiptToken(posMember($company, WorkspaceRole::Viewer)))
        ->getJson(route('mobile.v1.documents.receipt', $sale->json('data.receipt.public_id')))
        ->assertOk()
        ->assertJsonPath('data.document.document_no', $sale->json('data.receipt.document_no'));
});
