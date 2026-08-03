<?php

use App\FiscalDocumentStatus;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, workspace: Workspace, legal_entity: LegalEntity, establishment: Establishment, customer: Customer}
 */
function phaseThreeCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $owner = User::factory()->withWorkspace('VAP Fase 3')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'VAP Comércio, Lda.',
        'tax_identification_number' => '5000000001',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);
    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Cliente Guardado, Lda.',
        'tax_identification_number' => '5411111111',
        'country_code' => 'AO',
        'address_line' => 'Rua do Cliente, Luanda',
    ]);

    if ($role === WorkspaceRole::Owner) {
        $user = $owner;
    } else {
        $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);
    }

    return compact('user', 'workspace', 'legalEntity', 'establishment', 'customer') + [
        'legal_entity' => $legalEntity,
    ];
}

/** @return array<string, mixed> */
function validFiscalDraft(array $overrides = []): array
{
    $company = phaseThreeCompanyState();

    return [
        'document_type' => 'FT',
        'document_date' => now('Africa/Luanda')->toDateString(),
        'due_date' => now('Africa/Luanda')->addDays(30)->toDateString(),
        'currency_code' => 'AOA',
        'establishment_public_id' => $company['establishment']->public_id,
        'customer_public_id' => $company['customer']->public_id,
        'customer' => [
            'name' => 'Dados manipulados no navegador',
            'tax_identification_number' => '5999999999',
            'country_code' => 'AO',
            'address_line' => 'Endereço manipulado',
        ],
        'notes' => 'Entregar durante a manhã.',
        'lines' => [[
            'operation_type' => 'TB',
            'product_code' => 'ART-001',
            'product_description' => 'Equipamento comercial',
            'quantity' => '2.5',
            'unit_of_measure' => 'un',
            'unit_price' => '100.00',
            'discount_percentage' => '10',
            'tax' => [
                'type' => 'IVA',
                'code' => 'NOR',
                'percentage' => '14',
                'exemption_code' => null,
            ],
        ]],
        ...$overrides,
    ];
}

/** @var array<string, mixed>|null */
$phaseThreeState = null;

/** @return array<string, mixed> */
function phaseThreeCompanyState(?array $state = null): array
{
    global $phaseThreeState;

    if ($state !== null) {
        $phaseThreeState = $state;
    }

    if ($phaseThreeState === null) {
        throw new LogicException('The Phase 3 test company has not been prepared.');
    }

    return $phaseThreeState;
}

beforeEach(function () {
    phaseThreeCompanyState(phaseThreeCompany());
});

test('the composer receives real scoped company data and explicit draft guardrails', function () {
    $company = phaseThreeCompanyState();

    $this->actingAs($company['user'])
        ->get(route('invoices.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Invoices/Create')
            ->where('company.tax_identification_number', '5000000001')
            ->where('establishments.0.public_id', $company['establishment']->public_id)
            ->where('customers.0.public_id', $company['customer']->public_id)
            ->where('guardrails.draft_only', true)
            ->where('guardrails.number_assigned', false)
            ->where('document.public_id', null)
            ->has('operationTypes', 12)
            ->has('taxTreatments', 4));
});

test('a billing member can save a draft with server calculated totals and a trusted customer snapshot', function () {
    $company = phaseThreeCompanyState(phaseThreeCompany(WorkspaceRole::Billing));

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), validFiscalDraft([
            'gross_total_minor' => 1,
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $document = FiscalDocument::query()->firstOrFail();

    expect($document->workspace_id)->toBe($company['workspace']->id)
        ->and($document->legal_entity_id)->toBe($company['legal_entity']->id)
        ->and($document->status)->toBe(FiscalDocumentStatus::Draft)
        ->and($document->document_no)->toBeNull()
        ->and($document->customer_name)->toBe('Cliente Guardado, Lda.')
        ->and($document->customer_tax_identification_number)->toBe('5411111111')
        ->and($document->settlement_total_minor)->toBe(2_500)
        ->and($document->net_total_minor)->toBe(22_500)
        ->and($document->tax_payable_minor)->toBe(3_150)
        ->and($document->gross_total_minor)->toBe(25_650)
        ->and($document->revision)->toBe(1)
        ->and($document->lines()->count())->toBe(1)
        ->and($document->lines()->firstOrFail()->taxes()->firstOrFail()->tax_contribution_minor)->toBe(3_150);

    $activities = Activity::query()->where('log_name', 'fiscal-document')->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()?->properties->get('calculation_sha256'))->toBe($document->calculation_sha256)
        ->and($activities->pluck('properties')->flatten()->implode(' '))
        ->not->toContain('Cliente Guardado')
        ->not->toContain('5411111111');
});

