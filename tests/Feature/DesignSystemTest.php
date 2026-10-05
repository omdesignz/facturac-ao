<?php

use Illuminate\Support\Facades\File;

test('working screens open on a page header, not a dark banner', function () {
    // The dark rounded slab with a two-line slogan read as a landing page
    // sitting inside the product. Screens open on PageHeader instead.
    $banners = [];

    foreach (File::allFiles(resource_path('js/pages')) as $file) {
        if ($file->getExtension() !== 'vue' || str_starts_with($file->getRelativePathname(), 'Landing')) {
            continue;
        }

        if (preg_match('/<(section|header)\s[^>]*overflow-hidden rounded-3xl bg-brand-950/s', $file->getContents())) {
            $banners[] = $file->getRelativePathname();
        }
    }

    expect($banners)->toBe([]);
});

test('the screens that used a banner now use the shared page header', function (string $page) {
    expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
        ->toContain("import PageHeader from '@/components/PageHeader.vue';")
        ->toContain('<PageHeader');
})->with([
    'Agt/Connection/Show',
    'Agt/Submissions/Index',
    'Billing/Show',
    'Imports/Index',
    'Onboarding',
    'Invoices/Create',
    'Customers/Index',
    'Customers/Show',
    'Debts/Index',
    'Catalogue/Index',
    'PriceLists/Index',
    'Stock/Index',
    'Quotes/Index',
    'TransportDocuments/Index',
    'Recurring/Index',
]);

test('the dashboard and the debts page colour debt age the same way', function (string $page) {
    expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
        ->toContain("import { agingBucketColours, agingShare } from '@/lib/aging';");
})->with(['Dashboard', 'Debts/Index']);

test('headings are set at regular weight and labels stay quiet', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/@utility display \{\s*font-weight: 400;/')
        ->toMatch('/@utility eyebrow \{[^}]*font-weight: 500;[^}]*letter-spacing: 0\.07em;/s');
});

test('the sign-in screens show the brand, not a dark slab or an endorsement', function () {
    $layout = (string) file_get_contents(resource_path('js/layouts/AuthLayout.vue'));

    expect($layout)
        ->toContain('<BrandWordmark')
        ->not->toContain('bg-brand-950 lg:block')
        ->not->toContain('fiscal-grid')
        // A document can be validated by the AGT; the software saying it is
        // "aceite pela AGT" would claim an endorsement it does not hold.
        ->not->toContain('Aceite pela AGT');
});

test('auth buttons use the shared pill roles', function () {
    foreach (File::files(resource_path('js/pages/Auth')) as $file) {
        expect($file->getContents())->not->toContain('bg-brand-700');
    }

    expect((string) file_get_contents(resource_path('js/pages/Auth/Register.vue')))
        ->toContain('rounded-full bg-accent-400');
});

test('no screen falls back to the old brand-blue buttons or labels', function () {
    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        expect($file->getContents())
            ->not->toContain('bg-brand-700')
            ->not->toContain('eyebrow text-brand-700');
    }
});

test('every dialog sits on the shared scrim and panel', function () {
    foreach (File::allFiles(resource_path('js')) as $file) {
        $contents = $file->getContents();

        if (! str_contains($contents, '<DialogPanel') || str_ends_with($file->getFilename(), 'AppLayout.vue')) {
            continue;
        }

        expect($contents)
            ->toContain('dialog-panel')
            ->toContain('dialog-scrim');
    }
});

test('a form dialog opens on its first field, not its close button', function () {
    $dialog = (string) file_get_contents(resource_path('js/components/RecordDialog.vue'));

    expect(strpos($dialog, '<slot />'))->toBeLessThan(strpos($dialog, '<span class="sr-only">Fechar</span>'));
});

test('secondary buttons are outline pills, not rounded boxes', function () {
    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        preg_match_all('/<(?:button|Link|a)\b[^>]*?\sclass="([^"]*)"/s', $file->getContents(), $matches);

        foreach ($matches[1] as $classes) {
            $isOutlinedButton = str_contains($classes, 'ring-1') && str_contains($classes, 'font-semibold');

            expect($isOutlinedButton && preg_match('/\brounded-(xl|lg|md)\b/', $classes) === 1)
                ->toBeFalse("{$file->getRelativePathname()}: {$classes}");
        }
    }
});

test('the product speaks Hanken Grotesk, and the logo never depends on it', function () {
    $styles = (string) file_get_contents(resource_path('css/app.css'));
    $vite = (string) file_get_contents(base_path('vite.config.ts'));

    expect($styles)->toMatch("/--font-sans:\s+'Hanken Grotesk'/")
        ->not->toContain('IBM Plex Sans')
        ->and($vite)->toContain("bunny('Hanken Grotesk'")
        ->and((string) file_get_contents(resource_path('js/components/BrandWordmark.vue')))
        ->toContain('<path');
});
