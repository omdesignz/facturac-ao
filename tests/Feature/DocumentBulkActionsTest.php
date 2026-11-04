<?php

use App\Fiscal\Documents\FiscalDocumentPrints;
use App\Fiscal\Documents\PrintRecordMismatch;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function bulkFixture(string $workspaceName = 'VAP Lote'): array
{
    $owner = User::factory()->withWorkspace($workspaceName)->create();
    $workspace = $owner->currentWorkspace()->firstOrFail();
    $legalEntity = LegalEntity::factory()->configured()->create(['workspace_id' => $workspace->id]);
    $establishment = Establishment::factory()->headOffice()->create([
        'workspace_id' => $workspace->id,
        'legal_entity_id' => $legalEntity->id,
    ]);

    return compact('owner', 'legalEntity', 'establishment');
}

/**
 * @param  array{owner: User, legalEntity: LegalEntity, establishment: Establishment}  $fixture
 */
function bulkDocument(array $fixture, ?Customer $customer = null, ?string $dueDate = null): FiscalDocument
{
    static $number = 0;
    $number++;

    return FiscalDocument::factory()->issued()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'document_type' => FiscalDocumentType::Invoice,
        'document_no' => "FT LOTE2026/{$number}",
        'customer_id' => $customer?->id,
        'customer_name' => $customer->name ?? 'Consumidor final',
        'status' => FiscalDocumentStatus::Valid,
        'gross_total_minor' => 500_000,
        'due_date' => $dueDate,
    ]);
}

/**
 * @return list<string>
 */
function zipEntries(TestResponse $response): array
{
    $archive = new ZipArchive;
    $archive->open($response->baseResponse->getFile()->getPathname());

    $names = [];

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $names[] = (string) $archive->getNameIndex($index);
    }

    $archive->close();
    sort($names);

    return $names;
}

test('chosen documents download as one zip of their PDFs', function () {
    $fixture = bulkFixture();
    $first = bulkDocument($fixture);
    $second = bulkDocument($fixture);

    $response = $this->actingAs($fixture['owner'])
        ->get(route('documents.bulk-pdf', ['documentos' => [$first->public_id, $second->public_id]]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/zip');

    expect($response->baseResponse->headers->get('Cache-Control'))->toContain('no-store')
        ->and(zipEntries($response))->toHaveCount(2)
        ->and(implode(' ', zipEntries($response)))->toEndWith('.pdf');

    File::delete($response->baseResponse->getFile()->getPathname());
});

test('another company\'s document cannot be slipped into the download', function () {
    $fixture = bulkFixture();
    $mine = bulkDocument($fixture);
    $theirs = bulkDocument(bulkFixture('VAP Outra'));

    $this->actingAs($fixture['owner'])
        ->get(route('documents.bulk-pdf', ['documentos' => [$mine->public_id, $theirs->public_id]]))
        ->assertNotFound();
});

test('a draft has nothing to download', function () {
    $fixture = bulkFixture();
    $draft = FiscalDocument::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'establishment_id' => $fixture['establishment']->id,
        'status' => FiscalDocumentStatus::Draft,
        'document_no' => null,
    ]);

    $this->actingAs($fixture['owner'])
        ->get(route('documents.bulk-pdf', ['documentos' => [$draft->public_id]]))
        ->assertNotFound();
});

test('one request takes at most a page of documents', function () {
    $fixture = bulkFixture();

    $this->actingAs($fixture['owner'])
        ->from(route('documents.index'))
        ->get(route('documents.bulk-pdf', ['documentos' => array_map(strval(...), range(1, 26))]))
        ->assertRedirect(route('documents.index'))
        ->assertSessionHasErrors('documentos');
});

test('a refused print refuses the whole download and names the document', function () {
    $fixture = bulkFixture();
    $document = bulkDocument($fixture);

    $this->mock(FiscalDocumentPrints::class)
        ->shouldReceive('pdf')
        ->andThrow(new PrintRecordMismatch('Impressão adulterada.'));

    $this->actingAs($fixture['owner'])
        ->from(route('documents.index'))
        ->get(route('documents.bulk-pdf', ['documentos' => [$document->public_id]]))
        ->assertRedirect(route('documents.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, (string) $document->document_no));
});

test('the documents page knows whether its user may remind customers', function () {
    $fixture = bulkFixture();

    $this->actingAs($fixture['owner'])
        ->get(route('documents.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canRemindCustomers', true));
});

test('debts opens with the customers chosen on the documents page, and says who it left out', function () {
    $fixture = bulkFixture();
    $customer = fn (string $name): Customer => Customer::factory()->create([
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
        'name' => $name,
    ]);
    $late = $customer('Atrasado Lda');
    $onTime = $customer('Em Dia Lda');
    bulkDocument($fixture, $late, now()->subDays(40)->toDateString());
    bulkDocument($fixture, $onTime, now()->addDays(10)->toDateString());

    $this->actingAs($fixture['owner'])
        ->get(route('debts.index', ['clientes' => [$late->public_id, $onTime->public_id, 'desconhecido']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preselected', [$late->public_id])
            ->where('preselectedSkipped', 2));
});

test('sending a page of reminders in one go is not cut off by the rate limit', function () {
    $route = app('router')->getRoutes()->getByName('debts.reminder.store');

    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});

test('search everywhere goes through the accent-blind helper', function () {
    $direct = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => str_ends_with($file->getFilename(), '.php'))
        ->filter(fn ($file): bool => preg_match('/->(or)?whereLike\(/i', $file->getContents()) === 1
            && $file->getFilename() !== 'AppServiceProvider.php')
        ->map(fn ($file): string => $file->getRelativePathname())
        ->values()
        ->all();

    expect($direct)->toBe([]);
});
