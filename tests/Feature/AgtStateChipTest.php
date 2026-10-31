<?php

use App\AgtConnectionStatus;
use App\Models\AgtConnection;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity}
 */
function agtChipFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Cabeçalho')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $workspace->id,
    ]);

    Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'legalEntity');
}

test('every page tells the top bar whether the AGT link is verified', function () {
    $fixture = agtChipFixture();

    AgtConnection::factory()->create([
        'legal_entity_id' => $fixture['legalEntity']->id,
        'status' => AgtConnectionStatus::Verified,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentWorkspace.legal_entity.agt.configured', true)
            ->where('currentWorkspace.legal_entity.agt.verified', true));
});

test('a connection that is saved but not verified shows as configured, not verified', function () {
    $fixture = agtChipFixture();

    AgtConnection::factory()->create([
        'legal_entity_id' => $fixture['legalEntity']->id,
        'status' => AgtConnectionStatus::Ready,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentWorkspace.legal_entity.agt.configured', true)
            ->where('currentWorkspace.legal_entity.agt.verified', false));
});

test('a company with no connection yet is neither configured nor verified', function () {
    $fixture = agtChipFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('customers.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('currentWorkspace.legal_entity.agt.configured', false)
            ->where('currentWorkspace.legal_entity.agt.verified', false));
});

test('the top bar shows the AGT chip', function () {
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));
    $chip = (string) file_get_contents(resource_path('js/components/AgtStateChip.vue'));

    expect($header)->toContain('<AgtStateChip />')
        ->and($chip)->toContain('AGT ligada')
        ->toContain('AGT por verificar');
});
