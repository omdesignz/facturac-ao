<?php

use App\LegalEntityStatus;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

function bankingCompany(): array
{
    $user = User::factory()->withWorkspace('Banking')->create();
    $entity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $user->current_workspace_id,
        'status' => LegalEntityStatus::Active,
    ]);

    return [$user, $entity];
}

test('an active company can update and clear its own bank details', function () {
    [$user, $entity] = bankingCompany();
    $details = ['bank_name' => 'Banco exemplo', 'bank_account_holder' => 'Empresa Exemplo', 'bank_account_number' => '123456', 'bank_iban' => 'GB82 WEST 1234 5698 7654 32'];
    $this->actingAs($user)->put(route('company.bank-details.update'), $details)->assertSessionHasNoErrors()->assertRedirect(route('onboarding'));
    expect($entity->fresh()->bank_iban)->toBe('GB82WEST12345698765432');
    $this->get(route('onboarding'))->assertInertia(fn (Assert $page) => $page->where('bankDetails.bank_name', 'Banco exemplo')->where('canUpdateBankDetails', true));
    $this->put(route('company.bank-details.update'), array_fill_keys(array_keys($details), ''))->assertSessionHasNoErrors();
    expect($entity->fresh()->bank_name)->toBeNull()->and($entity->fresh()->bank_iban)->toBeNull();
});

test('invalid IBANs and incomplete payment instructions are rejected', function () {
    [$user] = bankingCompany();
    $this->actingAs($user)->put(route('company.bank-details.update'), ['bank_name' => 'Banco', 'bank_account_holder' => 'Empresa', 'bank_iban' => 'GB83WEST12345698765432'])->assertSessionHasErrors('bank_iban');
    $this->put(route('company.bank-details.update'), ['bank_account_number' => '1234'])->assertSessionHasErrors(['bank_name', 'bank_account_holder']);
});

test('viewers cannot replace company payment details', function () {
    [$user, $entity] = bankingCompany();
    WorkspaceMembership::query()->where('user_id', $user->id)->update(['role' => WorkspaceRole::Viewer]);
    $this->actingAs($user)->put(route('company.bank-details.update'), ['bank_name' => 'Wrong'])->assertForbidden();
    expect($entity->fresh()->bank_name)->toBeNull();
});

test('submitted company identifiers cannot redirect a bank update into another workspace', function () {
    [$user, $entity] = bankingCompany();
    [, $other] = bankingCompany();
    $this->actingAs($user)->put(route('company.bank-details.update'), [
        'legal_entity_id' => $other->id, 'workspace_id' => $other->workspace_id,
        'bank_name' => 'Own bank', 'bank_account_holder' => 'Own company', 'bank_account_number' => '1234',
    ])->assertSessionHasNoErrors();
    expect($entity->fresh()->bank_name)->toBe('Own bank')->and($other->fresh()->bank_name)->toBeNull();
});
