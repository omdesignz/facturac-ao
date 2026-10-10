<?php

use App\Actions\GenerateRecurringInvoices;
use App\FiscalDocumentStatus;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\RecurringInvoice;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Notifications\FiscalDocumentIssued;
use App\WorkspaceRole;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** @return array{user: User, entity: LegalEntity, profile: RecurringInvoice, customer: Customer} */
function securityGateCompany(): array
{
    $user = User::factory()->withWorkspace()->create();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $establishment = Establishment::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id]);
    $customer = Customer::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'email' => 'buyer@example.test']);
    $profile = RecurringInvoice::factory()->create([
        'workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id,
        'establishment_id' => $establishment->id, 'customer_id' => $customer->id,
        'created_by_user_id' => $user->id,
    ]);

    return compact('user', 'entity', 'profile', 'customer');
}

test('manual recurrence neither generates nor closes profiles in another workspace or legal entity', function () {
    Notification::fake();
    $own = securityGateCompany();
    $other = securityGateCompany();
    $siblingEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $own['entity']->workspace_id]);
    $siblingEstablishment = Establishment::factory()->create(['workspace_id' => $siblingEntity->workspace_id, 'legal_entity_id' => $siblingEntity->id]);
    $siblingCustomer = Customer::factory()->create(['workspace_id' => $siblingEntity->workspace_id, 'legal_entity_id' => $siblingEntity->id]);
    $sibling = RecurringInvoice::factory()->create([
        'workspace_id' => $siblingEntity->workspace_id, 'legal_entity_id' => $siblingEntity->id,
        'establishment_id' => $siblingEstablishment->id, 'customer_id' => $siblingCustomer->id,
        'created_by_user_id' => $own['user']->id,
    ]);
    $expired = $other['profile']->replicate();
    $expired->ends_on = now()->subDay();
    $expired->save();

    $this->actingAs($own['user'])->post(route('recurring.run'))->assertRedirect();

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and(FiscalDocument::query()->sole()->legal_entity_id)->toBe($own['entity']->id)
        ->and($other['profile']->fresh()->generated_count)->toBe(0)
        ->and($sibling->fresh()->generated_count)->toBe(0)
        ->and($expired->fresh()->is_active)->toBeTrue();
});

test('explicit recurrence context ignores browser selection and rechecks membership', function () {
    Notification::fake();
    $own = securityGateCompany();
    $other = securityGateCompany();
    $own['user']->update(['current_workspace_id' => $other['entity']->workspace_id]);

    $result = app(GenerateRecurringInvoices::class)->executeFor($own['entity'], $own['user']);
    expect($result['generated'])->toBe(1)
        ->and($other['profile']->fresh()->generated_count)->toBe(0);

    WorkspaceMembership::query()->where('user_id', $own['user']->id)->update(['is_active' => false]);
    expect(fn () => app(GenerateRecurringInvoices::class)->executeFor($own['entity'], $own['user']))
        ->toThrow(HttpException::class);
});

test('the trusted recurring scheduler retains its all-tenant scope', function () {
    Notification::fake();
    securityGateCompany();
    securityGateCompany();

    expect(app(GenerateRecurringInvoices::class)->execute()['generated'])->toBe(2);
});

test('delivery requires a writing role while viewing remains available', function (WorkspaceRole $role, bool $allowed) {
    Notification::fake();
    $company = securityGateCompany();
    WorkspaceMembership::query()->where('user_id', $company['user']->id)->update(['role' => $role]);
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $company['entity']->workspace_id, 'legal_entity_id' => $company['entity']->id,
        'establishment_id' => $company['profile']->establishment_id, 'customer_id' => $company['customer']->id,
        'status' => FiscalDocumentStatus::Issued, 'document_no' => 'FT SECURITY/1',
    ]);

    $this->actingAs($company['user'])->get(route('customers.show', $company['customer']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('documents.data.0.can_send', $allowed));
    $response = $this->post(route('invoices.send', $document));

    if ($allowed) {
        $response->assertRedirect();
        Notification::assertSentOnDemand(FiscalDocumentIssued::class);
        expect($document->fresh()->send_count)->toBe(1);
    } else {
        $response->assertForbidden();
        Notification::assertNothingSent();
        expect($document->fresh()->send_count)->toBe(0);
    }
})->with([
    'viewer' => [WorkspaceRole::Viewer, false],
    'billing' => [WorkspaceRole::Billing, true],
    'accountant' => [WorkspaceRole::Accountant, true],
    'administrator' => [WorkspaceRole::Administrator, true],
    'owner' => [WorkspaceRole::Owner, true],
]);

test('a member of another workspace cannot deliver a document', function () {
    Notification::fake();
    $own = securityGateCompany();
    $other = securityGateCompany();
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $other['entity']->workspace_id, 'legal_entity_id' => $other['entity']->id,
        'establishment_id' => $other['profile']->establishment_id, 'customer_id' => $other['customer']->id,
        'status' => FiscalDocumentStatus::Issued, 'document_no' => 'FT SECURITY/1',
    ]);
    $this->actingAs($own['user'])->post(route('invoices.send', $document))->assertForbidden();
    Notification::assertNothingSent();
});
