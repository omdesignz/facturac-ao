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
]);

test('headings are set at regular weight and labels stay quiet', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/@utility display \{\s*font-weight: 400;/')
        ->toMatch('/@utility eyebrow \{[^}]*font-weight: 500;[^}]*letter-spacing: 0\.07em;/s');
});
