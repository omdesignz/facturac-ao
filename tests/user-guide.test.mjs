import assert from 'node:assert/strict';
import { test } from 'node:test';
import { matchesGuideSearch, readGuideProgress } from '../resources/js/lib/user-guide.ts';

const chapters = [{
    id: 'documentos', title: 'Facturação e emissão', summary: 'Prepare a venda.',
    category: 'Vendas', tip: 'Confira o NIF.', question: 'E se falhar?', answer: 'Consulte o Monitor AGT.',
    steps: [{ title: 'Rever', body: 'Confirme o recibo e o total.' }],
    checklist: ['Conferi os dados.', 'Li o resultado.'], related: [], minutes: 3, url: '/documentos',
}];

test('search ignores accents and case and searches workflow instructions, not only titles', () => {
    assert.equal(matchesGuideSearch(chapters[0], 'FATURACAO'), true);
    assert.equal(matchesGuideSearch(chapters[0], 'FACTURACAO emissao'), true);
    assert.equal(matchesGuideSearch(chapters[0], 'recibo total'), true);
    assert.equal(matchesGuideSearch(chapters[0], '   '), true);
    assert.equal(matchesGuideSearch(chapters[0], 'inventario'), false);
});

test('reading state restores only recognised chapters and checklist items', () => {
    assert.deepEqual(readGuideProgress(JSON.stringify({
        completed: ['documentos', 'deleted', 'documentos', 1],
        saved: ['documentos'], checks: ['documentos:0', 'documentos:99', 'deleted:0'],
    }), chapters), { completed: ['documentos'], saved: ['documentos'], checks: ['documentos:0'] });
});

test('missing, malformed and wrong-shaped storage cannot break the guide', () => {
    for (const value of [null, '', '{bad json', 'null', '3', '"text"', '{"saved":3,"checks":{}}']) {
        assert.deepEqual(readGuideProgress(value, chapters), { completed: [], saved: [], checks: [] });
    }
});

test('search finds scenario examples and screenshot instructions', () => {
    const chapter = { ...chapters[0], scenarios: [{ code: 'GF', title: 'Factura genérica', when: 'Mensal', example: 'Instituição financeira', action: 'Conferir', attention: 'Não usar para venda comum' }], screenshots: [{ title: 'Balcão', caption: 'Demonstração', instruction: 'Escolha o fundamento legal' }] };
    assert.equal(matchesGuideSearch(chapter, 'instituicao financeira'), true);
    assert.equal(matchesGuideSearch(chapter, 'fundamento legal'), true);
});
