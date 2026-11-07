<?php

use App\Actions\SignInMobileDevice;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\MobileTransportDocument;
use App\Models\TransportDocument;
use App\Models\TransportDocumentSequence;
use App\Models\User;
use App\TransportDocumentStatus;
use App\TransportDocumentType;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

/**
 * A company that moves goods: a head office to load at, a customer to deliver
 * to, a product and a service in the catalogue, and an owner with two-factor
 * authentication and a known password, signed in on a phone.
 *
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment, customer: Customer, crate: CatalogueItem, service: CatalogueItem, token: string}
 */
function phoneGuideCompany(): array
{
    configureTestSaftSigning();
    $owner = User::factory()->withWorkspace('VAP Guias')->create();
    $owner->forceFill([
        'password' => Hash::make('a-very-good-password'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('guide-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['guide-recovery'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Logística, Lda.',
        'tax_identification_number' => '5412345678',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Armazém central',
        'address_line' => 'Rua do Porto, 12',
        'municipality' => 'Luanda',
    ]);
    $company = [
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ];

    return [
        'owner' => $owner,
        'legalEntity' => $legalEntity,
        'establishment' => $establishment,
        'customer' => Customer::factory()->create([
            ...$company,
            'name' => 'Comercial Maianga, Lda.',
            'tax_identification_number' => '5411111111',
            'address_line' => 'Avenida Revolução de Outubro, 45',
        ]),
        'crate' => CatalogueItem::factory()->create([
            ...$company,
            'type' => 'product',
            'code' => 'CX-001',
            'name' => 'Caixa de água 1,5 L',
            'unit_of_measure' => 'CX',
            'unit_price_minor' => 2_500_00,
        ]),
        'service' => CatalogueItem::factory()->create([
            ...$company,
            'type' => 'service',
            'code' => 'MONT',
            'name' => 'Montagem',
            'unit_of_measure' => 'UN',
        ]),
        'token' => $owner->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken,
    ];
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneGuidePayload(array $company, array $overrides = []): array
{
    return [
        'document_type' => TransportDocumentType::TransportGuide->value,
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => $company['customer']->public_id,
        'origin' => ['address' => 'Rua do Porto, 12', 'city' => 'Luanda'],
        'destination' => ['address' => 'Mercado do Kikolo, armazém 3', 'city' => 'Cacuaco'],
        'movement_start_at' => now('Africa/Luanda')->addHour()->format('Y-m-d H:i'),
        'movement_end_at' => now('Africa/Luanda')->addHours(3)->format('Y-m-d H:i'),
        'vehicle_registration' => 'ld-42-81-aa',
        'lines' => [['catalogue_item_public_id' => $company['crate']->public_id, 'quantity' => '12.5']],
        ...$overrides,
    ];
}

/**
 * 12,5 crates at 2 500,00 each.
 */
const PHONE_GUIDE_TOTAL = 31_250_00;

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneGuideIssue(array $company, array $overrides = []): array
{
    return [
        ...phoneGuidePayload($company),
        'client_key' => (string) Str::uuid(),
        'expected_total_minor' => PHONE_GUIDE_TOTAL,
        'password' => 'a-very-good-password',
        ...$overrides,
    ];
}

it('tells the phone which guides it may issue, and from where', function (): void {
    $company = phoneGuideCompany();

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_issue', true)
        ->assertJsonPath('data.reason', null)
        ->assertJsonPath('data.types.*.value', ['GR', 'GT', 'GA', 'GD'])
        ->assertJsonPath('data.types.*.recipient', ['customer', 'customer', 'company', 'supplier'])
        ->assertJsonPath('data.establishments.0.public_id', $company['establishment']->public_id)
        ->assertJsonPath('data.establishments.0.address', 'Rua do Porto, 12')
        ->assertJsonPath('data.establishments.0.city', 'Luanda');
});

it('says why the phone cannot issue when two-factor authentication is off', function (): void {
    $company = phoneGuideCompany();
    $company['owner']->forceFill(['two_factor_confirmed_at' => null, 'two_factor_secret' => null])->save();

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_issue', false)
        ->assertJsonPath('data.reason', 'Para emitir, active a autenticação em dois passos em facturac.ao.');

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED_TO_ISSUE');

    expect(TransportDocument::query()->count())->toBe(0);
});

it('previews a guide from item ids and quantities, with the catalogue\'s codes and values', function (): void {
    $company = phoneGuideCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.preview'), phoneGuidePayload($company))
        ->assertOk()
        ->assertJsonPath('data.document_type_label', 'Guia de transporte')
        ->assertJsonPath('data.recipient.name', 'Comercial Maianga, Lda.')
        ->assertJsonPath('data.recipient.tax_identification_number', '5411111111')
        ->assertJsonPath('data.destination.city', 'Cacuaco')
        ->assertJsonPath('data.vehicle_registration', 'LD-42-81-AA')
        ->assertJsonPath('data.lines.0.product_code', 'CX-001')
        ->assertJsonPath('data.lines.0.description', 'Caixa de água 1,5 L')
        ->assertJsonPath('data.lines.0.quantity', '12.500')
        ->assertJsonPath('data.lines.0.unit_of_measure', 'CX')
        ->assertJsonPath('data.gross_total_minor', PHONE_GUIDE_TOTAL);

    expect(TransportDocument::query()->count())->toBe(0);
});

it('issues a numbered, signed guide through the web\'s actions, and a repeat comes back as the same one', function (): void {
    $company = phoneGuideCompany();
    $payload = phoneGuideIssue($company);

    $issued = $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.status', 'issued')
        ->assertJsonPath('data.is_issued', true)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.recipient_name', 'Comercial Maianga, Lda.')
        ->assertJsonPath('data.origin.address', 'Rua do Porto, 12')
        ->assertJsonPath('data.destination.address', 'Mercado do Kikolo, armazém 3')
        ->assertJsonPath('data.vehicle_registration', 'LD-42-81-AA')
        ->assertJsonPath('data.lines.0.quantity', '12.500')
        ->assertJsonPath('data.gross_total_minor', PHONE_GUIDE_TOTAL)
        ->json('data');

    $guide = TransportDocument::query()->sole();

    expect($issued['document_no'])->toBe($guide->document_no)
        ->and($guide->document_no)->toStartWith('GT ')
        ->and($guide->status)->toBe(TransportDocumentStatus::Issued)
        ->and($guide->document_hash)->not->toBeNull()
        ->and($guide->issued_by_user_id)->toBe($company['owner']->id)
        ->and($guide->customer_id)->toBe($company['customer']->id)
        ->and($guide->recipient_address)->toBe('Avenida Revolução de Outubro, 45')
        ->and($guide->recipient_city)->toBe('Cacuaco')
        ->and($guide->movement_start_at->setTimezone('Africa/Luanda')->format('Y-m-d H:i'))->toBe($payload['movement_start_at'])
        ->and($guide->movement_end_at?->setTimezone('Africa/Luanda')->format('Y-m-d H:i'))->toBe($payload['movement_end_at'])
        ->and($guide->lines->sole()->catalogue_item_id)->toBe($company['crate']->id)
        ->and($guide->lines->sole()->quantity_units)->toBe(12_500);

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), $payload)
        ->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.document_no', $guide->document_no);

    expect(TransportDocument::query()->count())->toBe(1)
        ->and(MobileTransportDocument::query()->sole()->transport_document_id)->toBe($guide->id)
        ->and(TransportDocumentSequence::query()->sole()->next_number)->toBe(2);
});

it('gives the issued guide a signed link that opens its PDF for someone without an account', function (): void {
    $company = phoneGuideCompany();

    $shareUrl = $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company))
        ->assertCreated()
        ->json('data.share_url');
    $guide = TransportDocument::query()->sole();

    expect($shareUrl)->toBeString();

    app('auth')->forgetGuards();

    $this->get($shareUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');

    $this->get(route('transport-documents.shared', $guide))->assertForbidden();
});

