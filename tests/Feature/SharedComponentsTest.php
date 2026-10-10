<?php

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
