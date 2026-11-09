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
    'Billing/Show',
    'Imports/Index',
    'Onboarding',
    'Customers/Show',
    'PriceLists/Index',
    'Stock/Index',
    'Recurring/Index',
    'Pos/Registers/Index',
    'Pos/Sessions/Index',
    'Pos/Sessions/Show',
    'Support/Packages',
]);

test('the work surfaces open on the shared title row and run edge to edge', function (string $page) {
    expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
        ->toMatch('/<TitleBar\b|<header\s+class="[^"]*(\bpt-6 pb-5\b|h-\[3\.25rem\])/')
        ->not->toContain('<PageHeader')
        ->not->toContain('page-shell');
})->with(['Documents/Index', 'Debts/Index', 'Invoices/Create', 'Customers/Index', 'Catalogue/Index', 'Quotes/Index', 'TransportDocuments/Index', 'Agt/Submissions/Index']);

test('the title bar is one row of title and actions with the content edge to edge below it', function () {
    expect((string) file_get_contents(resource_path('js/components/TitleBar.vue')))
        ->toContain('pt-6 pb-5')
        ->toContain('text-page-title')
        ->toContain('<slot name="actions" />');
});

test('paragraph wrapping sits in the base layer, so truncate and nowrap still work on a paragraph', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)->toMatch('/@layer base \{\s*h1,\s*h2,\s*h3 \{\s*text-wrap: balance;\s*\}\s*p \{\s*text-wrap: pretty;/')
        ->not->toMatch('/^p \{\s*text-wrap/m');
});

test('the dashboard and the debts page colour debt age the same way', function (string $page) {
    expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
        ->toContain("import { agingBucketColours, agingShare } from '@/lib/aging';");
})->with(['Debts/Index']);

test('headings are set firm and tight, and labels stay quiet in sentence case', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch('/@utility display \{\s*font-weight: 650;\s*letter-spacing: -0\.025em;/')
        ->toMatch('/@utility eyebrow \{[^}]*font-weight: 500;[^}]*letter-spacing: 0;/s')
        ->not->toMatch('/@utility eyebrow \{[^}]*uppercase/s');
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
        ->toContain('<AppButton')
        ->toContain('variant="ink"');
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

test('the product speaks Inter, as the iPhone app does, and the logo never depends on it', function () {
    $styles = (string) file_get_contents(resource_path('css/app.css'));
    $vite = (string) file_get_contents(base_path('vite.config.ts'));

    expect($styles)->toMatch("/--font-sans:\s+'Inter', -apple-system,/")
        ->toContain("'cv11' 1,")
        ->not->toContain('Hanken Grotesk')
        ->not->toContain('IBM Plex Sans')
        ->and($vite)->toContain("bunny('Inter'")
        ->and((string) file_get_contents(resource_path('js/components/BrandWordmark.vue')))
        ->toContain('<path');
});

test('the top bar is one quiet row of shared menus', function () {
    $layout = (string) file_get_contents(resource_path('js/layouts/AppLayout.vue'));
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));

    // On a desktop the account and the company live in the sidebar; the bar
    // keeps only what announces itself: the work timer and notifications.
    expect($layout)->toContain('<AppHeader')
        ->not->toContain('<header')
        ->and($header)->toContain('<NotificationsMenu />')
        ->toContain('<AccountMenu v-if="!isDesktop" />');

    foreach (['NotificationsMenu.vue', 'AccountMenu.vue', 'AppSidebar.vue', 'WorkspaceSwitcher.vue'] as $component) {
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
        ->toContain("import { printDocument as print } from '@/lib/print'")
        ->toContain('await print()')
        ->toContain('v-html="document.authenticity.qr_svg"')
        ->not->toContain('AppLayout');
});

test('the form family is declared once, in the stylesheet', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    foreach (['form-input', 'form-input-sm', 'form-search', 'form-checkbox', 'form-radio', 'field-adorned', 'field-adornment-start', 'field-adornment-end', 'field-label', 'field-hint'] as $utility) {
        expect($css)->toContain("@utility {$utility} {");
    }

    // Text fields are 40px, and 44px under a coarse pointer.
    expect($css)->toMatch('/@utility form-input \{\s*width: 100%;\s*min-block-size: 2\.5rem;/')
        ->toContain('min-block-size: 2.75rem;');
});

