<?php

use App\Actions\IssueFiscalDocument;
use App\Actions\SaveAgtConnection;
use App\Actions\SaveFiscalDocumentDraft;
use App\AgtConnectionStatus;
use App\Analytics\ReceivablesQuery;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\Fiscal\Agt\Contracts\JwsSigner;
use App\Fiscal\Agt\Support\CanonicalJson;
use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\V2_0\FiscalDocumentPayloadBuilder;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentWithholding;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/** @return array{user: User, entity: LegalEntity, establishment: Establishment, connection: AgtConnection, series: FiscalSeries} */
function schemaTwoCompany(): array
{
    Http::preventStrayRequests();
    Queue::fake();
    app()->instance(JwsSigner::class, new class implements JwsSigner
    {
        public function sign(array $payload, string $keyReference): string
        {
            return 'test.'.rtrim(strtr(base64_encode(app(CanonicalJson::class)->encode($payload)), '+/', '-_'), '=').'.signature';
        }

        public function fingerprint(string $keyReference): string
        {
            return hash('sha256', $keyReference);
        }
    });
    $user = User::factory()->withWorkspace()->create();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
    ]);
    $connection = AgtConnection::factory()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'status' => AgtConnectionStatus::Verified,
        'software_key_reference' => 'software/test', 'taxpayer_key_reference' => 'taxpayer/test',
        'software_key_fingerprint' => hash('sha256', 'software/test'),
        'taxpayer_key_fingerprint' => hash('sha256', 'taxpayer/test'),
    ]);
    $series = FiscalSeries::factory()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'establishment_id' => $establishment->id, 'agt_connection_id' => $connection->id,
    ]);

    return compact('user', 'entity', 'establishment', 'connection', 'series');
}

/** @return array<string, mixed> */
function schemaTwoProfile(array $company, array $overrides = []): array
{
    return array_replace([
        'document_type' => 'FT', 'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => null, 'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => null,
        'customer' => ['name' => 'Cliente Exemplo', 'tax_identification_number' => '5411111111', 'country_code' => 'AO', 'address_line' => null],
        'notes' => null, 'references_document_public_id' => null, 'adjustment_reason' => null,
        'lines' => [[
            'operation_type' => 'TB', 'operation_date' => null, 'product_code' => 'PROD-1',
            'product_description' => 'Produto Exemplo', 'quantity' => '1.2345',
            'unit_of_measure' => 'UN', 'unit_price' => '10.00', 'discount_percentage' => '0',
            'tax_type' => 'IVA', 'tax_code' => 'NOR', 'tax_percentage' => '14', 'tax_exemption_code' => null,
        ]],
    ], $overrides);
}

/** @return array<string, mixed> */
function schemaTwoReceiptProfile(array $company, FiscalDocument $source, int $paidMinor): array
{
    return schemaTwoProfile($company, [
        'document_type' => 'RG', 'lines' => [], 'payment_method' => 'NU',
        'payment_date' => now('Africa/Luanda')->toDateString(),
        'settlements' => [['document_public_id' => $source->public_id, 'amount_minor' => $paidMinor]],
    ]);
}

function schemaTwoSource(
    array $company,
    int $netMinor = 10000,
    int $taxMinor = 1400,
    FiscalDocumentType $documentType = FiscalDocumentType::Invoice,
): FiscalDocument {
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id,
        'establishment_id' => $company['establishment']->id,
        'created_by_user_id' => $company['user']->id, 'updated_by_user_id' => $company['user']->id,
        'status' => FiscalDocumentStatus::Valid, 'agt_document_status' => 'V',
        'document_type' => $documentType, 'document_no' => $documentType->value.' ORIGINAL/1',
        'customer_tax_identification_number' => '5411111111',
        'net_total_minor' => $netMinor, 'tax_payable_minor' => $taxMinor, 'gross_total_minor' => $netMinor + $taxMinor,
    ]);
}

test('issuance freezes a schema 2 request with numeric line amounts and matching signed totals', function () {
    $company = schemaTwoCompany();
    $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoProfile($company));
    $submission = app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, $draft->revision);
    $body = json_decode($submission->request_body, true, flags: JSON_THROW_ON_ERROR);
    $signed = explode('.', $body['documents'][0]['jwsDocumentSignature'])[1];
    $signaturePayload = json_decode(base64_decode(strtr($signed, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);

    expect($body['schemaVersion'])->toBe('2.0')
        ->and($body['softwareInfo']['softwareInfoDetail']['signatureVersion'])->toBe(1)
        ->and($body['documents'][0]['lines'][0]['lineNumber'])->toBe(1)
        ->and($body['documents'][0]['lines'][0]['creditAmount'])->toBe(12.34)
        ->and($body['documents'][0]['documentTotals'])->toBe(['grossTotal' => 14.07, 'netTotal' => 12.34, 'taxPayable' => 1.73])
        ->and($signaturePayload['documentTotals'])->toBe($body['documents'][0]['documentTotals'])
        ->and($submission->request_body_sha256)->toBe(hash('sha256', $submission->request_body));
    Http::assertNothingSent();
    Queue::assertPushed(SubmitAgtDocument::class);
});

