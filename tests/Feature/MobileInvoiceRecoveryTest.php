<?php

use App\Actions\SignInMobileDevice;
use App\Models\FiscalDocument;
use App\Models\MobileInvoice;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../PosFixtures.php';

beforeEach(function (): void {
    Queue::fake();
    $this->company = posCompany();
    $this->token = $this->company['owner']->createToken('iPhone', [SignInMobileDevice::TOKEN_ABILITY])->plainTextToken;
});

test('a device resolves its earlier draft without writing or issuing anything', function (): void {
    $document = FiscalDocument::factory()->create([
        'workspace_id' => $this->company['legalEntity']->workspace_id,
        'legal_entity_id' => $this->company['legalEntity']->id,
        'establishment_id' => $this->company['establishment']->id,
    ]);
    $made = MobileInvoice::factory()->create([
        'fiscal_document_id' => $document->id,
        'workspace_id' => $document->workspace_id,
        'legal_entity_id' => $document->legal_entity_id,
        'created_by_user_id' => $this->company['owner']->id,
    ]);
    $before = $document->fresh()->getAttributes();
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.recover', $made->client_key))
        ->assertOk()->assertJsonPath('data.public_id', $document->public_id)
        ->assertJsonPath('data.replayed', true)->assertJsonPath('data.is_issued', false)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect($document->fresh()->getAttributes())->toBe($before);
    expect(MobileInvoice::query()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('an unknown request has no result and never creates a document', function (): void {
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.recover', Str::uuid()))
        ->assertOk()->assertExactJson(['data' => null]);
    expect(FiscalDocument::query()->count())->toBe(0);
});

test('recovery hides another company and another person request', function (): void {
    $foreign = MobileInvoice::factory()->create();
    $mine = FiscalDocument::factory()->create([
        'workspace_id' => $this->company['legalEntity']->workspace_id,
        'legal_entity_id' => $this->company['legalEntity']->id,
        'establishment_id' => $this->company['establishment']->id,
    ]);
    $colleague = MobileInvoice::factory()->create([
        'fiscal_document_id' => $mine->id,
        'workspace_id' => $mine->workspace_id,
        'legal_entity_id' => $mine->legal_entity_id,
    ]);
    foreach ([$foreign, $colleague] as $made) {
        $this->withToken($this->token)->getJson(route('mobile.v1.invoices.recover', $made->client_key))
            ->assertOk()->assertExactJson(['data' => null]);
    }
});

test('recovery requires authentication and the displayed company', function (): void {
    $key = (string) Str::uuid();
    $this->getJson(route('mobile.v1.invoices.recover', $key))->assertUnauthorized();
    $this->withToken($this->token)->withHeader('X-Company', 'another-company')
        ->getJson(route('mobile.v1.invoices.recover', $key))->assertConflict()
        ->assertJsonPath('error.code', 'COMPANY_CHANGED');
});

test('invoice recovery storage is scoped by account and legal entity', function (): void {
    $this->withToken($this->token)->getJson(route('mobile.v1.invoices.setup'))->assertOk()
        ->assertJsonPath('data.recovery_scope', implode(':', [
            $this->company['owner']->id, $this->company['legalEntity']->workspace_id, $this->company['legalEntity']->public_id,
        ]));
});
