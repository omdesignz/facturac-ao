import assert from 'node:assert/strict';
import { test } from 'node:test';
import { decisionsFor } from '../resources/js/lib/today-decisions.ts';

const hrefs = { fiscal: '/agt/submissions', stock: '/inventario?low=1' };

const debtor = {
    customer_public_id: 'cus_1',
    name: 'Mavinga & Filhos',
    overdue_minor: 37_800_000,
    oldest_days_past_due: 28,
};

test('a quiet day has nothing to decide', () => {
    assert.deepEqual(
        decisionsFor(
            { focus: null, collections: { customers: [] }, lowStock: null },
            hrefs,
        ),
        [],
    );
});

test('a document the AGT sent back is the first card, with one verb', () => {
    const [card] = decisionsFor(
        {
            focus: {
                reason: 'invalid',
                document_no: 'FT LOJA2026/172',
                agt_message: 'Falta o código de isenção na linha 2.',
            },
            collections: null,
            lowStock: null,
        },
        hrefs,
    );

    assert.equal(card.key, 'fiscal');
    assert.equal(card.headline, 'FT LOJA2026/172 foi devolvida pela AGT.');
    assert.equal(card.detail, 'Falta o código de isenção na linha 2.');
    assert.equal(card.action, 'Corrigir');
    assert.equal(card.href, '/agt/submissions');
});

test('a document in contingency says so, and a merely overdue one is not a fiscal card', () => {
    const contingency = decisionsFor(
        {
            focus: {
                reason: 'contingency',
                document_no: 'FT 9',
                agt_message: null,
            },
            collections: null,
            lowStock: null,
        },
        hrefs,
    );
    const overdue = decisionsFor(
        {
            focus: {
                reason: 'overdue',
                document_no: 'FT 9',
                agt_message: null,
            },
            collections: null,
            lowStock: null,
        },
        hrefs,
    );

    assert.equal(contingency[0].headline, 'FT 9 está em contingência.');
    assert.deepEqual(overdue, []);
});

test('the customer who owes is a reminder for review, never a send', () => {
    const [card] = decisionsFor(
        { focus: null, collections: { customers: [debtor] }, lowStock: null },
        hrefs,
    );

    assert.equal(card.key, 'collection');
    assert.equal(card.kind, 'remind');
    assert.equal(card.action, 'Rever e enviar');
    assert.equal(card.amountMinor, 37_800_000);
    assert.match(card.detail, /há 28 dias/);
    assert.match(card.detail, /Nada é enviado até confirmar/);
    assert.deepEqual(card.customer, {
        public_id: 'cus_1',
        name: 'Mavinga & Filhos',
    });
});

test('a customer with nothing overdue, or no record to remind about, is skipped', () => {
    const none = decisionsFor(
        {
            focus: null,
            collections: {
                customers: [
                    { ...debtor, overdue_minor: 0 },
                    { ...debtor, customer_public_id: null },
                ],
            },
            lowStock: null,
        },
        hrefs,
    );

    assert.deepEqual(none, []);
});

test('stock that is running out is the last card', () => {
    const cards = decisionsFor(
        {
            focus: null,
            collections: { customers: [debtor] },
            lowStock: { name: 'Rolo térmico 80 mm', quantity: 3, unit: 'un' },
        },
        hrefs,
    );

    assert.deepEqual(
        cards.map((card) => card.key),
        ['collection', 'stock'],
    );
    assert.equal(cards[1].headline, 'Rolo térmico 80 mm: restam 3 un.');
    assert.equal(cards[1].href, '/inventario?low=1');
});