it('refuses to issue without the right password, and spends no number', function (): void {
    $company = phoneGuideCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company, ['password' => 'wrong']))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'PASSWORD_INCORRECT');

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company, ['password' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'Confirme a palavra-passe para emitir.']);

    expect(TransportDocument::query()->count())->toBe(0)
        ->and(TransportDocumentSequence::query()->count())->toBe(0);
});

it('issues nothing, not even a draft, when the value changed since the preview', function (): void {
    $company = phoneGuideCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company, ['expected_total_minor' => 100]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['expected_total_minor']);

    expect(TransportDocument::query()->count())->toBe(0)
        ->and(TransportDocumentSequence::query()->count())->toBe(0);
});

it('refuses what a guide cannot carry', function (array $overrides, string $field): void {
    $company = phoneGuideCompany();
    $payload = phoneGuidePayload($company, is_callable($overrides[0] ?? null) ? $overrides[0]($company) : $overrides);

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.preview'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'a service' => [[fn (array $company): array => ['lines' => [['catalogue_item_public_id' => $company['service']->public_id, 'quantity' => '1']]]], 'lines.0.catalogue_item_public_id'],
    'a saved customer on a return note' => [['document_type' => 'GD'], 'customer_public_id'],
    'a consignment guide the AGT schema does not take yet' => [['document_type' => 'GC'], 'document_type'],
    'loading before today' => [[fn (): array => ['movement_start_at' => now('Africa/Luanda')->subDay()->format('Y-m-d H:i'), 'movement_end_at' => null]], 'movement_start_at'],
    'unloading before loading' => [[fn (): array => ['movement_end_at' => now('Africa/Luanda')->format('Y-m-d H:i')]], 'movement_end_at'],
    'no recipient at all' => [['customer_public_id' => null], 'recipient.name'],
    'a quantity with four decimals' => [[fn (array $company): array => ['lines' => [['catalogue_item_public_id' => $company['crate']->public_id, 'quantity' => '1.2345']]]], 'lines.0.quantity'],
]);