test('the package screens use the shared controls, wayfinder routes and the one period switch', function () {
    $screen = (string) file_get_contents(resource_path('js/pages/Support/Packages.vue'));

    expect($screen)
        ->toContain("import RecordDialog from '@/components/RecordDialog.vue';")
        ->toContain("import ToggleSwitch from '@/components/ToggleSwitch.vue';")
        ->toContain("import FormError from '@/components/FormError.vue';")
        ->toContain("import StatusBadge from '@/components/StatusBadge.vue';")
        ->toContain("from '@/routes/support/packages'")
        ->toContain('confirmAction(')
        ->toContain('focusFirstInvalid(')
        ->toContain('aria-invalid')
        // Money travels as a decimal string, never as a float.
        ->toContain('parseAmount(')
        ->not->toContain('/support/pacotes');

    // One switch, native radios, shared by the customer's billing page and the public page.
    $switch = (string) file_get_contents(resource_path('js/components/billing/PeriodSwitch.vue'));

    expect($switch)->toContain('role="radiogroup"')->toContain('type="radio"');

    foreach (['Billing/Show', 'Landing'] as $page) {
        expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
            ->toContain("import PeriodSwitch from '@/components/billing/PeriodSwitch.vue';")
            ->toContain('<PeriodSwitch');
    }
});

test('the package screens keep to the motion and logical-property rules', function () {
    foreach ([
        'pages/Support/Packages.vue',
        'components/billing/PeriodSwitch.vue',
        'lib/packages.ts',
    ] as $file) {
        $contents = (string) file_get_contents(resource_path("js/{$file}"));

        expect($contents)
            ->not->toContain('transition-all')
            ->not->toContain('ease-in ')
            ->not->toMatch('/\b(?:ml|mr|pl|pr|left|right)-(?:\d|\[|auto)/')
            ->not->toMatch('/\bh-screen\b|\bmin-h-screen\b/');
    }

    // The pure helper stays loadable by node's test runner: no imports at all.
    expect((string) file_get_contents(resource_path('js/lib/packages.ts')))->not->toContain('import ');
});

test('a package draft survives the password confirmation but is never saved by itself', function () {
    $screen = (string) file_get_contents(resource_path('js/pages/Support/Packages.vue'));

    $restore = substr($screen, (int) strpos($screen, 'function restoreDraft'), 2600);

    expect($screen)
        ->toContain("const DRAFT_KEY = 'support-package-draft';")
        // Stored just before the save is sent, so a bounce to the confirmation keeps it.
        ->and(strpos($screen, 'storeDraft();'))->toBeLessThan(strpos($screen, 'form.post(storePackage.url()'))
        // Removed when the save settles here, when a validation error comes back,
        // when the dialog is closed, and whenever a success flash arrives.
        ->and($screen)
        ->toContain("page.component === 'Support/Packages'")
        ->toContain('flash?.success')
        ->toContain('restoredDraft.value')
        ->toContain('Confirmou a palavra-passe. Reveja e guarde outra vez.')
        // Restoring only refills the dialog: the save stays a deliberate click.
        ->and($restore)
        ->toContain('openEdit(row)')
        ->not->toContain('submit(')
        ->not->toContain('form.post(')
        ->not->toContain('form.put(');
});

test('the phone gets a tab bar of four words and one ink action, and a header that is just the wordmark', function () {
    $layout = (string) file_get_contents(resource_path('js/layouts/AppLayout.vue'));
    $bar = (string) file_get_contents(resource_path('js/components/MobileTabBar.vue'));
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));

    expect($layout)->toContain('<MobileTabBar')
        ->toContain('max-lg:pb-')
        ->and($bar)->toContain('lg:hidden')
        ->toContain("label: 'Hoje'")
        ->toContain("label: 'Cobrar'")
        ->toContain("label: 'Registos'")
        ->toContain('Mais')
        ->toContain('bg-[var(--ui-ink)]')
        ->toContain('isFocusedPath')
        ->and($header)->toContain('BrandWordmark')
        ->not->toContain('Abrir navegação');
});

