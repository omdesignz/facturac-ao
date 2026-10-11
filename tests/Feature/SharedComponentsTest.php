<?php

use Illuminate\Support\Facades\File;

function component(string $path): string
{
    return (string) file_get_contents(resource_path("js/{$path}"));
}

test('the lifecycle track lays itself out by its own width and never overflows its box', function () {
    $lifecycle = component('components/DocumentLifecycle.vue');

    expect($lifecycle)
        ->toContain('@container')
        ->toContain('@lg:grid-flow-col')
        ->toContain('aria-current')
        // The old track pinned nowrap labels at percentages, which collided
        // and pushed the dashboard sideways at 375px.
        ->not->toContain('whitespace-nowrap')
        ->not->toContain('-translate-x-full');
});

test('the trend chart measures its container and does not read layout on every pointer move', function () {
    $chart = component('components/charts/ChartTrend.vue');

    expect($chart)
        ->toContain('useElementSize')
        ->toContain('touch-pan-y')
        ->not->toContain('touch-none')
        ->not->toContain('const WIDTH = 720')
        // The rect is read once per interaction, not inside every move.
        ->toContain('cachedLeft');
});

test('select and date inputs forward validity to the real control', function (string $file) {
    $input = component($file);

    expect($input)
        ->toContain('invalid')
        ->toContain('describedby');
})->with(['components/SelectInput.vue', 'components/DateInput.vue']);

test('the select input routes class and style to the wrapper and the rest to the input', function () {
    $select = component('components/SelectInput.vue');

    expect($select)
        ->toContain('inheritAttrs: false')
        ->toContain('v-bind="wrapperAttrs()"')
        ->toContain('v-bind="inputAttrs()"');
});

test('form errors can be pointed at by the field they describe', function () {
    expect(component('components/FormError.vue'))->toContain(':id="id"');
});

test('the focus helper looks for the first control marked invalid', function () {
    expect(component('lib/focus.ts'))
        ->toContain('[aria-invalid="true"]')
        ->toContain('nextTick');
});

test('banners stay mounted as live regions so a message is announced when it arrives', function () {
    expect(component('components/FlashBanner.vue'))
        ->toContain('aria-live="polite"')
        ->toContain("block: 'nearest'");

    expect(component('components/TermsReacceptanceNotice.vue'))
        ->toContain('aria-live="polite"');
});

test('the work-session countdown is not a live region and announces only at 60, 30 and 10 seconds', function () {
    $timer = component('components/WorkSessionTimer.vue');

    expect($timer)
        ->not->toContain('aria-live="assertive"')
        ->toContain('[10, 30, 60]')
        ->toContain(':initial-focus="renewButton"');
});

test('dialogs travel as a bottom sheet on phones and leave on the house curve', function (string $file) {
    $dialog = component($file);

    expect($dialog)
        ->toContain('max-sm:translate-y-full')
        ->toContain('overscroll-contain')
        ->not->toContain('ease-in ')
        ->not->toContain('ease-in"');
})->with(['components/ConfirmDialog.vue', 'components/RecordDialog.vue']);

test('the cookie banner scrolls inside itself on a short screen', function () {
    expect(component('components/CookieConsent.vue'))
        ->toContain('overflow-y-auto')
        ->toContain('safe-area-inset-bottom')
        ->not->toContain('ease-in');
});

/**
 * Every Vue file under resources/js except the folders another screen owns.
 *
 * @return list<array{path: string, contents: string}>
 */
