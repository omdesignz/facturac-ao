import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    activeDestination,
    destinationsFor,
} from '../resources/js/lib/top-navigation.ts';

const item = (name, match) => ({ name, match });

const groups = [
    { items: [item('Painel', '/dashboard')] },
    {
        items: [
            item('Ponto de venda', '/ponto-de-venda'),
            item('Documentos fiscais', '/documentos'),
            item('Orçamentos', '/orcamentos'),
        ],
    },
    {
        items: [
            item('Clientes', '/customers'),
            item('Quem me deve', '/dividas'),
        ],
    },
    { items: [item('Monitor AGT', '/agt/submissions')] },
    { items: [item('Conta e segurança', '/settings/security')] },
];

test('pages sort under five words, in the order the bar shows them', () => {
    const names = destinationsFor(groups).map((d) => d.name);

    assert.deepEqual(names, [
        'Hoje',
        'Vender',
        'Cobrar',
        'Registos',
        'Fiscal',
        'Mais',
    ]);
});

test('a destination lists its pages in its own order, not the map’s', () => {
    const sell = destinationsFor(groups).find((d) => d.key === 'sell');

    assert.deepEqual(
        sell.items.map((i) => i.name),
        ['Ponto de venda', 'Orçamentos'],
    );
});

test('a page that belongs to no word waits under Mais', () => {
    const more = destinationsFor(groups).find((d) => d.key === 'more');

    assert.deepEqual(
        more.items.map((i) => i.name),
        ['Conta e segurança'],
    );
});

test('a word with no page the person can reach is left out', () => {
    const keys = destinationsFor([
        { items: [item('Painel', '/dashboard')] },
    ]).map((d) => d.key);

    assert.deepEqual(keys, ['today']);
});

test('the active word is the one that owns the page on screen', () => {
    const destinations = destinationsFor(groups);
    const debts = groups[2].items[1];

    assert.equal(activeDestination(destinations, debts), 'collect');
    assert.equal(activeDestination(destinations, null), null);
});