it('issues a return note to a supplier typed on the phone', function (): void {
    $company = phoneGuideCompany();

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company, [
        'document_type' => 'GD',
        'customer_public_id' => null,
        'recipient' => ['name' => 'Fornecedora do Sul, Lda.', 'tax_identification_number' => '5422222222'],
    ]))
        ->assertCreated()
        ->assertJsonPath('data.document_type', 'GD')
        ->assertJsonPath('data.recipient.name', 'Fornecedora do Sul, Lda.');

    expect(TransportDocument::query()->sole()->recipient_address)->toBe('Mercado do Kikolo, armazém 3');
});

it('lists and opens only the current company\'s guides', function (): void {
    $company = phoneGuideCompany();
    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company))->assertCreated();
    $guide = TransportDocument::query()->sole();
    $elsewhere = TransportDocument::factory()->create(['recipient_name' => 'Outra empresa']);

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.public_id', $guide->public_id)
        ->assertJsonPath('data.0.document_type_label', 'Transporte')
        ->assertJsonPath('data.0.destination_city', 'Cacuaco')
        ->assertJsonPath('meta.total', 1);

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.index', ['q' => 'LD-42']))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.index', ['q' => 'Huambo']))
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.show', $guide->public_id))
        ->assertOk()
        ->assertJsonPath('data.document_no', $guide->document_no)
        ->assertJsonPath('data.company_tax_identification_number', '5412345678')
        ->assertJsonPath('data.lines.0.description', 'Caixa de água 1,5 L');

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.show', $elsewhere->public_id))
        ->assertNotFound();
});

it('lets a consultation-only member read guides but not issue one', function (): void {
    $company = phoneGuideCompany();
    $company['owner']->workspaceMemberships()
        ->where('workspace_id', $company['legalEntity']->workspace_id)
        ->update(['role' => WorkspaceRole::Viewer->value]);

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.setup'))
        ->assertOk()
        ->assertJsonPath('data.can_issue', false);

    $this->withToken($company['token'])->getJson(route('mobile.v1.transport-documents.index'))->assertOk();

    $this->withToken($company['token'])->postJson(route('mobile.v1.transport-documents.store'), phoneGuideIssue($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TRANSPORT_NOT_ALLOWED');
});

it('keeps issuing on its own named limit, apart from the reads', function (): void {
    expect(Route::getRoutes()->getByName('mobile.v1.transport-documents.store')->gatherMiddleware())
        ->toContain('throttle:10,1,mobile-transport-issue');
});