test('the desktop navigation is a sidebar that folds to a rail of icons', function () {
    $sidebar = (string) file_get_contents(resource_path('js/components/AppSidebar.vue'));
    $state = (string) file_get_contents(resource_path('js/lib/sidebar.ts'));
    $map = (string) file_get_contents(resource_path('js/lib/top-navigation.ts'));
    $header = (string) file_get_contents(resource_path('js/components/AppHeader.vue'));
    $layout = (string) file_get_contents(resource_path('js/layouts/AppLayout.vue'));

    foreach (['Hoje', 'Vender', 'Cobrar', 'Registos', 'Fiscal', 'Mais'] as $word) {
        expect($map)->toContain("{$word}'");
    }

    // The company, the AGT and the account live in the sidebar; search sits
    // alone in the bar above the page.
    expect($sidebar)->toContain('aria-current')
        ->toContain('Nova factura')
        ->toContain('switchWorkspace')
        ->toContain('PRIMARY_PLACES')
        ->toContain('<AccountMenu')
        ->and($state)->toContain("'vap-sidebar'")
        ->toContain("event.key === '\\\\'")
        ->and($layout)->toContain('<AppSidebar />')
        ->toContain("'ps-[5.5rem] pe-4'")
        ->toContain("'ps-[17rem] pe-4'")
        ->and($header)->not->toContain('AppTopNav')
        ->toContain('<GlobalSearch class="lg:max-w-64" />');

    expect(file_exists(resource_path('js/components/AppTopNav.vue')))->toBeFalse()
        ->and(file_exists(resource_path('js/components/AppRail.vue')))->toBeFalse();
});

test('every working screen shares one width, so the ground never shifts between pages', function () {
    $stray = [];

    foreach (File::allFiles(resource_path('js/pages')) as $file) {
        if ($file->getExtension() !== 'vue') {
            continue;
        }

        $contents = $file->getContents();

        if (str_contains($contents, 'px-4 py-6 sm:px-6 lg:px-8 lg:py-7')
            && preg_match('/mx-auto max-w-(5xl|6xl|7xl|\[100rem\])[" ]/', $contents)) {
            $stray[] = $file->getRelativePathname();
        }
    }

    expect($stray)->toBe([])
        ->and((string) file_get_contents(resource_path('css/app.css')))
        ->toContain('@utility page-shell')
        ->toContain('max-width: 100rem');
});

test('the product has no blue: informational notes use the neutral ramp, and charts lead with ink', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $notice = (string) file_get_contents(resource_path('js/components/SubscriptionNotice.vue'));

    expect($css)->toContain('--color-sky-50: #f5f5f7')
        ->toContain('--viz-primary: #1d1d1f')
        ->and($notice)->not->toMatch('/(bg|text|border)-blue-/');

    foreach (['ChartTrend', 'ChartBars'] as $chart) {
        expect((string) file_get_contents(resource_path("js/components/charts/{$chart}.vue")))
            ->toContain('--viz-primary');
    }
});

test('the invoice, the quote and the transport guide open their cards with one heading', function (string $page) {
    expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
        ->toContain("import SectionHeading from '@/components/SectionHeading.vue';")
        ->toContain('<SectionHeading');
})->with(['Invoices/Create', 'Quotes/Form', 'TransportDocuments/Form']);

test('a list with nothing in it says so in one shape', function (string $page) {
    expect((string) file_get_contents(resource_path("js/{$page}.vue")))
        ->toContain("import EmptyState from '@/components/EmptyState.vue';")
        ->toContain('<EmptyState');
})->with([
    'pages/Quotes/Index',
    'pages/Recurring/Index',
    'pages/PriceLists/Index',
    'pages/Notifications/Index',
    'pages/Stock/Index',
    'pages/Customers/Index',
    'pages/Catalogue/Index',
    'pages/TransportDocuments/Index',
    'pages/Agt/Submissions/Index',
    'pages/Imports/Index',
    'pages/Pos/Sessions/Index',
    'pages/Pos/Registers/Index',
    'components/pos/PosOpenShift',
]);

test('no working screen leaves the date to the browser, so every date reads dd/mm/aaaa', function () {
    $native = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        if ($file->getExtension() === 'vue' && preg_match('/type="(date|datetime-local|time|month)"/', $file->getContents())) {
            $native[] = $file->getRelativePathname();
        }
    }

    expect($native)->toBe([]);
});

test('the export page follows the theme through light-dark, not a dark override that can drop out', function () {
    $page = (string) file_get_contents(resource_path('js/pages/Analytics/Export.vue'));

    expect($page)->toContain('light-dark(')
        ->and($page)->not->toContain(':global(.dark)');
});