function formScreens(): array
{
    $excluded = ['pages/Pos/', 'components/pos/', 'pages/Landing.vue', 'pages/Support/'];
    $files = [];

    foreach (File::allFiles(resource_path('js')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if ($file->getExtension() !== 'vue') {
            continue;
        }

        foreach ($excluded as $prefix) {
            if (str_starts_with($path, $prefix)) {
                continue 2;
            }
        }

        $files[] = ['path' => $path, 'contents' => $file->getContents()];
    }

    return $files;
}

test('the select input is the only select in the product', function () {
    foreach (formScreens() as $screen) {
        if ($screen['path'] === 'components/SelectInput.vue') {
            continue;
        }

        expect(preg_match('/<select[\s>]/', $screen['contents']) === 1)->toBeFalse($screen['path']);
    }
});

test('the select is a menu panel with a check, a filter past eight options and an empty state', function () {
    expect(component('components/SelectInput.vue'))
        ->toContain('menu-panel')
        ->toContain('menu-item')
        ->toContain('role="listbox"')
        ->toContain('role="option"')
        ->toContain('<Check')
        ->toContain('max-h-[min(20rem,50dvh)]')
        ->toContain('overscroll-contain')
        ->toContain('FILTER_THRESHOLD = 8')
        ->toContain('Nenhum resultado')
        ->toContain('form-input')
        // Escape must not also close the dialog the field sits in.
        ->toContain('event.preventDefault();');
});

test('the invalid look belongs to the form-input utility, not to the pages', function () {
    foreach (formScreens() as $screen) {
        preg_match_all('/<(?:input|textarea)\b[^>]*?\sclass="([^"]*)"/s', $screen['contents'], $matches);

        foreach ($matches[1] as $classes) {
            expect(preg_match('/outline-rose|ring-rose|border-rose|aria-invalid:/', $classes) === 1)
                ->toBeFalse("{$screen['path']}: {$classes}");
        }
    }

    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toMatch("/@utility form-input \{.*?\[aria-invalid='true'\]/s")
        ->toContain('&[readonly]:not(:disabled)')
        ->toContain('&:disabled')
        ->toContain('field-sizing: content')
        ->toContain('&:-webkit-autofill')
        ->toContain('@media (pointer: coarse)');
});

test('text fields use the shared recipe rather than their own outline', function () {
    foreach (formScreens() as $screen) {
        preg_match_all('/<(?:input|textarea)\b[^>]*?\sclass="([^"]*)"/s', $screen['contents'], $matches);

        foreach ($matches[1] as $classes) {
            if (str_contains($classes, 'form-search') || str_contains($classes, 'bg-transparent')) {
                continue;
            }

            expect(preg_match('/\b(?:outline-zinc-\d+|ring-zinc-\d+)\b/', $classes) === 1)
                ->toBeFalse("{$screen['path']}: {$classes}");
        }
    }
});

test('every checkbox and radio is the drawn control', function () {
    foreach (formScreens() as $screen) {
        preg_match_all('/<input\b[^>]*?\stype="(checkbox|radio)"[^>]*>/s', $screen['contents'], $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            // A visually hidden input that drives a control drawn by its sibling
            // (a segmented switch) is not a tick and is allowed.
            if (str_contains($match[0], 'sr-only')) {
                continue;
            }

            $needle = $match[1] === 'checkbox' ? 'form-checkbox' : 'form-radio';

            expect(str_contains($match[0], $needle))->toBeTrue("{$screen['path']}: {$match[0]}");
        }
    }

    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('@utility form-checkbox')
        ->toContain('@utility form-radio')
        ->toContain('appearance: none')
        ->toContain('&:indeterminate')
        ->toContain('@media (forced-colors: active)');
});

test('the on/off switch is a real switch that the thumb position explains', function () {
    $switch = component('components/ToggleSwitch.vue');

    expect($switch)
        ->toContain('role="switch"')
        ->toContain(':aria-checked="modelValue"')
        ->toContain('<button')
        ->toContain('translate-x-5')
        ->toContain('motion-safe:duration-150')
        ->toContain('motion-safe:ease-out')
        ->toContain('forced-colors:')
        ->not->toContain('transition-all');
});

test('settings use the switch and consent keeps the checkbox', function () {
    foreach (['Customers/Index', 'Catalogue/Index', 'Recurring/Index', 'Establishments/Index', 'PriceLists/Index'] as $page) {
        expect(component("pages/{$page}.vue"))->toContain('<ToggleSwitch');
    }

    expect(component('components/CookieConsent.vue'))->toContain('form-checkbox')->not->toContain('<ToggleSwitch');
});

test('the file field is a real file input behind one outline pill', function () {
    expect(component('components/FileInput.vue'))
        ->toContain('type="file"')
        ->toContain('Escolher ficheiro')
        ->toContain('rounded-full');

    foreach (formScreens() as $screen) {
        if ($screen['path'] === 'components/FileInput.vue') {
            continue;
        }

        expect(str_contains($screen['contents'], 'type="file"'))->toBeFalse($screen['path']);
    }
});

test('the date input holds a day, a day and a time, or a time', function () {
    $input = component('components/DateInput.vue');

    expect($input)
        ->toContain('mode?: DateInputMode')
        ->toContain("mode: 'date'")
        ->toContain('withTime')
        ->toContain('Hoje')
        ->toContain('Limpar')
        ->toContain('role="combobox"')
        ->toContain("event.key === 'Escape'")
        ->not->toContain('toISOString');

    expect(component('lib/dates.ts'))
        ->toContain("export type DateInputMode = 'date' | 'datetime' | 'time';")
        ->not->toMatch('/[\w)\]]\.toISOString\(/');
});

test('the date picker popup has 40px cells, a gold ring for today and an ink circle for the day', function () {
    $css = (string) file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('--date-cell-size: 2.5rem;')
        ->toContain('--date-cell-size: 2.75rem;')
        ->toMatch("/\.date-cell\[data-today='true'\] \{[^}]*var\(--color-accent-400\)/s")
        ->toMatch("/\.date-cell\[data-selected='true'\] \{[^}]*var\(--color-brand-950\)/s")
        ->not->toContain('vuepic');

    expect(component('components/DateInput.vue'))
        ->toContain('menu-panel')
        ->toContain('icon-button')
        ->toContain('Mês anterior')
        ->toContain('Mês seguinte');
});

test('form errors keep the 6px rhythm and announce once', function () {
    expect(component('components/FormError.vue'))
        ->toContain('mt-1.5')
        ->toContain('role="alert"')
        ->toContain('v-if="message"');
});
