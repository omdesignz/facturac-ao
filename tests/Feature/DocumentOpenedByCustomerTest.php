<?php

use App\Fiscal\Documents\FiscalDocumentPresenter;
use App\Fiscal\Documents\FiscalDocumentRegister;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use App\Notifications\DocumentOpenedByCustomer;
use App\NotificationTopic;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * @return array{owner: User, document: FiscalDocument}
 */
function openedFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Abertura')->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();

    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    $document = FiscalDocument::factory()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
        'establishment_id' => $establishment->id,
        'document_type' => FiscalDocumentType::Invoice,
        'status' => FiscalDocumentStatus::Valid,
        'document_no' => 'FT 2026/'.fake()->unique()->numberBetween(100, 999),
        'customer_name' => 'Mavinga & Filhos, Lda.',
        'gross_total_minor' => 500_000,
        'document_payload_sha256' => hash('sha256', 'exemplo'),
    ]);

    return compact('owner', 'document');
}

test('the customer opening the link is noted, and the company is told once', function () {
    Notification::fake();
    Cache::flush();
    $fixture = openedFixture();

    $this->get(app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']))->assertOk();

    $document = $fixture['document']->fresh();

    expect($document->open_count)->toBe(1)
        ->and($document->first_opened_by_customer_at)->not->toBeNull()
        ->and($document->last_opened_by_customer_at)->not->toBeNull();

    Notification::assertSentTo($fixture['owner'], DocumentOpenedByCustomer::class);
});

test('opening it again within the hour is the same visit', function () {
    Notification::fake();
    Cache::flush();
    $fixture = openedFixture();
    $url = app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']);

    $this->get($url)->assertOk();
    $this->get($url)->assertOk();

    expect($fixture['document']->fresh()->open_count)->toBe(1);
});

test('a later visit counts, but the company is not told twice', function () {
    Notification::fake();
    Cache::flush();
    $fixture = openedFixture();
    $url = app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']);

    $this->get($url)->assertOk();
    Cache::flush();
    $this->get($url)->assertOk();

    expect($fixture['document']->fresh()->open_count)->toBe(2);

    Notification::assertSentToTimes($fixture['owner'], DocumentOpenedByCustomer::class, 1);
});

test('someone from the company opening the signed link is not the customer', function () {
    Notification::fake();
    Cache::flush();
    $fixture = openedFixture();

    $this->actingAs($fixture['owner'])
        ->get(app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']))
        ->assertOk();

    expect($fixture['document']->fresh()->open_count)->toBe(0);

    Notification::assertNothingSent();
});

test('a refused link records nothing', function () {
    Notification::fake();
    Cache::flush();
    $fixture = openedFixture();

    $this->get(route('documents.print', $fixture['document']))->assertForbidden();
    $this->get(app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']).'&total=1')->assertForbidden();

    expect($fixture['document']->fresh()->open_count)->toBe(0);

    Notification::assertNothingSent();
});

test('the register tells the page when a document was opened', function () {
    Cache::flush();
    $fixture = openedFixture();

    $this->get(app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']))->assertOk();

    $this->actingAs($fixture['owner'])
        ->get(route('documents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('documents.data.0.open_count', 1)
            ->whereNot('documents.data.0.opened_at', null));
});

test('the topic can be switched off like any other', function () {
    expect(NotificationTopic::DocumentOpened->isMandatory())->toBeFalse()
        ->and(NotificationTopic::configurable())->toContain(NotificationTopic::DocumentOpened);
});

test('the document’s history lays the sending and the opening beside the AGT’s steps', function () {
    Cache::flush();
    $fixture = openedFixture();

    FiscalDocument::query()->whereKey($fixture['document']->id)->update([
        'sent_to_customer_at' => now()->subHour(),
        'sent_to_email' => 'contas@mavinga.ao',
        'send_count' => 1,
    ]);

    $this->get(app(FiscalDocumentPresenter::class)->signedUrl($fixture['document']))->assertOk();

    $selected = app(FiscalDocumentRegister::class)->selected(
        $fixture['document']->legalEntity,
        $fixture['document']->public_id,
        true,
    );

    $types = array_column($selected['history'], 'type');

    expect($types)->toContain('sent_to_customer', 'opened_by_customer')
        ->and($types[0])->toBe('opened_by_customer')
        ->and(array_column($selected['history'], 'label'))->toContain('Enviado a contas@mavinga.ao', 'Aberta pelo cliente');
});