test('an RG receipt issues without goods and settles net and VAT rather than treating gross as net', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company);
    $company['series']->update(['document_type' => FiscalDocumentType::Receipt, 'series_code' => 'RG26TEST']);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, 11400));
    $submission = app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, $draft->revision);
    $document = json_decode($submission->request_body, true, flags: JSON_THROW_ON_ERROR)['documents'][0];

    expect($document['documentType'])->toBe('RG')
        ->and($document)->not->toHaveKey('lines')
        ->and($document['paymentReceipt']['sourceDocuments'][0])->toBe([
            'creditAmount' => 100, 'lineNo' => 1,
            'sourceDocumentID' => ['OriginatingON' => 'FT ORIGINAL/1', 'documentDate' => $source->document_date->toDateString()],
        ])
        ->and($document['documentTotals'])->toBe(['grossTotal' => 114, 'netTotal' => 100, 'taxPayable' => 14])
        ->and($draft->fresh()->status)->toBe(FiscalDocumentStatus::Issued);
    Http::assertNothingSent();
});

test('credit notes subtract from receipt totals and printed settlement rows', function () {
    $company = schemaTwoCompany();
    $invoice = schemaTwoSource($company, 10000, 0);
    $credit = schemaTwoSource($company, 2000, 0, FiscalDocumentType::CreditNote);
    $company['series']->update(['document_type' => FiscalDocumentType::Receipt, 'series_code' => 'RG26TEST']);
    $profile = schemaTwoReceiptProfile($company, $invoice, 10000);
    $profile['settlements'][] = ['document_public_id' => $credit->public_id, 'amount_minor' => 2000];
    $receipt = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile);
    $submission = app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $company['series']->public_id, $receipt->revision);
    $payload = json_decode($submission->request_body, true, flags: JSON_THROW_ON_ERROR)['documents'][0];
    $rows = app(FiscalDocumentPresenter::class)->settlements($receipt->fresh());

    expect($payload['documentTotals'])->toBe(['grossTotal' => 80, 'netTotal' => 80, 'taxPayable' => 0])
        ->and($payload['paymentReceipt']['sourceDocuments'][1]['debitAmount'])->toBe(20)
        ->and($rows[1]['net_minor'])->toBe(-2000)
        ->and($rows[1]['total_minor'])->toBe(-2000);
});

test('partial receipts conserve every net VAT and withholding cent and keep old payloads frozen', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company, 100, 14);
    FiscalDocumentWithholding::query()->create([
        'workspace_id' => $source->workspace_id, 'legal_entity_id' => $source->legal_entity_id,
        'fiscal_document_id' => $source->id, 'withholding_type' => 'IRT', 'rate_basis_points' => 650,
        'base_minor' => 100, 'amount_minor' => 7,
    ]);
    $company['series']->update(['document_type' => FiscalDocumentType::Receipt, 'series_code' => 'RG26TEST']);
    $receipts = [];
    $originalBody = null;

    foreach ([38, 38, 38] as $paidMinor) {
        $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, $paidMinor));
        $submission = app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, $draft->revision);
        $receipts[] = $draft->fresh('withholdings');
        $originalBody ??= $submission->request_body;
    }

    expect(collect($receipts)->sum('net_total_minor'))->toBe(100)
        ->and(collect($receipts)->sum('tax_payable_minor'))->toBe(14)
        ->and(collect($receipts)->sum(fn (FiscalDocument $receipt): int => (int) $receipt->withholdings->sum('amount_minor')))->toBe(7)
        ->and($receipts[0]->submissions()->first()->request_body)->toBe($originalBody);
    $projected = app(FiscalDocumentPayloadBuilder::class)->document($receipts[0]);
    expect((string) $projected['paymentReceipt']['sourceDocuments'][0]['creditAmount'])->toBe('0.33');
});

