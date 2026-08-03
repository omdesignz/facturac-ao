<?php

use App\Actions\IssueFiscalDocument;
use App\Actions\SaveFiscalDocumentDraft;
use App\AgtConnectionCheckStatus;
use App\AgtConnectionStatus;
use App\AgtOperation;
use App\AgtSubmissionStatus;
use App\Fiscal\Agt\Contracts\AgtGateway;
use App\FiscalDocumentEventType;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Jobs\PollAgtSubmissionStatus;
use App\Jobs\SubmitAgtDocument;
use App\Models\AgtConnection;
use App\Models\AgtConnectionCheck;
use App\Models\AgtSubmission;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;

/**
 * @return array{fingerprint: string}
 */
function provisionPhaseFourKey(string $directory, string $reference): array
{
    $path = $directory.'/'.$reference.'.pem';
    File::ensureDirectoryExists(dirname($path));
    $key = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    if ($key === false || ! openssl_pkey_export($key, $privateKeyPem)) {
        throw new RuntimeException('Unable to create the synthetic phase four key.');
    }

    $details = openssl_pkey_get_details($key);

    if (! is_array($details) || ! is_string($details['key'] ?? null)) {
        throw new RuntimeException('Unable to inspect the synthetic phase four key.');
    }

    File::put($path, $privateKeyPem);

    return ['fingerprint' => hash('sha256', $details['key'])];
}

/**
 * @return array{
 *     user: User,
 *     legal_entity: LegalEntity,
 *     establishment: Establishment,
 *     connection: AgtConnection,
 *     series: FiscalSeries,
 *     draft: FiscalDocument,
 *     key_directory: string,
 *     software_fingerprint: string,
 *     taxpayer_fingerprint: string
 * }
 */
function phaseFourCompany(): array
{
    $keyDirectory = storage_path('framework/testing/agt-phase-four-'.Str::uuid());
    $softwareKey = provisionPhaseFourKey($keyDirectory, 'software/vap-hml');
    $taxpayerKey = provisionPhaseFourKey($keyDirectory, 'taxpayer/5000000001-hml');
    config()->set('agt.signing.key_directory', $keyDirectory);

    $user = User::factory()->withWorkspace('VAP Fase 4')->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('phase-four-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
            json_encode(['phase-four-recovery'], JSON_THROW_ON_ERROR),
        ),
        'two_factor_confirmed_at' => now(),
    ]);
    $workspace = $user->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Comércio, Lda.',
        'tax_identification_number' => '5000000001',
        'main_cae_code' => '62010',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Sede Luanda',
        'code' => 'LAD-001',
    ]);
    $connection = AgtConnection::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'status' => AgtConnectionStatus::Verified,
        'basic_auth_username' => 'agt-hml-user',
        'basic_auth_password' => 'agt-hml-password',
        'product_id' => 'VAP Fatura',
        'product_version' => '0.5.0',
        'software_validation_number' => 'AGT-SW-2026',
        'establishment_number' => 'AO-LAD-001',
        'software_key_reference' => 'software/vap-hml',
        'software_key_fingerprint' => $softwareKey['fingerprint'],
        'taxpayer_key_reference' => 'taxpayer/5000000001-hml',
        'taxpayer_key_fingerprint' => $taxpayerKey['fingerprint'],
        'verified_at' => now(),
    ]);
    $series = FiscalSeries::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'agt_connection_id' => $connection->id,
        'series_code' => 'FT26HML',
        'series_year' => (int) now('Africa/Luanda')->format('Y'),
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalSeriesStatus::Open,
        'contingency_indicator' => FiscalSeriesContingency::Normal,
        'invoicing_method' => 'FESF',
        'agt_first_document_no' => 'FT FT26HML/1',
        'agt_last_document_no' => 'FT FT26HML/500',
        'first_authorized_number' => 1,
        'last_authorized_number' => 500,
        'next_number' => 100,
    ]);
    $draft = app(SaveFiscalDocumentDraft::class)->execute(
        $legalEntity,
        $user,
        [
            'document_type' => FiscalDocumentType::Invoice->value,
            'document_date' => now('Africa/Luanda')->toDateString(),
            'due_date' => now('Africa/Luanda')->addDays(30)->toDateString(),
            'currency_code' => 'AOA',
            'establishment_public_id' => $establishment->public_id,
            'customer_public_id' => null,
            'customer' => [
                'name' => 'Cliente de Homologação, Lda.',
                'tax_identification_number' => '5411111111',
                'country_code' => 'AO',
                'address_line' => 'Talatona, Luanda',
            ],
            'notes' => 'Emissão fiscal de homologação.',
            'lines' => [[
                'operation_type' => 'TB',
                'product_code' => 'SERV-001',
                'product_description' => 'Serviço de consultoria',
                'quantity' => '2',
                'unit_of_measure' => 'un',
                'unit_price' => '1000.00',
                'discount_percentage' => '5',
                'tax_type' => 'IVA',
                'tax_code' => 'NOR',
                'tax_percentage' => '14',
                'tax_exemption_code' => null,
            ]],
        ],
    );

    return [
        'user' => $user,
        'legal_entity' => $legalEntity,
        'establishment' => $establishment,
        'connection' => $connection,
        'series' => $series,
        'draft' => $draft,
        'key_directory' => $keyDirectory,
        'software_fingerprint' => $softwareKey['fingerprint'],
        'taxpayer_fingerprint' => $taxpayerKey['fingerprint'],
    ];
}

