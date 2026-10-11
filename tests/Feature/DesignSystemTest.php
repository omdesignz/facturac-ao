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
    'Pos/Registers/Index',
    'Pos/Sessions/Index',
    'Pos/Sessions/Show',
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

        if (! str_contains($contents, '<DialogPanel')) {
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

test('the top bar is one quiet row of shared menus', function () {
    $layout = (string) file_get_contents(resource_path('js/layouts/AppLayout.vue'));
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));

    expect($layout)->toContain('<AppHeader')
        ->not->toContain('<header')
        ->and($header)->toContain('<NotificationsMenu />')
        ->toContain('<AccountMenu />');

    foreach (['NotificationsMenu.vue', 'AccountMenu.vue', 'AppRail.vue'] as $component) {
        expect((string) file_get_contents(resource_path("js/components/{$component}")))
            ->toContain('menu-panel');
    }

    // Light, dark and system live in the account menu, not as a fourth icon.
    expect((string) file_get_contents(resource_path('js/components/AccountMenu.vue')))
        ->toContain('changeAppearance');
});

test('the phone menu is a floating panel that closes from inside itself', function () {
    $drawer = (string) file_get_contents(resource_path('js/components/SidebarNavigation.vue'));

    // The scrim is light now, so a white close button on it would vanish.
    expect($drawer)->toContain('Fechar navegação')
        ->toContain("\$emit('close')")
        ->toContain('menu-panel')
        ->and((string) file_get_contents(resource_path('js/layouts/AppLayout.vue')))
        ->not->toContain('Fechar navegação');
});

test('the till keeps one key per sale, so a retry can never charge twice', function () {
    $payment = (string) file_get_contents(resource_path('js/components/pos/PosPaymentDialog.vue'));
    $arithmetic = (string) file_get_contents(resource_path('js/lib/pos.ts'));

    expect($payment)
        ->toContain('attemptFor(')
        ->toContain('http.client_key = attempt.key')
        ->toContain('expected_total_minor')
        // A dialog that is charging cannot be dismissed from the keyboard or the scrim.
        ->toContain('if (isSending.value)')
        ->and($arithmetic)
        ->toContain('export function newClientKey')
        ->toContain("from './fiscal-rounding.ts'");
});

test('the till does not animate the things done hundreds of times a day', function () {
    foreach (['PosCatalogue', 'PosCart'] as $component) {
        $contents = (string) file_get_contents(resource_path("js/components/pos/{$component}.vue"));

        expect($contents)
            ->not->toContain('<TransitionGroup')
            ->not->toContain('transition-all');
    }
});

test('the till receipt is an 80mm roll in black on white that prints itself', function () {
    $receipt = (string) file_get_contents(resource_path('js/pages/Pos/Receipt.vue'));

    expect($receipt)
        ->toContain('size: 80mm auto')
        ->toContain('margin: 3mm')
        ->toContain('document.fonts.ready')
        ->toContain('window.print()')
        ->toContain('v-html="document.authenticity.qr_svg"')
        ->not->toContain('AppLayout');
});