test('a competing issued receipt blocks stale allocations without consuming a number', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company, 100, 14);
    $company['series']->update(['document_type' => FiscalDocumentType::Receipt, 'series_code' => 'RG26TEST']);
    $profile = schemaTwoReceiptProfile($company, $source, 114);
    $first = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile);
    $second = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile);
    app(IssueFiscalDocument::class)->execute($first, $company['user'], $company['series']->public_id, $first->revision);
    $nextNumber = $company['series']->fresh()->next_number;

    expect(fn () => app(IssueFiscalDocument::class)->execute($second, $company['user'], $company['series']->public_id, $second->revision))
        ->toThrow(FiscalFinalizationBlocked::class)
        ->and($second->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($company['series']->fresh()->next_number)->toBe($nextNumber);
});

test('unissued receipt drafts do not reduce customer balances', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company);
    app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, 11400));
    $balances = app(ReceivablesQuery::class)->withBalances(FiscalDocument::query())->findOrFail($source->id);

    expect((int) $balances->settled_minor)->toBe(0);
});

test('partial receipt print rows match their frozen net and tax allocation', function () {
    $company = schemaTwoCompany();
    $source = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoProfile($company));
    app(IssueFiscalDocument::class)->execute($source, $company['user'], $company['series']->public_id, $source->revision);
    FiscalDocument::query()->whereKey($source->id)->update(['status' => FiscalDocumentStatus::Valid, 'agt_document_status' => 'V']);
    $source->refresh();
    $company['series']->update(['document_type' => FiscalDocumentType::Receipt, 'series_code' => 'RG26TEST']);

    foreach ([[703, 617, 86], [704, 617, 87]] as [$paidMinor, $netMinor, $taxMinor]) {
        $receipt = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, $paidMinor));
        app(IssueFiscalDocument::class)->execute($receipt, $company['user'], $company['series']->public_id, $receipt->revision);
        $row = app(FiscalDocumentPresenter::class)->settlements($receipt->fresh())[0];

        expect($row['net_minor'])->toBe($netMinor)
            ->and($row['taxes_by_type'])->toBe(['IEC' => 0, 'IVA' => $taxMinor, 'IS' => 0])
            ->and($row['total_minor'])->toBe($paidMinor)
            ->and($row['discount_minor'])->toBe(0);
    }
});

test('printed fiscal discounts use the frozen line amounts', function (string $type) {
    $company = schemaTwoCompany();
    $profile = schemaTwoProfile($company, ['document_type' => $type]);
    $profile['lines'][0]['discount_percentage'] = '20';
    $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile);

    expect(app(FiscalDocumentPresenter::class)->forPrint($draft)['discount_total_minor'])->toBe(247);
})->with(['FT', 'NC']);

test('historical receipt rows without fiscal snapshots retain the source presentation', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company, 10000, 0);
    $receipt = FiscalDocument::factory()->create([
        'workspace_id' => $source->workspace_id, 'legal_entity_id' => $source->legal_entity_id,
        'establishment_id' => $source->establishment_id, 'document_type' => FiscalDocumentType::IssuedReceipt,
        'payload_schema_version' => '1.2', 'document_no' => 'RC ORIGINAL/1', 'status' => FiscalDocumentStatus::Issued,
    ]);
    $receipt->settlements()->create([
        'workspace_id' => $source->workspace_id, 'legal_entity_id' => $source->legal_entity_id,
        'settled_document_id' => $source->id, 'settled_document_no' => $source->document_no, 'amount_minor' => 5000,
    ]);
    $row = app(FiscalDocumentPresenter::class)->settlements($receipt)[0];

    expect($row['net_minor'])->toBe(10000)
        ->and($row['total_minor'])->toBe(5000)
        ->and($receipt->settlements()->first()->net_amount_minor)->toBeNull();
});

test('receipt edit props expose the saved fiscal repartition and derived withholding total', function () {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company);
    FiscalDocumentWithholding::query()->create([
        'workspace_id' => $source->workspace_id, 'legal_entity_id' => $source->legal_entity_id,
        'fiscal_document_id' => $source->id, 'withholding_type' => 'IRT', 'base_minor' => 10000,
        'rate_basis_points' => 650, 'amount_minor' => 650,
    ]);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, 11400));

    $this->actingAs($company['user'])->get(route('invoices.edit', $draft))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('document.totals.net', '100')
            ->where('document.totals.tax', '14')
            ->where('document.totals.gross', '114')
            ->where('document.withholding_total', '6.5')
            ->has('document.lines', 0));
});

