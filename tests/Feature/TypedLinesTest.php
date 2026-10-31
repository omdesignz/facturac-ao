<?php

test('the composer offers a typed sale, matched against the catalogue, replacing the empty first line', function () {
    $composer = (string) file_get_contents(resource_path('js/pages/Invoices/Create.vue'));
    $parser = (string) file_get_contents(resource_path('js/lib/typed-lines.ts'));

    expect($composer)->toContain("import { parseTypedLines } from '@/lib/typed-lines';")
        ->toContain('Escreva o que vendeu')
        ->toContain('id="typed-sale"')
        ->toContain('typedSaleReady')
        ->toContain('applyCatalogueItem(line, typed.item.public_id)')
        ->toContain('form.lines.splice(0, 1)')
        ->and($parser)->toContain('Two items that answer equally well is a question');
});

test('a typed sale only ever adds lines that matched one article', function () {
    $composer = (string) file_get_contents(resource_path('js/pages/Invoices/Create.vue'));

    expect($composer)->toContain('typedLines.value.every((line) => line.item !== null)')
        ->toContain('if (!typedSaleReady.value) {');
});
