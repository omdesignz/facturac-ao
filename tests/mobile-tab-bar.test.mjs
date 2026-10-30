import assert from 'node:assert/strict';
import { test } from 'node:test';
import { isFocusedPath } from '../resources/js/lib/mobile-tab-bar.ts';

test('the tab bar steps aside on the till and on a document being made', () => {
    assert.equal(isFocusedPath('/ponto-de-venda'), true);
    assert.equal(isFocusedPath('/ponto-de-venda/turnos'), true);
    assert.equal(isFocusedPath('/documents/invoices/create'), true);
    assert.equal(isFocusedPath('/orcamentos/novo'), true);
    assert.equal(isFocusedPath('/guias/nova'), true);
});

test('the tab bar stays on the screens you move between', () => {
    assert.equal(isFocusedPath('/dashboard'), false);
    assert.equal(isFocusedPath('/dividas'), false);
    assert.equal(isFocusedPath('/documentos'), false);
    assert.equal(isFocusedPath('/customers'), false);
    assert.equal(isFocusedPath('/ponto-de-vendas'), false);
});
