<?php

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Fiscal\Pos\FinalConsumer;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\FiscalSeriesStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\Integration;
use App\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class);

require_once __DIR__.'/../PosFixtures.php';

/**
 * 2 × 100,00 at 14 % IVA: 200,00 net, 28,00 tax, 228,00 to pay.
 */
const API_INVOICE_GROSS = '22800';

/**
 * A company whose production AGT connection has FT, FR and NC series, a
 * customer and an article, and an integration credential holding the given
 * scopes. The homologation connection and FR series from posCompany() stay,
 * so the tests can tell which environment a number came from.
 *
 * @param  list<string>  $scopes
 * @return array<string, mixed>
 */
function apiDocumentCompany(array $scopes = ['documents:issue']): array
{
    config(['integrations.enabled' => true, 'integrations.commands_enabled' => true, 'agt.environments.production.enabled' => true]);
    $company = posCompany();
    $entity = $company['legalEntity'];
    $production = AgtConnection::factory()->create([
        'workspace_id' => $entity->workspace_id,
        'legal_entity_id' => $entity->id,
        'environment' => AgtEnvironment::Production,
        'status' => AgtConnectionStatus::Verified,
        'software_key_reference' => 'software/pos',
        'taxpayer_key_reference' => 'taxpayer/pos',
        'software_key_fingerprint' => hash('sha256', 'software/pos'),
        'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer/pos'),
        'verified_at' => now(),
    ]);
    $series = [];
    foreach ([FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::CreditNote] as $type) {
        $series[$type->value] = posSeries($entity, $company['establishment'], $production, [
            'series_code' => $type->value.'API',
            'document_type' => $type,
            'environment' => 'production',
        ]);
    }

    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $company['owner']);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $management = IntegrationManagementContext::resolve($request, $entity->workspace_id);
    $issued = app(IntegrationCredentials::class)->create($management, $entity->public_id, AgtEnvironment::Production, 'Loja online', $scopes);
    $integration = Integration::query()->where('public_id', $issued->integrationPublicId)->firstOrFail();

    return [
        ...$company,
        'production' => $production,
        'productionSeries' => $series,
        'integration' => $integration,
        'secret' => $issued->revealOnce(),
        'url' => 'https://localhost/api/integrations/commands/v1/workspaces/'.strtolower($entity->workspace->public_id)
            .'/legal-entities/'.strtolower($entity->public_id).'/environments/production/documents',
        'customer' => Customer::factory()->create([
            'workspace_id' => $entity->workspace_id,
            'legal_entity_id' => $entity->id,
            'name' => 'Mavinga & Filhos',
            'tax_identification_number' => '5417000000',
            'payment_terms_days' => 30,
        ]),
        'item' => posItem($company),
    ];
}

/**
 * @param  array<string, mixed>  $company
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiInvoicePayload(array $company, array $overrides = []): array
{
    return [
        'document_type' => 'FT',
        'customer_public_id' => $company['customer']->public_id,
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => API_INVOICE_GROSS,
        ...$overrides,
    ];
}

/**
 * @param  array<string, mixed>  $company
 * @return array<string, string>
 */
function apiHeaders(array $company, string $key = 'order-1001'): array
{
    return ['Authorization' => 'Bearer '.$company['secret'], 'Idempotency-Key' => $key];
}

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect((DB::getDriverName() === 'sqlite' && DB::connection()->getDatabaseName() === ':memory:') || (DB::getDriverName() === 'pgsql' && str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')))->toBeTrue();
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    Queue::fake();
});

