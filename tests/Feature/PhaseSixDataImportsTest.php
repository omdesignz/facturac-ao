<?php

use App\DataImportRowStatus;
use App\DataImportStatus;
use App\DataImportType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\DataImport;
use App\Models\DataImportRow;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\DataImportCompleted;
use App\WorkspaceRole;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @return array{user: User, workspace: Workspace, legal_entity: LegalEntity}
 */
function phaseSixCompany(
    WorkspaceRole $role = WorkspaceRole::Owner,
    bool $withMfa = true,
): array {
    $mfaAttributes = $withMfa
        ? [
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('phase-six-secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
                json_encode(['phase-six-recovery'], JSON_THROW_ON_ERROR),
            ),
            'two_factor_confirmed_at' => now(),
        ]
        : [];
    $owner = User::factory()->withWorkspace('VAP Importações')->create($mfaAttributes);
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Importações, Lda.',
        'tax_identification_number' => '5000000601',
    ]);

    if ($role === WorkspaceRole::Owner) {
        $user = $owner;
    } else {
        $user = User::factory()->create([
            'current_workspace_id' => $workspace->id,
            ...$mfaAttributes,
        ]);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    return [
        'user' => $user,
        'workspace' => $workspace,
        'legal_entity' => $legalEntity,
    ];
}

/** @return array<string, int> */
function phaseSixPasswordSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

function phaseSixCsv(string $content, string $name = 'clientes.csv'): File
{
    return File::createWithContent($name, $content);
}

/** @param array{user: User, workspace: Workspace, legal_entity: LegalEntity} $company */
function phaseSixUploadCustomers(
    TestCase $test,
    array $company,
    string $content,
): DataImport {
    $test->actingAs($company['user'])
        ->post(route('imports.store'), [
            'type' => 'customers',
            'source' => 'csv',
            'file' => phaseSixCsv($content),
        ])
        ->assertSessionHasNoErrors();

    return DataImport::query()
        ->where('workspace_id', $company['workspace']->id)
        ->latest('id')
        ->firstOrFail();
}

/** @return array{mapping: array<string, string>} */
function phaseSixCustomerMapping(): array
{
    return [
        'mapping' => [
            'name' => 'nome',
            'tax_identification_number' => 'nif',
            'country_code' => 'pais',
            'address_line' => 'morada',
            'email' => 'email',
            'phone' => 'telefone',
            'is_active' => 'activo',
        ],
    ];
}

test('the import workspace exposes real workflow guardrails and templates', function () {
    $company = phaseSixCompany();

    $this->actingAs($company['user'])
        ->get(route('imports.index'))
        ->assertOk()
        ->assertHeader('cache-control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Imports/Index')
            ->where('company.legal_name', 'VAP Importações, Lda.')
            ->where('types.0.value', 'customers')
            ->where('types.1.value', 'catalogue_items')
            ->where('guardrails.maximum_file_size_mb', 25)
            ->where('guardrails.maximum_rows', 10000)
            ->where('guardrails.source_files_private', true)
            ->where('guardrails.source_deleted_after_commit', true)
            ->where('guardrails.saft_available', false)
            ->where('selected', null));

    $templateResponse = $this->actingAs($company['user'])
        ->get(route('imports.templates.show', DataImportType::Customers))
        ->assertOk()
        ->assertDownload('modelo-clientes.csv')
        ->assertStreamed();

    expect($templateResponse->streamedContent())->toContain('Nome;NIF');
});

test('a CSV upload is privately staged with suggested mappings and encrypted rows', function () {
    $company = phaseSixCompany();
    $dataImport = phaseSixUploadCustomers(
        $this,
        $company,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Nova;5417000001;AO;Rua 1;geral@nova.ao;+244 923 000 001;Sim\nOutra Empresa;5417000002;AO;Rua 2;;;Sim\n",
    );

    expect($dataImport->status)->toBe(DataImportStatus::AwaitingMapping)
        ->and($dataImport->total_rows)->toBe(2)
        ->and($dataImport->headers)->toBe([
            'nome',
            'nif',
            'pais',
            'morada',
            'email',
            'telefone',
            'activo',
        ])
        ->and($dataImport->column_mapping['name'])->toBe('nome')
        ->and($dataImport->column_mapping['tax_identification_number'])->toBe('nif')
        ->and($dataImport->rows()->count())->toBe(2)
        ->and($dataImport->storage_path)->not->toBeNull();

    Storage::disk('local')->assertExists((string) $dataImport->storage_path);

    $row = $dataImport->rows()->orderBy('row_number')->firstOrFail();
    $rawEncryptedPayload = DB::table('data_import_rows')
        ->where('id', $row->id)
        ->value('source_payload');

    expect($row->source_payload['nif'])->toBe('5417000001')
        ->and((string) $rawEncryptedPayload)->not->toContain('5417000001');
});

