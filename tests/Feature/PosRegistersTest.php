<?php

use App\Models\PosRegister;
use App\WorkspaceRole;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/../PosFixtures.php';

test('a manager can create, rename and deactivate a register', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('pos.registers.store'), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Caixa 2',
            'is_active' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $register = PosRegister::query()->where('name', 'Caixa 2')->sole();

    expect($register->establishment_id)->toBe($company['establishment']->id)
        ->and($register->legal_entity_id)->toBe($company['legalEntity']->id);

    $this->actingAs($company['owner'])
        ->put(route('pos.registers.update', $register), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Caixa rápida',
            'is_active' => false,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($register->fresh())
        ->name->toBe('Caixa rápida')
        ->is_active->toBeFalse();
});

test('two registers at one establishment cannot share a name', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('pos.registers.store'), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Caixa 1',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('name');
});

test('someone who does not manage the company cannot set up registers', function (WorkspaceRole $role) {
    $company = posCompany();
    $member = posMember($company, $role);

    $this->actingAs($member)
        ->post(route('pos.registers.store'), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Caixa 9',
            'is_active' => true,
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->put(route('pos.registers.update', $company['register']), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Outro nome',
            'is_active' => true,
        ])
        ->assertForbidden();

    $this->actingAs($member)
        ->delete(route('pos.registers.destroy', $company['register']))
        ->assertForbidden();

    expect(PosRegister::query()->count())->toBe(1);
})->with([
    'accountant' => WorkspaceRole::Accountant,
    'billing' => WorkspaceRole::Billing,
    'viewer' => WorkspaceRole::Viewer,
]);

test('a register from another company does not exist', function () {
    $company = posCompany();
    $other = posCompany();

    $this->actingAs($company['owner'])
        ->put(route('pos.registers.update', $other['register']), [
            'establishment_public_id' => $company['establishment']->public_id,
            'name' => 'Roubada',
            'is_active' => true,
        ])
        ->assertNotFound();

    $this->actingAs($company['owner'])
        ->delete(route('pos.registers.destroy', $other['register']))
        ->assertNotFound();

    expect($other['register']->fresh()->name)->toBe('Caixa 1');
});

test('a register cannot be placed at another company establishment', function () {
    $company = posCompany();
    $other = posCompany();

    $this->actingAs($company['owner'])
        ->post(route('pos.registers.store'), [
            'establishment_public_id' => $other['establishment']->public_id,
            'name' => 'Caixa 3',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('establishment_public_id');
});

test('a register that never ran a shift can be deleted', function () {
    $company = posCompany();

    $this->actingAs($company['owner'])
        ->delete(route('pos.registers.destroy', $company['register']))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(PosRegister::query()->count())->toBe(0);
});

test('a register that has had shifts is not deleted', function () {
    $company = posCompany();
    posOpenSession($company);

    $this->actingAs($company['owner'])
        ->delete(route('pos.registers.destroy', $company['register']))
        ->assertRedirect()
        ->assertSessionHas('error', 'Esta caixa já teve turnos. Desactive-a em vez de a apagar.');

    expect(PosRegister::query()->count())->toBe(1);
});

test('the registers page lists every register with its shifts', function () {
    $company = posCompany();
    posOpenSession($company);
    PosRegister::factory()->inactive()->create([
        'workspace_id' => $company['legalEntity']->workspace_id,
        'legal_entity_id' => $company['legalEntity']->id,
        'establishment_id' => $company['establishment']->id,
        'name' => 'Caixa antiga',
    ]);

    $this->actingAs($company['owner'])
        ->get(route('pos.registers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pos/Registers/Index', false)
            ->has('registers', 2)
            ->where('registers.0.name', 'Caixa 1')
            ->where('registers.0.sessions_count', 1)
            ->where('registers.0.open_session.is_mine', true)
            ->where('registers.1.is_active', false)
            ->where('registers.1.open_session', null)
            ->has('establishments', 1)
            ->where('establishments.0.public_id', $company['establishment']->public_id)
            ->where('canManage', true));
});

test('registers of another company never appear', function () {
    $company = posCompany();
    posCompany();

    $this->actingAs($company['owner'])
        ->get(route('pos.registers.index'))
        ->assertInertia(fn (Assert $page) => $page->has('registers', 1));
});