test('an integration issues an invoice numbered from its production series', function () {
    $company = apiDocumentCompany();

    $response = $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false');

    expect(array_keys($response->json()))->toBe(['data', 'meta'])
        ->and(array_keys($response->json('data')))->toBe(['public_id']);

    $document = FiscalDocument::query()->sole();
    expect($document->public_id)->toBe($response->json('data.public_id'))
        ->and($document->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($document->document_type)->toBe(FiscalDocumentType::Invoice)
        ->and($document->environment)->toBe('production')
        ->and($document->fiscal_series_id)->toBe($company['productionSeries']['FT']->id)
        ->and($document->gross_total_minor)->toBe((int) API_INVOICE_GROSS)
        ->and($document->customer_name)->toBe('Mavinga & Filhos')
        ->and($document->due_date?->toDateString())->toBe(now('Africa/Luanda')->addDays(30)->toDateString())
        ->and($document->issued_by_user_id)->toBe($company['owner']->id);
    expect($company['productionSeries']['FT']->fresh()->next_number)->toBe(2);
    Queue::assertPushed(SubmitAgtDocument::class);

    $audit = Activity::query()->where('event', 'fiscal-document.issued')->sole();
    expect($audit->subject_id)->toBe($document->id)
        ->and($audit->causer_type)->toBe(Integration::class)
        ->and($audit->causer_id)->toBe($company['integration']->id)
        ->and($audit->properties->get('capability'))->toBe('documents.issue')
        ->and($audit->properties->get('operation_id'))->toBe($response->json('meta.operation_id'));
});

test('a retried request answers with the same document and spends no second number', function () {
    $company = apiDocumentCompany();

    $first = $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))->assertCreated();
    $again = $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'true');

    expect($again->getContent())->toBe($first->getContent())
        ->and(FiscalDocument::query()->count())->toBe(1)
        ->and($company['productionSeries']['FT']->fresh()->next_number)->toBe(2);
});

test('the same key with a different document is a conflict', function () {
    $company = apiDocumentCompany();

    $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))->assertCreated();
    $this->postJson($company['url'], apiInvoicePayload($company, [
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '1']],
        'expected_total_minor' => '11400',
    ]), apiHeaders($company))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');

    expect(FiscalDocument::query()->count())->toBe(1);
});

test('an invoice-receipt to the final consumer carries a one-off line and its payment', function () {
    $company = apiDocumentCompany();

    $this->postJson($company['url'], [
        'document_type' => 'FR',
        'payment_method' => 'CD',
        'notes' => 'Encomenda 1002',
        'lines' => [[
            'description' => 'Entrega ao domicílio',
            'unit_price_minor' => '150000',
            'tax_treatment' => 'IVA_NOR_14',
            'service' => true,
            'quantity' => '1',
        ]],
        'expected_total_minor' => '171000',
    ], apiHeaders($company))->assertCreated();

    $document = FiscalDocument::query()->sole();
    expect($document->document_type)->toBe(FiscalDocumentType::InvoiceReceipt)
        ->and($document->fiscal_series_id)->toBe($company['productionSeries']['FR']->id)
        ->and($document->customer_name)->toBe(FinalConsumer::NAME)
        ->and($document->customer_tax_identification_number)->toBe(FinalConsumer::TAX_IDENTIFICATION_NUMBER)
        ->and($document->payment_method)->toBe(PaymentMethod::DebitCard)
        ->and($document->notes)->toBe('Encomenda 1002')
        ->and($document->gross_total_minor)->toBe(171_000);
    expect($company['series']->fresh()->next_number)->toBe(1);
});

test('a credit note corrects an invoice the AGT accepted', function () {
    $company = apiDocumentCompany(['documents:issue']);
    $invoiceId = $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))->json('data.public_id');
    $invoice = FiscalDocument::query()->where('public_id', $invoiceId)->sole();
    recordAuthoritativeAgtAcceptance($invoice);

    $this->postJson($company['url'], [
        'document_type' => 'NC',
        'references_document_public_id' => $invoiceId,
        'reason' => 'Devolução de mercadoria',
        'lines' => [['line_number' => 1, 'quantity' => '1']],
        'expected_total_minor' => '11400',
    ], apiHeaders($company, 'refund-1001'))->assertCreated();

    $note = FiscalDocument::query()->where('document_type', FiscalDocumentType::CreditNote)->sole();
    expect($note->references_document_id)->toBe($invoice->id)
        ->and($note->adjustment_reason)->toBe('Devolução de mercadoria')
        ->and($note->fiscal_series_id)->toBe($company['productionSeries']['NC']->id)
        ->and($note->gross_total_minor)->toBe(11_400);
});

test('a credit note against an invoice the AGT has not accepted is blocked with its reason', function () {
    $company = apiDocumentCompany();
    $invoiceId = $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))->json('data.public_id');

    $this->postJson($company['url'], [
        'document_type' => 'NC',
        'references_document_public_id' => $invoiceId,
        'reason' => 'Devolução de mercadoria',
        'lines' => [['line_number' => 1, 'quantity' => '1']],
        'expected_total_minor' => '11400',
    ], apiHeaders($company, 'refund-1001'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DOCUMENT_BLOCKED')
        ->assertJsonPath('error.message', 'A AGT ainda não validou este documento. Poderá corrigi-lo quando estiver válido.');

    expect(FiscalDocument::query()->count())->toBe(1);
});