test('validation reports row errors without writing business records', function () {
    $company = phaseSixCompany();
    $dataImport = phaseSixUploadCustomers(
        $this,
        $company,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Um;5417000001;AO;Rua 1;;;Sim\nEmpresa Duplicada;5417000001;AO;Rua 2;;;Sim\nA;123;ANG;Rua 3;email-invalido;;Talvez\n",
    );

    $this->actingAs($company['user'])
        ->put(route('imports.mapping.update', $dataImport), phaseSixCustomerMapping())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('imports.index', ['import' => $dataImport->public_id]));

    $dataImport->refresh();
    $rows = $dataImport->rows()->orderBy('row_number')->get();

    expect($dataImport->status)->toBe(DataImportStatus::HasErrors)
        ->and($dataImport->valid_rows)->toBe(1)
        ->and($dataImport->invalid_rows)->toBe(2)
        ->and(Customer::query()->doesntExist())->toBeTrue()
        ->and(collect($rows->firstWhere('row_number', 3)?->validation_errors['tax_identification_number'] ?? [])
            ->contains(fn (string $error): bool => str_contains($error, 'linha')))
        ->toBeTrue();
});

test('customer commit creates and updates exactly once then removes the source file', function () {
    Notification::fake();
    $company = phaseSixCompany();
    Customer::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
        'name' => 'Nome Antigo',
        'tax_identification_number' => '5417000002',
    ]);
    $dataImport = phaseSixUploadCustomers(
        $this,
        $company,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Nova;5417000001;AO;Rua 1;geral@nova.ao;+244 923 000 001;Sim\nNome Actualizado;5417000002;AO;Rua 2;;;Sim\n",
    );
    $sourcePath = $dataImport->storage_path;

    $this->actingAs($company['user'])
        ->put(route('imports.mapping.update', $dataImport), phaseSixCustomerMapping())
        ->assertSessionHasNoErrors();

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($company['user'])
            ->withSession(phaseSixPasswordSession())
            ->post(route('imports.commit', $dataImport))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('imports.index', ['import' => $dataImport->public_id]));
    }

    $dataImport->refresh();

    expect($dataImport->status)->toBe(DataImportStatus::Completed)
        ->and($dataImport->created_rows)->toBe(1)
        ->and($dataImport->updated_rows)->toBe(1)
        ->and($dataImport->imported_rows)->toBe(2)
        ->and($dataImport->storage_path)->toBeNull()
        ->and($dataImport->rows()->where('status', DataImportRowStatus::Imported)->count())->toBe(2)
        ->and(Customer::query()->where('legal_entity_id', $company['legal_entity']->id)->count())->toBe(2)
        ->and(Customer::query()->where('tax_identification_number', '5417000002')->value('name'))
        ->toBe('Nome Actualizado');

    Storage::disk('local')->assertMissing((string) $sourcePath);
    Notification::assertSentToTimes($company['user'], DataImportCompleted::class, 1);
});

