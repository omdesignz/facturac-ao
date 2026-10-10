<?php

use App\Models\LegalEntity;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, legalEntity: LegalEntity}
 */
function assistantNavigationFixture(): array
{
    $user = User::factory()->withWorkspace()->create();
    $legalEntity = LegalEntity::factory()->configured()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);

    return compact('user', 'legalEntity');
}

test('the assistant entry is not shared while the assistant is switched off', function () {
    config(['assistant.enabled' => false]);
    $fixture = assistantNavigationFixture();

    $this->actingAs($fixture['user'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('assistant', null));
});

test('the assistant entry points at the current company in production when it is on', function () {
    config(['assistant.enabled' => true]);
    $fixture = assistantNavigationFixture();
    $expected = route('assistant.show', [
        'workspacePublicId' => $fixture['user']->currentWorkspace()->firstOrFail()->public_id,
        'entityPublicId' => $fixture['legalEntity']->public_id,
        'environment' => 'production',
    ], false);

    $this->actingAs($fixture['user'])
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('assistant.url', $expected)
            ->where('currentWorkspace.legal_entity.public_id', $fixture['legalEntity']->public_id));

    expect($expected)->not->toContain('?');
});

test('the assistant entry is not shared before the company has a legal entity', function () {
    config(['assistant.enabled' => true]);
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('assistant', null));
});

test('the assistant entry costs no queries beyond the company lookup the header already makes', function () {
    config(['assistant.enabled' => false]);
    $fixture = assistantNavigationFixture();
    $this->actingAs($fixture['user']);

    $queries = function (): int {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $this->get(route('dashboard'));

        return $count;
    };

    $off = $queries();
    config(['assistant.enabled' => true]);

    expect($queries())->toBe($off);
});