test('a count agrees with its noun, never "documento(s)"', function () {
    $hedged = [];
    $files = [...File::allFiles(resource_path('js/pages')), ...File::allFiles(app_path('Http/Controllers'))];

    foreach ($files as $file) {
        if (preg_match('/\p{Ll}\((s|es|ões)\)/u', $file->getContents())) {
            $hedged[] = $file->getRelativePathname();
        }
    }

    expect($hedged)->toBe([])
        ->and((string) file_get_contents(resource_path('js/lib/plural.ts')))
        ->toContain('count === 1 ? one : many');
});

test('status columns are wide enough for the whole AGT and collection labels', function () {
    $documents = (string) file_get_contents(resource_path('js/pages/Documents/Index.vue'));

    expect($documents)->toContain('_7.75rem_minmax(10rem,1.5fr)]')
        ->and($documents)->toContain('class="mt-1 flex flex-wrap items-center justify-between gap-x-3 gap-y-1"');
});

test('the debts table drops its age columns while the reminder sits beside it, instead of clipping them', function () {
    $debts = (string) file_get_contents(resource_path('js/pages/Debts/Index.vue'));

    expect(substr_count($debts, 'max-lg:hidden xl:max-[100rem]:hidden'))->toBeGreaterThanOrEqual(8)
        ->and($debts)->toContain('xl:grid-cols-[2.75rem_minmax(11rem,1fr)_6.5rem_9.5rem] min-[100rem]:grid-cols-');
});

test('plan cards share one surface and the button recipe, and say why checkout is off', function () {
    $billing = (string) file_get_contents(resource_path('js/pages/Billing/Show.vue'));

    expect($billing)->not->toContain('bg-brand-950')
        ->and($billing)->toContain('checkoutBlockedReason')
        ->and($billing)->toContain('md:grid-cols-[repeat(auto-fit,minmax(15rem,1fr))]')
        ->and(substr_count($billing, 'buttonClass('))->toBeGreaterThanOrEqual(2);
});

test('a bar chart with one category stays ink', function () {
    expect((string) file_get_contents(resource_path('js/components/charts/ChartBars.vue')))
        ->toContain('!props.categorical || props.bars.length < 2');
});

test('invoice lines are a dense grid from a narrow sheet up, with their fiscal detail on one row beneath', function () {
    $composer = (string) file_get_contents(resource_path('js/pages/Invoices/Create.vue'));

    expect($composer)
        ->toContain('class="hidden @min-[36rem]/invoice-lines:block"')
        ->toContain('@min-[36rem]/invoice-lines:hidden')
        ->toContain(':aria-label="`Linha ${index + 1}`"')
        ->toContain('lineDetailGrid')
        ->toContain('LINE_ERROR_PATHS')
        ->not->toContain('min-w-[58rem]')
        ->not->toContain('@min-[58rem]/invoice-lines');
});

test('lists that squeeze on a phone fold their secondary columns under the first', function () {
    $quotes = (string) file_get_contents(resource_path('js/pages/Quotes/Index.vue'));
    $monitor = (string) file_get_contents(resource_path('js/pages/Agt/Submissions/Index.vue'));

    expect(substr_count($quotes, 'max-sm:hidden'))->toBeGreaterThanOrEqual(6)
        ->and($quotes)->toContain('class="mt-0.5 truncate text-xs text-[var(--ui-ink-2)] sm:hidden"')
        ->and($monitor)->toContain('xl:max-2xl:block')
        ->and($monitor)->toContain('<td class="min-w-[16.5rem]">');
});

test('money in a page figure carries its currency, and counters pair up on a phone', function () {
    expect((string) file_get_contents(resource_path('js/components/PageStat.vue')))->toContain('unit?: string')
        ->and(substr_count((string) file_get_contents(resource_path('js/pages/Customers/Show.vue')), ':unit="currencyLabel(props.currencyCode)"'))->toBe(4)
        ->and((string) file_get_contents(resource_path('js/pages/Saft/Index.vue')))->toContain('grid grid-cols-2 gap-3')
        ->and((string) file_get_contents(resource_path('js/pages/Analytics/Index.vue')))
        ->toContain('grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4')
        ->toContain(":tone=\"summary.paid_minor > 0 ? 'good' : 'neutral'\"");
});

test('a quote with no lines says so and offers the first one', function () {
    expect((string) file_get_contents(resource_path('js/pages/Quotes/Form.vue')))
        ->toContain('title="Ainda sem linhas"')
        ->toContain('v-if="form.lines.length === 0"');
});