test('catalogue imports normalize Angolan decimals and tax defaults', function () {
    $company = phaseSixCompany();
    $content = "Código;Tipo;Nome;Descrição;Unidade;Preço unitário;Moeda;Tipo imposto;Código imposto;Taxa imposto;Motivo isenção;Activo\nSERV-001;Serviço;Consultoria mensal;Acompanhamento;UN;1.234,56;AOA;IVA;NOR;14;;Sim\n";

    $this->actingAs($company['user'])
        ->post(route('imports.store'), [
            'type' => 'catalogue_items',
            'source' => 'csv',
            'file' => phaseSixCsv($content, 'catalogo.csv'),
        ])
        ->assertSessionHasNoErrors();
    $dataImport = DataImport::query()->firstOrFail();

    $this->actingAs($company['user'])
        ->put(route('imports.mapping.update', $dataImport), [
            'mapping' => $dataImport->column_mapping,
        ])
        ->assertSessionHasNoErrors();
    $this->actingAs($company['user'])
        ->withSession(phaseSixPasswordSession())
        ->post(route('imports.commit', $dataImport))
        ->assertSessionHasNoErrors();

    $item = CatalogueItem::query()->firstOrFail();

    expect($item->code)->toBe('SERV-001')
        ->and($item->type->value)->toBe('service')
        ->and($item->unit_price_minor)->toBe(123456)
        ->and($item->tax_type)->toBe('IVA')
        ->and($item->tax_code)->toBe('NOR')
        ->and($item->tax_percentage)->toBe('14.00');
});

test('duplicate source columns cannot be mapped twice', function () {
    $company = phaseSixCompany();
    $dataImport = phaseSixUploadCustomers(
        $this,
        $company,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Nova;5417000001;AO;Rua 1;;;Sim\n",
    );
    $mapping = phaseSixCustomerMapping();
    $mapping['mapping']['tax_identification_number'] = 'nome';

    $this->actingAs($company['user'])
        ->put(route('imports.mapping.update', $dataImport), $mapping)
        ->assertSessionHasErrors('mapping');

    expect($dataImport->fresh()->status)->toBe(DataImportStatus::AwaitingMapping)
        ->and(Customer::query()->doesntExist())->toBeTrue();
});

test('source choice, permissions and MFA protect import mutations', function () {
    $viewerCompany = phaseSixCompany(WorkspaceRole::Viewer);

    $this->actingAs($viewerCompany['user'])
        ->post(route('imports.store'), [
            'type' => 'customers',
            'source' => 'csv',
            'file' => phaseSixCsv("Nome;NIF\nCliente;5417000001\n"),
        ])
        ->assertForbidden();

    $ownerCompany = phaseSixCompany(withMfa: false);
    $dataImport = phaseSixUploadCustomers(
        $this,
        $ownerCompany,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Nova;5417000001;AO;Rua 1;;;Sim\n",
    );
    $this->actingAs($ownerCompany['user'])
        ->put(route('imports.mapping.update', $dataImport), phaseSixCustomerMapping())
        ->assertSessionHasNoErrors();
    $this->actingAs($ownerCompany['user'])
        ->withSession(phaseSixPasswordSession())
        ->post(route('imports.commit', $dataImport))
        ->assertRedirect(route('settings.security'));

    expect(Customer::query()->doesntExist())->toBeTrue();

    $this->actingAs($ownerCompany['user'])
        ->post(route('imports.store'), [
            'type' => 'customers',
            'source' => 'excel',
            'file' => phaseSixCsv("Nome;NIF\nCliente;5417000002\n"),
        ])
        ->assertSessionHasErrors('file');
});

test('another workspace cannot discover, validate, commit or cancel an import', function () {
    $ownerCompany = phaseSixCompany();
    $dataImport = phaseSixUploadCustomers(
        $this,
        $ownerCompany,
        "Nome;NIF;País;Morada;Email;Telefone;Activo\nEmpresa Nova;5417000001;AO;Rua 1;;;Sim\n",
    );
    $otherCompany = phaseSixCompany();

    $this->actingAs($otherCompany['user'])
        ->put(route('imports.mapping.update', $dataImport), phaseSixCustomerMapping())
        ->assertNotFound();
    $this->actingAs($otherCompany['user'])
        ->withSession(phaseSixPasswordSession())
        ->post(route('imports.commit', $dataImport))
        ->assertNotFound();
    $this->actingAs($otherCompany['user'])
        ->withSession(phaseSixPasswordSession())
        ->delete(route('imports.destroy', $dataImport))
        ->assertNotFound();

    expect($dataImport->fresh()->status)->toBe(DataImportStatus::AwaitingMapping)
        ->and(DataImportRow::query()->where('data_import_id', $dataImport->id)->count())->toBe(1);
});