test('a total that differs from the expected one issues nothing and names the field', function () {
    $company = apiDocumentCompany();

    $this->postJson($company['url'], apiInvoicePayload($company, ['expected_total_minor' => '22700']), apiHeaders($company))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => ['expected_total_minor']], 'meta' => ['request_id']]);

    expect(FiscalDocument::query()->count())->toBe(0)
        ->and(DB::table('external_command_operations')->count())->toBe(0)
        ->and($company['productionSeries']['FT']->fresh()->next_number)->toBe(1);
});

test('without a production series nothing is issued, and the same key works once one exists', function () {
    $company = apiDocumentCompany();
    $series = $company['productionSeries']['FT'];
    $series->forceFill(['status' => FiscalSeriesStatus::Closed])->save();

    $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DOCUMENT_BLOCKED')
        ->assertJsonPath('error.message', 'Não há série de Factura disponível. Peça uma série à AGT em facturac.ao.');
    expect(FiscalDocument::query()->count())->toBe(0);

    $series->forceFill(['status' => FiscalSeriesStatus::Open])->save();
    $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))
        ->assertCreated()
        ->assertHeader('Idempotency-Replayed', 'false');
});

test('a homologation series is never used for a production request', function () {
    $company = apiDocumentCompany();
    $company['productionSeries']['FR']->forceFill(['status' => FiscalSeriesStatus::Closed])->save();

    $this->postJson($company['url'], [
        'document_type' => 'FR',
        'payment_method' => 'NU',
        'lines' => [['catalogue_item_public_id' => $company['item']->public_id, 'quantity' => '2']],
        'expected_total_minor' => API_INVOICE_GROSS,
    ], apiHeaders($company))->assertStatus(409)->assertJsonPath('error.code', 'DOCUMENT_BLOCKED');

    expect(FiscalDocument::query()->where('status', FiscalDocumentStatus::Issued)->count())->toBe(0)
        ->and($company['series']->fresh()->next_number)->toBe(1);
});

test('issuing needs its own scope', function (string $scope) {
    $company = apiDocumentCompany([$scope]);

    $this->postJson($company['url'], apiInvoicePayload($company), apiHeaders($company))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'FORBIDDEN');

    expect(FiscalDocument::query()->count())->toBe(0);
})->with(['customers:create', 'catalogue:services:create', 'documents:read']);

test('the request is refused field by field before anything is written', function (array $overrides, string $field) {
    $company = apiDocumentCompany();

    $this->postJson($company['url'], apiInvoicePayload($company, $overrides), apiHeaders($company))
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['fields' => [$field]]]);

    expect(FiscalDocument::query()->count())->toBe(0);
})->with([
    'unsupported type' => [['document_type' => 'RG'], 'document_type'],
    'a price on a catalogue line' => [['lines' => [['catalogue_item_public_id' => '01jx0000000000000000000000', 'quantity' => '1', 'unit_price_minor' => '1']]], 'lines.0.unit_price_minor'],
    'a number for a quantity' => [['lines' => [['catalogue_item_public_id' => '01jx0000000000000000000000', 'quantity' => 2]]], 'lines.0.quantity'],
    'a payment on an invoice' => [['payment_method' => 'NU'], 'payment_method'],
    'a total as a number' => [['expected_total_minor' => 22800], 'expected_total_minor'],
    'no customer on an invoice' => [['customer_public_id' => null], 'customer_public_id'],
    'an unknown field' => [['series_public_id' => 'x'], 'series_public_id'],
    'a one-off line without tax' => [['lines' => [['description' => 'Montagem', 'unit_price_minor' => '1000', 'quantity' => '1']]], 'lines.0.tax_treatment'],
    'an article that does not exist' => [['lines' => [['catalogue_item_public_id' => '01jx0000000000000000000000', 'quantity' => '1']]], 'lines.0.catalogue_item_public_id'],
]);

test('a credential may hold the issue grant only in production', function () {
    $company = apiDocumentCompany();
    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $company['owner']);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $management = IntegrationManagementContext::resolve($request, $company['legalEntity']->workspace_id);

    expect(fn () => app(IntegrationCredentials::class)->create($management, $company['legalEntity']->public_id, AgtEnvironment::Homologation, 'Teste', ['documents:issue']))
        ->toThrow(HttpException::class);
});