test('till dates read dd/mm/aaaa like the rest of the app', function () {
    expect((string) file_get_contents(resource_path('js/lib/pos-ui.ts')))
        ->toContain("day: '2-digit',\n    month: '2-digit',");
});

test('invoice prices read 9 800,00 and reach the server as 9800.00', function () {
    expect((string) file_get_contents(resource_path('js/pages/Invoices/Create.vue')))
        ->toContain(':value="priceValue(line)"')
        ->toContain('unit_price: canonicalDecimal(line.unit_price)')
        ->toContain('quantity: canonicalDecimal(line.quantity)')
        ->toContain('discount_percentage: canonicalDecimal(')
        ->not->toContain('v-model="line.unit_price"');
});

test('the customer document page never scrolls sideways on a phone, and prints unchanged', function () {
    expect((string) file_get_contents(resource_path('js/pages/Documents/Print.vue')))
        ->toContain('overflow-x-auto overscroll-x-contain print:overflow-visible')
        ->toContain('max-sm:grid-cols-[auto_minmax(0,1fr)]');
});

test('printed quantities, prices and dates read the way an Angolan reader writes them', function () {
    $invoice = (string) file_get_contents(resource_path('js/pages/Documents/Print.vue'));
    $guide = (string) file_get_contents(resource_path('js/pages/TransportDocuments/Print.vue'));

    expect($invoice)->toContain('{{ formatQuantity(line.quantity) }}')
        ->not->toContain('{{ line.quantity }}')
        ->and($guide)->toContain('{{ formatQuantity(line.quantity) }}')
        ->toContain('{{ formatPrice(line.unit_price) }}')
        ->toContain('return `${day}/${month}/${year}`;')
        ->not->toContain("value.split('T')[0]");
});

test('tax and discount rates print with a comma, never "14.00%"', function () {
    expect((string) file_get_contents(resource_path('js/lib/rate.ts')))->toContain("new Intl.NumberFormat('pt-AO'");

    foreach (['Pos/Receipt', 'Documents/Print'] as $page) {
        expect((string) file_get_contents(resource_path("js/pages/{$page}.vue")))
            ->toContain("import { formatRate } from '@/lib/rate';")
            ->not->toMatch('/\{\{\s*[\w.]*rate\s*\}\}%/');
    }
});

test('the assistant names a document by its date as dd/mm/aaaa', function () {
    expect((string) file_get_contents(resource_path('js/pages/Assistant/Index.vue')))
        ->toContain('calendarDay(document.document_date)')
        ->toContain('return `${day}/${month}/${year}`;');
});

test('the document filter row shows on a phone that it scrolls', function () {
    expect((string) file_get_contents(resource_path('js/pages/Documents/Index.vue')))
        ->toContain('max-md:[mask-image:linear-gradient(to_right,#000_82%,transparent)]');
});

test('every trend chart labels its value axis in amounts, and type mixes show shares in one ink', function (string $page) {
    $source = (string) file_get_contents(resource_path("js/pages/{$page}.vue"));

    expect($source)
        ->toContain(':tick-format="axisAmount"')
        ->not->toContain('categorical');
})->with(['Dashboard', 'Analytics/Index', 'Customers/Show']);

test('line chart keys come from the shared legend, which names only the lines drawn', function (string $page) {
    $source = (string) file_get_contents(resource_path("js/pages/{$page}.vue"));

    expect($source)
        ->toContain('<ChartLegend :items="trendLegend" />')
        ->toContain('point.comparison > 0')
        ->toContain('shares');
})->with(['Analytics/Index', 'Customers/Show']);

test('the sign-in screenshot is drawn from 2x pixels in its frame shape, and the hidden theme never downloads', function () {
    $layout = (string) file_get_contents(resource_path('js/layouts/AuthLayout.vue'));

    expect($layout)
        ->toContain('/images/auth/today-light-2400.webp 2400w')
        ->toContain('/images/auth/today-dark-2400.webp 2400w')
        ->toContain('width="2400"')
        ->toContain('height="1500"')
        ->toContain('loading="lazy"')
        ->not->toContain('today-web-');

    foreach (['light', 'dark'] as $theme) {
        foreach ([1200, 2400] as $width) {
            $size = getimagesize(public_path("images/auth/today-{$theme}-{$width}.webp"));

            expect($size[0])->toBe($width)
                ->and($size[0] / $size[1])->toBe(1.6);
        }
    }
});
