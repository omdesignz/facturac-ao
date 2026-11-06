<?php

use App\Actions\SignInMobileDevice;
use App\Analytics\CollectionFollowUps;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Notification::fake();
    Queue::fake();
    $this->user = User::factory()->withWorkspace()->create();
    $this->entity = LegalEntity::factory()->configured()->create(['workspace_id' => $this->user->current_workspace_id]);
    $this->customer = Customer::factory()->create(['workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id]);
    $this->payload = ['revision' => 0, 'next_contact_on' => '2026-11-07', 'promised_payment_on' => '2026-11-10', 'note' => 'Ligar de manhã — nota privada.', 'completed' => false];
});

test('web follow-ups are shared with the phone and never send a reminder or payment', function () {
    $this->actingAs($this->user)->put(route('customers.follow-up.update', $this->customer), $this->payload)
        ->assertRedirect(route('customers.show', $this->customer))->assertSessionHasNoErrors();
    $this->get(route('customers.show', $this->customer))->assertInertia(fn (Assert $page) => $page
        ->where('followUp.note', $this->payload['note'])->where('followUp.revision', 1)->where('canUpdateFollowUp', true));
    Sanctum::actingAs($this->user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->getJson(route('mobile.v1.customers.show', $this->customer->public_id))->assertOk()
        ->assertJsonPath('data.follow_up.note', $this->payload['note'])->assertJsonPath('data.follow_up.updated_by', $this->user->name);
    $this->getJson(route('mobile.v1.collections'))->assertOk()->assertJsonCount(1, 'data.follow_ups')
        ->assertJsonPath('data.outstanding_minor', 0);
    expect($this->customer->fresh()->toArray())->not->toHaveKey('collection_note');
    expect(Activity::where('log_name', 'collection')->first()->properties->toJson())->not->toContain('nota privada');
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

test('phone updates are repeatable and stale edits cannot overwrite the team', function () {
    Sanctum::actingAs($this->user, [SignInMobileDevice::TOKEN_ABILITY]);
    $url = route('mobile.v1.customers.follow-up.update', $this->customer->public_id);
    $this->putJson($url, $this->payload)->assertOk()->assertJsonPath('data.revision', 1);
    $this->putJson($url, $this->payload)->assertOk()->assertJsonPath('data.revision', 1);
    $this->putJson($url, [...$this->payload, 'note' => 'stale edit'])->assertUnprocessable()->assertJsonValidationErrors('revision');
    expect($this->customer->fresh()->collection_note)->toBe($this->payload['note']);
    expect(Activity::where('log_name', 'collection')->count())->toBe(1);
    $this->putJson($url, [...$this->payload, 'revision' => 1, 'completed' => true])->assertOk()
        ->assertJsonPath('data.completed', true)->assertJsonPath('data.note', $this->payload['note']);
    $this->getJson(route('mobile.v1.collections'))->assertJsonCount(0, 'data.follow_ups');
    $this->putJson($url, [...$this->payload, 'revision' => 2, 'completed' => false])->assertOk()->assertJsonPath('data.revision', 3);
    $this->getJson(route('mobile.v1.collections'))->assertJsonCount(1, 'data.follow_ups');
});

test('viewers can read but not alter team follow-ups', function () {
    WorkspaceMembership::where('user_id', $this->user->id)->update(['role' => WorkspaceRole::Viewer]);
    $this->actingAs($this->user)->put(route('customers.follow-up.update', $this->customer), $this->payload)->assertForbidden();
    Sanctum::actingAs($this->user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->putJson(route('mobile.v1.customers.follow-up.update', $this->customer->public_id), $this->payload)->assertForbidden();
    $this->getJson(route('mobile.v1.customers.show', $this->customer->public_id))->assertOk()->assertJsonPath('data.can_edit', false);
    expect($this->customer->fresh()->collection_revision)->toBe(0);
});

test('follow-ups respect company and legal entity boundaries', function () {
    $other = LegalEntity::factory()->configured()->create(['workspace_id' => $this->entity->workspace_id]);
    $foreign = Customer::factory()->create(['workspace_id' => $other->workspace_id, 'legal_entity_id' => $other->id]);
    Sanctum::actingAs($this->user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->putJson(route('mobile.v1.customers.follow-up.update', $foreign->public_id), $this->payload)->assertNotFound();
    $this->withHeader('X-Company', 'another-company')->putJson(route('mobile.v1.customers.follow-up.update', $this->customer->public_id), $this->payload)
        ->assertConflict()->assertJsonPath('error.code', 'COMPANY_CHANGED');
    expect($foreign->fresh()->collection_revision)->toBe(0);
});

test('the shared queue uses the earliest date and Luanda day including settled customers', function () {
    $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 11, 6)->setTime(23, 30));
    $this->customer->forceFill(['collection_next_contact_on' => '2026-11-08', 'collection_promised_payment_on' => '2026-11-07', 'collection_note' => 'Agreed'])->save();
    $future = Customer::factory()->create(['workspace_id' => $this->entity->workspace_id, 'legal_entity_id' => $this->entity->id]);
    $future->forceFill(['collection_next_contact_on' => '2026-11-09'])->save();
    $foreign = Customer::factory()->create();
    $foreign->forceFill(['collection_next_contact_on' => '2020-01-01'])->save();
    $queue = app(CollectionFollowUps::class)->forCompany($this->entity);
    expect($queue)->toHaveCount(2)
        ->and($queue[0]['customer_public_id'])->toBe($this->customer->public_id)
        ->and($queue[0]['follow_up']['is_due'])->toBeTrue()
        ->and($queue[1]['follow_up']['is_due'])->toBeFalse();
    $this->actingAs($this->user)->get(route('debts.index'))->assertInertia(fn (Assert $page) => $page
        ->has('followUps', 2)->where('followUps.0.follow_up.is_due', true));
});

test('follow-up dates notes and revisions are validated before writing', function (array $override, string $field) {
    Sanctum::actingAs($this->user, [SignInMobileDevice::TOKEN_ABILITY]);
    $this->putJson(route('mobile.v1.customers.follow-up.update', $this->customer->public_id), [...$this->payload, ...$override])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->customer->fresh()->collection_revision)->toBe(0);
})->with([
    [['next_contact_on' => '2026-02-30'], 'next_contact_on'],
    [['promised_payment_on' => 'tomorrow'], 'promised_payment_on'],
    [['note' => str_repeat('a', 2001)], 'note'],
    [['revision' => -1], 'revision'],
    [['completed' => 'wrong'], 'completed'],
]);
