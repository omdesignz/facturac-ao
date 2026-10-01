<?php

use App\AgtSubmissionStatus;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\AgtConnection;
use App\Models\AgtSubmission;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     workspace: Workspace,
 *     legal_entity: LegalEntity,
 *     establishment: Establishment,
 *     customer: Customer
 * }
 */
function fiscalRegisterCompany(WorkspaceRole $role = WorkspaceRole::Owner): array
{
    $owner = User::factory()->withWorkspace('Livro fiscal')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
        'legal_name' => 'Livro Fiscal, Lda.',
        'tax_identification_number' => '5000000033',
    ]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Sede do livro',
        'code' => 'SEDE',
    ]);
    $customer = Customer::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'name' => 'Cliente Alvo, Lda.',
        'tax_identification_number' => '5411111133',
        'email' => 'financeiro@cliente-alvo.test',
    ]);

    if ($role === WorkspaceRole::Owner) {
        $user = $owner;
    } else {
        $user = User::factory()->create(['current_workspace_id' => $workspace->id]);
        WorkspaceMembership::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    return compact('user', 'workspace', 'establishment', 'customer') + [
        'legal_entity' => $legalEntity,
    ];
}

/** @param array<string, mixed> $overrides */
function fiscalRegisterDocument(array $company, array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
        'establishment_id' => $company['establishment']->id,
        'customer_id' => $company['customer']->id,
        'created_by_user_id' => $company['user']->id,
        'updated_by_user_id' => $company['user']->id,
        'customer_name' => $company['customer']->name,
        'customer_tax_identification_number' => $company['customer']->tax_identification_number,
        'gross_total_minor' => 114_000,
        ...$overrides,
    ]);
}

test('the fiscal register unifies drafts and AGT workflow outcomes', function () {
    $company = fiscalRegisterCompany();
    $draft = fiscalRegisterDocument($company, [
        'document_type' => FiscalDocumentType::CreditNote,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);
    $issued = fiscalRegisterDocument($company, [
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Issued,
        'document_no' => 'FT 2026SEDE/1',
        'issued_at' => now(),
        'frozen_at' => now(),
        'system_entry_at' => now(),
    ]);
    $connection = AgtConnection::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
    ]);
    $submission = AgtSubmission::factory()->create([
        'workspace_id' => $company['workspace']->id,
        'legal_entity_id' => $company['legal_entity']->id,
        'fiscal_document_id' => $issued->id,
        'agt_connection_id' => $connection->id,
        'status' => AgtSubmissionStatus::Valid,
        'request_id' => '202600000000033',
        'safe_message' => 'Documento validado pela AGT.',
        'completed_at' => now(),
    ]);

    $this->actingAs($company['user'])
        ->get(route('documents.index'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Documents/Index')
            ->where('summary.total', 2)
            ->where('summary.draft', 1)
            ->where('summary.active', 0)
            ->where('summary.valid', 1)
            ->where('summary.attention', 0)
            ->where('permissions.create', true)
            ->has('documents.data', 2)
            ->where('documents.data.0.public_id', $issued->public_id)
            ->where('documents.data.0.workflow_status', 'valid')
            ->where('documents.data.0.submission.public_id', $submission->public_id)
            ->where('documents.data.0.can_print', true)
            ->where('documents.data.0.can_send', true)
            ->where('documents.data.1.public_id', $draft->public_id)
            ->where('documents.data.1.workflow_status', 'draft')
            ->where('documents.data.1.can_edit', true)
            ->where('documents.data.1.can_print', false));
});

test('search and fiscal filters are scoped to the current legal entity', function () {
    $company = fiscalRegisterCompany();
    $matching = fiscalRegisterDocument($company, [
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'document_date' => '2026-07-04',
        'customer_name' => 'Cliente Alvo, Lda.',
    ]);
    fiscalRegisterDocument($company, [
        'document_type' => FiscalDocumentType::Invoice,
        'document_date' => '2026-06-01',
        'customer_name' => 'Outro cliente',
    ]);
    $otherCompany = fiscalRegisterCompany();
    fiscalRegisterDocument($otherCompany, [
        'document_type' => FiscalDocumentType::IssuedReceipt,
        'document_date' => '2026-07-04',
        'customer_name' => 'Cliente Alvo, Lda.',
    ]);

    $this->actingAs($company['user'])
        ->get(route('documents.index', [
            'q' => 'Cliente Alvo',
            'family' => 'invoice',
            'type' => FiscalDocumentType::IssuedReceipt->value,
            'establishment' => $company['establishment']->public_id,
            'from' => '2026-07-01',
            'to' => '2026-07-31',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Documents/Index')
            ->where('filters.q', 'Cliente Alvo')
            ->where('filters.family', 'receipt')
            ->where('filters.type', FiscalDocumentType::IssuedReceipt->value)
            ->where('documents.total', 1)
            ->where('documents.data.0.public_id', $matching->public_id));
});

test('viewers can inspect the register but cannot prepare or send documents', function () {
    $company = fiscalRegisterCompany(WorkspaceRole::Viewer);
    $draft = fiscalRegisterDocument($company);

    $this->actingAs($company['user'])
        ->get(route('documents.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Documents/Index')
            ->where('permissions.create', false)
            ->where('documents.data.0.public_id', $draft->public_id)
            ->where('documents.data.0.can_edit', false)
            ->where('documents.data.0.can_send', false));
});

test('invalid and cross-tenant register filters are rejected', function () {
    $company = fiscalRegisterCompany();
    $otherCompany = fiscalRegisterCompany();

    $this->actingAs($company['user'])
        ->from(route('documents.index'))
        ->get(route('documents.index', [
            'status' => 'pretend-valid',
            'establishment' => $otherCompany['establishment']->public_id,
            'from' => '2026-08-10',
            'to' => '2026-08-01',
        ]))
        ->assertRedirect(route('documents.index'))
        ->assertSessionHasErrors(['status', 'establishment', 'to']);
});
