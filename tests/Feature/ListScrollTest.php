<?php

use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{owner: User, legalEntity: LegalEntity, establishment: Establishment}
 */
function scrollFixture(): array
{
    $owner = User::factory()->withWorkspace('VAP Lista')->create();
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
function scrollDocuments(array $fixture, int $count): void
{
    foreach (range(1, $count) as $number) {
        FiscalDocument::factory()->issued()->create([
            'workspace_id' => $fixture['legalEntity']->workspace_id,
            'legal_entity_id' => $fixture['legalEntity']->id,
            'establishment_id' => $fixture['establishment']->id,
            'document_type' => FiscalDocumentType::Invoice,
            'document_no' => "FT SCROLL2026/{$number}",
        ]);
    }
}

test('the documents list says where the next page is and how many there are', function () {
    $fixture = scrollFixture();
    scrollDocuments($fixture, 27);

    $response = $this->actingAs($fixture['owner'])->get(route('documents.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('documents.data', 25)
        ->where('documents.total', 27)
        ->missing('documents.links'));

    expect($response->viewData('page')['scrollProps']['documents'])
        ->toMatchArray(['pageName' => 'page', 'currentPage' => 1, 'nextPage' => 2, 'previousPage' => null]);
});

test('the next page of documents holds the rest and has no further page', function () {
    $fixture = scrollFixture();
    scrollDocuments($fixture, 27);

    $first = $this->actingAs($fixture['owner'])->get(route('documents.index'))->viewData('page');
    $second = $this->actingAs($fixture['owner'])->get(route('documents.index', ['page' => 2]))->viewData('page');

    $firstIds = array_column($first['props']['documents']['data'], 'public_id');
    $secondIds = array_column($second['props']['documents']['data'], 'public_id');

    expect($secondIds)->toHaveCount(2)
        ->and(array_intersect($firstIds, $secondIds))->toBe([])
        ->and($second['scrollProps']['documents']['nextPage'])->toBeNull()
        ->and($second['scrollProps']['documents']['previousPage'])->toBe(1);
});

test('the customers and catalogue lists grow the same way', function () {
    $fixture = scrollFixture();
    $belongs = [
        'workspace_id' => $fixture['legalEntity']->workspace_id,
        'legal_entity_id' => $fixture['legalEntity']->id,
    ];
    Customer::factory()->count(16)->create($belongs);
    CatalogueItem::factory()->count(16)->create($belongs);

    $this->actingAs($fixture['owner']);

    expect($this->get(route('customers.index'))->viewData('page')['scrollProps']['customers']['nextPage'])->toBe(2)
        ->and($this->get(route('catalogue.index'))->viewData('page')['scrollProps']['items']['nextPage'])->toBe(2);
});

test('a filter starts the list again, and only a read can repeat by itself', function () {
    $source = fn (string $path): string => (string) file_get_contents(resource_path("js/{$path}"));

    expect($source('pages/Documents/Index.vue'))->toContain("reset: ['documents']")
        ->and($source('pages/Customers/Index.vue'))->toContain("reset: ['customers']")
        ->and($source('pages/Catalogue/Index.vue'))->toContain("reset: ['items']")
        ->and($source('lib/connection.ts'))->toContain('failed === null || failed.writes')
        ->and($source('layouts/AppLayout.vue'))->toContain('<ConnectionNotice />')
        ->and($source('layouts/AuthLayout.vue'))->toContain('<ConnectionNotice />');
});

test('nothing fiscal is ever shown before the server confirms it', function () {
    $optimisticScreens = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file): bool => str_contains($file->getContents(), '.optimistic<'))
        ->map(fn ($file): string => $file->getRelativePathname())
        ->sort()
        ->values()
        ->all();

    expect($optimisticScreens)->toBe([
        'components/NotificationsMenu.vue',
        'components/TermsReacceptanceNotice.vue',
        'pages/Catalogue/Index.vue',
        'pages/Customers/Index.vue',
        'pages/Notifications/Index.vue',
    ]);
});