/** @var array<string, mixed>|null */
$phaseFourState = null;

/** @return array<string, mixed> */
function phaseFourState(?array $state = null): array
{
    global $phaseFourState;

    if ($state !== null) {
        $phaseFourState = $state;
    }

    if ($phaseFourState === null) {
        throw new LogicException('The Phase 4 test company has not been prepared.');
    }

    return $phaseFourState;
}

/** @return array<string, int> */
function phaseFourPasswordSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

function decodePhaseFourJwsPayload(string $jws): array
{
    $segment = explode('.', $jws)[1] ?? '';
    $segment .= str_repeat('=', (4 - strlen($segment) % 4) % 4);
    $decoded = base64_decode(strtr($segment, '-_', '+/'), true);

    if (! is_string($decoded)) {
        throw new RuntimeException('Unable to decode the fiscal JWS payload.');
    }

    return json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    phaseFourState(phaseFourCompany());
});

afterEach(function () {
    File::deleteDirectory((string) phaseFourState()['key_directory']);
});

test('issuing is one atomic irreversible transaction with exact signed bytes and one sequence', function () {
    $company = phaseFourState();
    Queue::fake();

    $this->actingAs($company['user'])
        ->withSession(phaseFourPasswordSession())
        ->post(route('invoices.issue', $company['draft']), [
            'revision' => $company['draft']->revision,
            'series_public_id' => $company['series']->public_id,
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $document = $company['draft']->fresh();
    $series = $company['series']->fresh();
    $submission = AgtSubmission::query()->firstOrFail();
    $request = json_decode($submission->request_body, true, flags: JSON_THROW_ON_ERROR);

    expect($document->status)->toBe(FiscalDocumentStatus::Issued)
        ->and($document->document_no)->toBe('FT FT26HML/100')
        ->and($document->issue_sequence)->toBe(100)
        ->and($document->frozen_at)->not->toBeNull()
        ->and($document->signable_payload_sha256)->toHaveLength(64)
        ->and($document->document_payload_sha256)->toHaveLength(64)
        ->and(substr_count((string) $document->document_jws, '.'))->toBe(2)
        ->and(array_keys(decodePhaseFourJwsPayload((string) $document->document_jws)))
        ->toBe([
            'companyName',
            'customerCountry',
            'customerTaxID',
            'documentDate',
            'documentNo',
            'documentTotals',
            'documentType',
            'taxRegistrationNumber',
        ])
        ->and($series->next_number)->toBe(101)
        ->and($series->last_issued_number)->toBe(100)
        ->and($series->status)->toBe(FiscalSeriesStatus::InUse)
        ->and($submission->status)->toBe(AgtSubmissionStatus::Pending)
        ->and($submission->request_body_sha256)->toBe(hash('sha256', $submission->request_body))
        ->and($request['numberOfEntries'])->toBe(1)
        ->and($request['documents'][0]['documentNo'])->toBe('FT FT26HML/100')
        ->and(substr_count((string) $request['documents'][0]['jwsDocumentSignature'], '.'))->toBe(2)
        ->and($document->events()->pluck('event_type')->all())
        ->toBe([
            FiscalDocumentEventType::Issued,
            FiscalDocumentEventType::SubmissionQueued,
        ]);

    Queue::assertPushed(
        SubmitAgtDocument::class,
        fn (SubmitAgtDocument $job): bool => $job->submissionId === $submission->id,
    );

    $this->actingAs($company['user'])
        ->withSession(phaseFourPasswordSession())
        ->post(route('invoices.issue', $document), [
            'revision' => $document->revision,
            'series_public_id' => $series->public_id,
        ])
        ->assertForbidden();

    expect($series->fresh()->next_number)->toBe(101)
        ->and(AgtSubmission::query()->count())->toBe(1);
});

test('a changed signing key rolls the whole issuance back without consuming a number', function () {
    $company = phaseFourState();
    Queue::fake();
    $company['connection']->forceFill([
        'taxpayer_key_fingerprint' => str_repeat('0', 64),
    ])->save();

    $this->actingAs($company['user'])
        ->withSession(phaseFourPasswordSession())
        ->from(route('invoices.edit', $company['draft']))
        ->post(route('invoices.issue', $company['draft']), [
            'revision' => $company['draft']->revision,
            'series_public_id' => $company['series']->public_id,
        ])
        ->assertRedirect(route('invoices.edit', $company['draft']))
        ->assertSessionHas('error');

    expect($company['draft']->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($company['draft']->fresh()->document_no)->toBeNull()
        ->and($company['series']->fresh()->next_number)->toBe(100)
        ->and(AgtSubmission::query()->doesntExist())->toBeTrue();

    Queue::assertNothingPushed();
});

test('MFA protects final issuance even when the password was recently confirmed', function () {
    $company = phaseFourState();
    $company['user']->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($company['user'])
        ->withSession(phaseFourPasswordSession())
        ->post(route('invoices.issue', $company['draft']), [
            'revision' => $company['draft']->revision,
            'series_public_id' => $company['series']->public_id,
        ])
        ->assertRedirect(route('settings.security'));

    expect($company['draft']->fresh()->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($company['series']->fresh()->next_number)->toBe(100);
});

test('series synchronization applies the AGT contract without ever rewinding the local cursor', function () {
    $company = phaseFourState();
    Http::preventStrayRequests();
    Http::fake([
        'https://sifphml.minfin.gov.ao/sigt/fe/v1/listarSeries' => Http::response([
            'resultCode' => '0',
            'errorList' => [],
            'seriesResultCount' => 1,
            'seriesInfo' => [[
                'seriesCode' => 'FT26HML',
                'seriesYear' => (int) now('Africa/Luanda')->format('Y'),
                'documentType' => 'FT',
                'seriesStatus' => 'U',
                'seriesCreationDate' => now('Africa/Luanda')->toDateString(),
                'firstDocumentApproved' => 'FT FT26HML/1',
                'lastDocumentApproved' => 'FT FT26HML/750',
                'firstDocumentCreated' => 'FT FT26HML/1',
                'lastDocumentCreated' => 'FT FT26HML/75',
                'invoicingMethod' => 'FESF',
                'seriesContingencyIndicator' => 'N',
            ]],
        ]),
    ]);

    $this->actingAs($company['user'])
        ->withSession(phaseFourPasswordSession())
        ->from(route('agt.connection.show'))
        ->post(route('agt.series.sync'))
        ->assertRedirect(route('agt.connection.show'))
        ->assertSessionHas('success');

    $series = $company['series']->fresh();
    $check = AgtConnectionCheck::query()->latest('id')->firstOrFail();

    expect($series->next_number)->toBe(100)
        ->and($series->last_authorized_number)->toBe(750)
        ->and($series->status)->toBe(FiscalSeriesStatus::InUse)
        ->and($check->operation)->toBe(AgtOperation::SyncSeries)
        ->and($check->status)->toBe(AgtConnectionCheckStatus::Passed)
        ->and($check->request_body_sha256)->toHaveLength(64)
        ->and($check->response_body_sha256)->toHaveLength(64);

    Http::assertSent(function (Request $request): bool {
        $payload = json_decode($request->body(), true);

        return $request->url() === 'https://sifphml.minfin.gov.ao/sigt/fe/v1/listarSeries'
            && is_array($payload)
            && $payload['taxRegistrationNumber'] === '5000000001'
            && $payload['seriesYear'] === now('Africa/Luanda')->format('Y')
            && substr_count((string) $payload['jwsSignature'], '.') === 2;
    });
});

test('the durable outbox reuses frozen bytes and records receipt validation and append-only evidence', function () {
    $company = phaseFourState();
    Queue::fake();
    $submission = app(IssueFiscalDocument::class)->execute(
        $company['draft'],
        $company['user'],
        $company['series']->public_id,
        $company['draft']->revision,
    );
    $frozenRequestBody = $submission->request_body;
    Http::preventStrayRequests();
    Http::fake([
        'https://sifphml.minfin.gov.ao/sigt/fe/v1/registarFactura' => Http::response([
            'requestID' => '123456789012345',
        ]),
    ]);

    (new SubmitAgtDocument($submission->id))->handle(app(AgtGateway::class));

    $submission = $submission->fresh();

    expect($submission->status)->toBe(AgtSubmissionStatus::Received)
        ->and($submission->request_id)->toBe('123456789012345')
        ->and($submission->attempt_count)->toBe(1)
        ->and($submission->attempts()->count())->toBe(1)
        ->and($submission->attempts()->firstOrFail()->request_body)->toBe($frozenRequestBody);

    Http::assertSent(
        fn (Request $request): bool => $request->url()
            === 'https://sifphml.minfin.gov.ao/sigt/fe/v1/registarFactura'
            && $request->body() === $frozenRequestBody,
    );
    Queue::assertPushed(
        PollAgtSubmissionStatus::class,
        fn (PollAgtSubmissionStatus $job): bool => $job->submissionId === $submission->id,
    );

    (new SubmitAgtDocument($submission->id))->handle(app(AgtGateway::class));
    expect($submission->attempts()->count())->toBe(1);

    Http::fake([
        'https://sifphml.minfin.gov.ao/sigt/fe/v1/obterEstado' => Http::response([
            'resultCode' => '0',
            'requestErrorList' => [],
            'documentStatusList' => [[
                'documentNo' => $company['draft']->fresh()->document_no,
                'documentStatus' => 'V',
                'errorList' => [],
            ]],
        ]),
    ]);

    (new PollAgtSubmissionStatus($submission->id))->handle(app(AgtGateway::class));
    $submission = $submission->fresh();

    expect($submission->status)->toBe(AgtSubmissionStatus::Valid)
        ->and($submission->attempt_count)->toBe(2)
        ->and($submission->attempts()->count())->toBe(2)
        ->and($submission->documentEvents()->latest('id')->firstOrFail()->event_type)
        ->toBe(FiscalDocumentEventType::Validated)
        ->and((string) DB::table('agt_submission_attempts')
            ->where('agt_submission_id', $submission->id)
            ->value('request_body'))
        ->not->toContain('FT FT26HML/100');

    $this->actingAs($company['user'])
        ->get(route('agt.submissions.index', ['submission' => $submission->public_id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Agt/Submissions/Index')
            ->where('summary.valid', 1)
            ->where('selected.public_id', $submission->public_id)
            ->where('selected.status', 'valid')
            ->has('selected.attempts', 2)
            ->has('selected.events', 4)
            ->where('guardrails.raw_payloads_exposed', false)
            ->missing('selected.request_body')
            ->missing('selected.attempts.0.request_body')
            ->missing('selected.attempts.0.response_body'));

    expect(fn () => $submission->attempts()->firstOrFail()->update([
        'safe_message' => 'Evidence altered',
    ]))->toThrow(DomainException::class);
});