test('updating a draft replaces its lines atomically and increments the revision', function () {
    $company = phaseThreeCompanyState();
    $this->actingAs($company['user'])->post(route('invoices.store'), validFiscalDraft());
    $document = FiscalDocument::query()->firstOrFail();
    $oldLineId = $document->lines()->firstOrFail()->id;
    $payload = validFiscalDraft([
        'lines' => [[
            'operation_type' => 'SG',
            'product_code' => 'SERV-001',
            'product_description' => 'Consultoria',
            'quantity' => '1',
            'unit_of_measure' => 'serviço',
            'unit_price' => '50.00',
            'discount_percentage' => '0',
            'tax' => [
                'type' => 'NS',
                'code' => null,
                'percentage' => '0',
                'exemption_code' => 'M02',
            ],
        ]],
    ]);

    $this->actingAs($company['user'])
        ->put(route('invoices.update', $document), $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('invoices.edit', $document));

    $document->refresh();

    expect($document->revision)->toBe(2)
        ->and($document->net_total_minor)->toBe(5_000)
        ->and($document->tax_payable_minor)->toBe(0)
        ->and($document->lines()->firstOrFail()->id)->not->toBe($oldLineId)
        ->and($document->lines()->firstOrFail()->operation_type->value)->toBe('SG');
});

test('unsupported tax combinations and unknown nested fields are rejected', function () {
    $company = phaseThreeCompanyState();
    $payload = validFiscalDraft();
    $payload['lines'][0]['unexpected'] = 'ignored-by-many-validators';
    $payload['lines'][0]['tax'] = [
        'type' => 'IVA',
        'code' => 'ISE',
        'percentage' => '14',
        'exemption_code' => null,
    ];

    $this->actingAs($company['user'])
        ->post(route('invoices.store'), $payload)
        ->assertSessionHasErrors([
            'lines.0',
            'lines.0.tax.percentage',
            'lines.0.tax.exemption_code',
        ]);

    expect(FiscalDocument::query()->doesntExist())->toBeTrue();
});

test('viewers cannot create drafts and another workspace cannot discover a draft', function () {
    $viewerCompany = phaseThreeCompanyState(phaseThreeCompany(WorkspaceRole::Viewer));

    $this->actingAs($viewerCompany['user'])
        ->post(route('invoices.store'), validFiscalDraft())
        ->assertForbidden();

    $ownerCompany = phaseThreeCompanyState(phaseThreeCompany());
    $this->actingAs($ownerCompany['user'])->post(route('invoices.store'), validFiscalDraft());
    $document = FiscalDocument::query()
        ->where('workspace_id', $ownerCompany['workspace']->id)
        ->firstOrFail();
    $otherCompany = phaseThreeCompanyState(phaseThreeCompany());

    $this->actingAs($otherCompany['user'])
        ->get(route('invoices.edit', $document))
        ->assertNotFound();
});

test('issued documents cannot be changed through the draft endpoint or model', function () {
    $company = phaseThreeCompanyState();
    $this->actingAs($company['user'])->post(route('invoices.store'), validFiscalDraft());
    $document = FiscalDocument::query()->firstOrFail();
    $document->update([
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => 'FT TESTE/1',
        'system_entry_at' => now(),
        'frozen_at' => now(),
        'issued_at' => now(),
    ]);

    $this->actingAs($company['user'])
        ->put(route('invoices.update', $document), validFiscalDraft())
        ->assertForbidden();

    expect(fn () => $document->forceFill(['customer_name' => 'Alterado'])->save())
        ->toThrow(DomainException::class);
});

test('database tenant constraints reject a cross-company establishment', function () {
    $company = phaseThreeCompanyState();
    $otherCompany = phaseThreeCompany();

    FiscalDocument::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $otherCompany['establishment']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
    ]);
})->throws(QueryException::class);