test('schema 2 text limits are enforced before draft persistence', function (string $field, string $value) {
    $company = schemaTwoCompany();
    $payload = [
        'document_type' => 'FT', 'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => null, 'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => null,
        'customer' => ['name' => 'Cliente Exemplo', 'tax_identification_number' => '5411111111', 'country_code' => 'AO', 'address_line' => null],
        'lines' => [[
            'operation_type' => 'TB', 'product_code' => 'P', 'product_description' => 'Produto Exemplo',
            'quantity' => '1', 'unit_of_measure' => 'UN', 'unit_price' => '10', 'discount_percentage' => '0',
            'tax' => ['type' => 'IVA', 'code' => 'NOR', 'percentage' => '14', 'exemption_code' => null],
        ]],
    ];
    data_set($payload, $field, $value);

    $this->actingAs($company['user'])->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'customer name' => ['customer.name', str_repeat('C', 201)],
    'description' => ['lines.0.product_description', str_repeat('P', 201)],
    'unit' => ['lines.0.unit_of_measure', str_repeat('U', 21)],
    'adjustment reason' => ['adjustment_reason', str_repeat('R', 61)],
]);

test('legacy outgoing requests are refused before HTTP without rewriting their signed bytes', function (string $connectionSchema, string $bodySchema) {
    $company = schemaTwoCompany();
    $company['connection']->schema_version = $connectionSchema;
    $body = '{"schemaVersion":"'.$bodySchema.'","documents":[]}';
    $result = app(AgtGateway::class)->registerInvoice($company['connection'], $body);

    expect($result->accepted)->toBeFalse()
        ->and($result->retryable)->toBeFalse()
        ->and($result->errorCodes)->toBe(['AGT_SCHEMA_UNSUPPORTED'])
        ->and($result->requestBodySha256)->toBe(hash('sha256', $body));
    Http::assertNothingSent();
})->with([
    'old connection' => ['1.2', '2.0'],
    'old frozen body' => ['2.0', '1.2'],
]);

test('a legacy draft must be resaved before issue and resaving upgrades only that draft', function () {
    $company = schemaTwoCompany();
    $profile = schemaTwoProfile($company);
    $draft = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile);
    $draft->update(['payload_schema_version' => '1.2']);

    expect(fn () => app(IssueFiscalDocument::class)->execute($draft, $company['user'], $company['series']->public_id, $draft->revision))
        ->toThrow(FiscalFinalizationBlocked::class);
    $updated = app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], $profile, $draft);
    expect($updated->payload_schema_version)->toBe('2.0');
});

test('resaving a legacy connection upgrades the schema and requires fresh verification', function () {
    $company = schemaTwoCompany();
    $company['connection']->update(['schema_version' => '1.2']);
    $profile = $company['connection']->only([
        'basic_auth_username', 'basic_auth_password', 'product_id', 'product_version',
        'software_validation_number', 'establishment_number', 'software_key_reference', 'taxpayer_key_reference',
    ]);
    $updated = app(SaveAgtConnection::class)->execute($company['entity'], $company['user'], $profile);

    expect($updated->schema_version)->toBe('2.0')
        ->and($updated->status)->toBe(AgtConnectionStatus::Ready)
        ->and($updated->verified_at)->toBeNull();
    Http::assertNothingSent();
});

test('schema 2 distinguishes retryable processing responses from permanent status errors', function (int $httpStatus, string $code, bool $retryable, bool $wrapped) {
    $company = schemaTwoCompany();
    $entry = ['errorCode' => $code, 'errorDescription' => 'Synthetic AGT response'];
    Http::fake(['*' => Http::response($wrapped ? ['errorEntry' => $entry] : $entry, $httpStatus)]);
    $result = app(AgtGateway::class)->queryInvoiceStatus($company['connection'], $company['entity'], '202600000000001');

    expect($result->successful)->toBeFalse()
        ->and($result->retryable)->toBe($retryable)
        ->and($result->requestErrorCodes)->toBe([$code]);
})->with([
    'processing' => [422, 'E96', true, false],
    'premature wrapped' => [422, 'E97', true, true],
    'wrong taxpayer' => [422, 'E95', false, false],
    'bad structure' => [400, 'E96', false, true],
    'throttled' => [429, 'E98', true, false],
]);

test('a settlement with another currency or customer is refused at the action boundary', function (array $changes) {
    $company = schemaTwoCompany();
    $source = schemaTwoSource($company);
    FiscalDocument::withoutEvents(fn () => $source->forceFill($changes)->save());

    expect(fn () => app(SaveFiscalDocumentDraft::class)->execute($company['entity'], $company['user'], schemaTwoReceiptProfile($company, $source, 11400)))
        ->toThrow(ValidationException::class)
        ->and(FiscalDocument::query()->where('document_type', FiscalDocumentType::Receipt)->count())->toBe(0);
})->with([
    'currency mismatch' => [['currency_code' => 'USD']],
    'customer mismatch' => [['customer_tax_identification_number' => '5411111112']],
]);
