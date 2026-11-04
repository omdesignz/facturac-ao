<?php

use App\Exceptions\SubscriptionRequired;
use App\SubscriptionFeature;
use Database\Seeders\UiDemoSeeder;
use Illuminate\Http\Request;

function pageSource(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

test('the app layout carries the shortcuts, and lists mark their rows for them', function () {
    expect(pageSource('layouts/AppLayout.vue'))->toContain('<KeyboardShortcuts />')
        ->and(pageSource('pages/Documents/Index.vue'))->toContain('data-nav-row="open"')
        ->and(pageSource('pages/Customers/Index.vue'))->toContain('data-nav-row');
});

test('every list with a row to open can be walked with the keyboard', function (string $page) {
    expect(pageSource("pages/{$page}"))->toContain('data-nav-row');
})->with([
    'Quotes/Index.vue',
    'TransportDocuments/Index.vue',
    'Agt/Submissions/Index.vue',
    'Imports/Index.vue',
    'PriceLists/Index.vue',
    'Catalogue/Index.vue',
    'Pos/Sessions/Index.vue',
]);

test('the shortcuts keep off the till, where scanners type letters', function () {
    expect(pageSource('components/KeyboardShortcuts.vue'))
        ->toContain('isTillUrl(page.url)')
        ->toContain("closest('[data-nav-item]')")
        ->toContain('isTypingInFormField(event.target)');
});

test('the palette offers what the open document can do', function () {
    expect(pageSource('components/GlobalSearch.vue'))->toContain("name: 'Nesta página'")
        ->and(pageSource('pages/Documents/Index.vue'))
        ->toContain('usePaletteActions(')
        ->toContain('document.can_send')
        ->toContain('document.can_print');
});

test('a locked feature explains itself on the billing page it lands on', function () {
    $response = (new SubscriptionRequired(SubscriptionFeature::Invoicing))->render(Request::create('/documents/invoices/create'));

    expect($response->getTargetUrl())->toBe(route('billing.show'))
        ->and($response->getSession()?->get('error') ?? session('error'))->toContain('Escolha um plano')
        ->and(pageSource('pages/Billing/Show.vue'))->toContain('<FlashBanner />');
});

test('the composer leads with the customer and the lines', function () {
    $composer = pageSource('pages/Invoices/Create.vue');

    expect(strpos($composer, 'id="add-line"'))
        ->toBeLessThan(strpos($composer, 'Retenção na fonte e cativação'))
        ->and($composer)
        ->toContain('v-show="showDocumentDetails"')
        ->toContain('DOCUMENT_DETAIL_FIELDS')
        ->not->toContain('sem ponto flutuante');
});

test('the demo seeder refuses to run anywhere but locally', function () {
    (new UiDemoSeeder)->run();
})->throws(RuntimeException::class, 'only runs locally');
