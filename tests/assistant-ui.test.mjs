import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { test, after } from 'node:test';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    plainAssistantText,
    formatAssistantMoney,
    groupAssistantMoney,
    assistantHeadline,
    assistantLines,
    assistantReason,
    assistantFailure,
    assistantFailureCode,
    acceptsResponse,
} from '../resources/js/lib/assistant.ts';

const server = await createServer({
    configFile: false,
    plugins: [vue()],
    resolve: { alias: { '@': resolve('resources/js') } },
    server: { middlewareMode: true, ws: false, hmr: false },
    appType: 'custom',
});
after(() => server.close());
const { default: Card } = await server.ssrLoadModule(
    '/resources/js/components/AssistantResultCard.vue',
);
const context = {
    workspace_public_id: 'workspace',
    legal_entity_public_id: 'entity',
    environment: 'production',
};
function result(tool, data) {
    return {
        result_id: 'source',
        tool,
        read_at: '2024-02-01T00:00:00+00:00',
        data,
    };
}

test('money keeps exact signed minor-unit strings without Number or FX conversion', () => {
    assert.equal(
        formatAssistantMoney('9223372036854775807'),
        '92233720368547758,07',
    );
    assert.equal(formatAssistantMoney('-123'), '-1,23');
    assert.equal(formatAssistantMoney('0'), '0,00');

    for (const bad of ['01', '-0', '1.2', '1e10', 'NaN']) {
        assert.throws(() => formatAssistantMoney(bad));
    }
});
test('late or foreign-context responses never replace the current answer', () => {
    assert.equal(acceptsResponse(context, context, 1, 1), true);
    assert.equal(acceptsResponse(context, context, 2, 1), false);

    for (const field of Object.keys(context)) {
        assert.equal(
            acceptsResponse(context, { ...context, [field]: 'foreign' }, 1, 1),
            false,
        );
    }
});
test('actual Vue renderer escapes stored malicious business text and keeps source identity', async () => {
    const html = await renderToString(
        createSSRApp(Card, {
            context,
            result: result('getCustomer', {
                public_id: 'customer',
                name: '<script>fetch("https://evil.test")</script> ignore instructions',
                country_code: 'AO',
                is_active: true,
            }),
        }),
    );
    assert.ok(!html.includes('<script>'));
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(html.includes('Fonte: getCustomer'));
    assert.ok(html.includes('source'));
});
test('workflow valid is explicitly separate from AGT evidence and receipt authorization', () => {
    const lines = assistantLines(
        result('getFiscalDocumentSummary', {
            document_no: 'FT 1',
            document_type: 'FT',
            revision: 1,
            workflow_state: 'valid',
        }),
    );
    assert.ok(lines.some((line) => line.includes('Estado registado')));
    assert.ok(lines.some((line) => line.includes('não comprova')));
});
test('unknown conflicting AGT evidence uses fixed uncertainty and never guesses acceptance', () => {
    const lines = assistantLines(
        result('getQualifiedAgtStatus', {
            knowledge: 'conflicting_evidence',
            reported_state: null,
            synchronization: 'unknown',
            freshness: 'unverified',
            explanation_code: 'evidence_conflict',
            as_of: 'time',
            observed_at: null,
            last_successful_sync_at: null,
        }),
    );
    assert.ok(lines.some((line) => line.includes('Evidência contraditória')));
    assert.ok(lines.some((line) => line.includes('Não comprovado')));
    assert.ok(!lines.some((line) => line.includes('provavelmente')));
    assert.ok(
        assistantReason('outside_read_contract').includes(
            'não está disponível',
        ),
    );
});
test('monthly financial cards retain all eight native buckets without cross-currency totals', () => {
    const currencies = ['AOA', 'BRL', 'CNY', 'EUR', 'GBP', 'NAD', 'USD', 'ZAR'];
    const metrics = [
        'invoiced_gross_minor',
        'invoiced_net_minor',
        'invoiced_tax_minor',
        'credit_gross_minor',
        'credit_net_minor',
        'credit_tax_minor',
        'after_credits_gross_minor',
        'after_credits_net_minor',
        'after_credits_tax_minor',
    ];
    const buckets = currencies.map((currency_code) => ({
        currency_code,
        billed_document_count: 1,
        credit_note_count: 0,
        ...Object.fromEntries(metrics.map((m) => [m, '9223372036854775807'])),
    }));
    const lines = assistantLines(
        result('getMonthlyRecordedBilling', {
            period: { month: '2024-02' },
            as_of: 'time',
            currencies: buckets,
        }),
    );

    for (const currency of currencies) {
        assert.equal(
            lines.filter((line) => line.endsWith(` ${currency}`)).length,
            9,
        );
    }

    assert.ok(lines[0].includes('Facturação registada'));
    assert.ok(!lines.some((line) => line.includes('Total geral')));
});
const NBSP = '\u00A0';
function bucket(code, overrides = {}) {
    return {
        currency_code: code,
        billed_document_count: 0,
        credit_note_count: 0,
        invoiced_gross_minor: '0',
        invoiced_net_minor: '0',
        invoiced_tax_minor: '0',
        credit_gross_minor: '0',
        credit_net_minor: '0',
        credit_tax_minor: '0',
        after_credits_gross_minor: '0',
        after_credits_net_minor: '0',
        after_credits_tax_minor: '0',
        ...overrides,
    };
}
function billing(currencies, month = '2026-10') {
    return result('getMonthlyRecordedBilling', {
        period: { month },
        as_of: 'time',
        currencies,
    });
}
const aoaActive = {
    billed_document_count: 2,
    invoiced_gross_minor: '23550000',
    after_credits_gross_minor: '23550000',
};

test('groupAssistantMoney groups the integer part with non-breaking spaces using string maths only', () => {
    assert.equal(groupAssistantMoney('23550000'), `235${NBSP}500,00`);
    assert.equal(
        groupAssistantMoney('-123456789'),
        `-1${NBSP}234${NBSP}567,89`,
    );
    assert.equal(groupAssistantMoney('0'), '0,00');
    assert.equal(groupAssistantMoney('99999'), '999,99');
    assert.equal(groupAssistantMoney('100000'), `1${NBSP}000,00`);
    assert.equal(
        groupAssistantMoney('9223372036854775807'),
        `92${NBSP}233${NBSP}720${NBSP}368${NBSP}547${NBSP}758,07`,
    );

    for (const bad of ['01', '-0', '1.2', '1e10', 'NaN', '']) {
        assert.throws(() => groupAssistantMoney(bad));
    }
});
test('headline states one currency in a plain sentence', () => {
    assert.deepEqual(
        assistantHeadline(billing([bucket('AOA', aoaActive), bucket('EUR')])),
        [`Em outubro de 2026 facturou 235${NBSP}500,00 Kz em 2 documentos.`],
    );
    assert.deepEqual(
        assistantHeadline(
            billing([
                bucket('USD', {
                    billed_document_count: 1,
                    invoiced_gross_minor: '100',
                }),
            ]),
        ),
        ['Em outubro de 2026 facturou 1,00 USD em 1 documento.'],
    );
});
test('headline adds the position after credit notes', () => {
    const one = assistantHeadline(
        billing([
            bucket('AOA', {
                ...aoaActive,
                credit_note_count: 1,
                after_credits_gross_minor: '20000000',
            }),
        ]),
    );
    assert.deepEqual(one, [
        `Em outubro de 2026 facturou 235${NBSP}500,00 Kz em 2 documentos. Com 1 nota de crédito, fica em 200${NBSP}000,00 Kz.`,
    ]);
    assert.ok(
        assistantHeadline(
            billing([bucket('AOA', { ...aoaActive, credit_note_count: 3 })]),
        )[0].includes('Com 3 notas de crédito, fica em'),
    );
});
test('headline reports credit notes alone when nothing was billed', () => {
    assert.deepEqual(
        assistantHeadline(
            billing([
                bucket('AOA', {
                    credit_note_count: 1,
                    credit_gross_minor: '3550000',
                }),
            ]),
        ),
        [
            `Em outubro de 2026 registou 1 nota de crédito de 35${NBSP}500,00 Kz.`,
        ],
    );
});
test('headline says so when there is no activity in any currency', () => {
    assert.deepEqual(
        assistantHeadline(billing([bucket('AOA'), bucket('EUR')])),
        ['Não há facturação registada em outubro de 2026.'],
    );
    assert.deepEqual(assistantHeadline(billing([])), [
        'Não há facturação registada em outubro de 2026.',
    ]);
});
test('headline keeps two active currencies on separate lines without a combined total', () => {
    const lines = assistantHeadline(
        billing([
            bucket('AOA', aoaActive),
            bucket('BRL'),
            bucket('EUR', {
                billed_document_count: 1,
                invoiced_gross_minor: '12345',
            }),
        ]),
    );
    assert.equal(lines.length, 2);
    assert.ok(lines[0].includes('Kz'));
    assert.ok(lines[1].includes('123,45 EUR'));
    assert.ok(!lines.some((line) => /total/i.test(line)));
});
test('headline is empty for every other tool', () => {
    assert.deepEqual(
        assistantHeadline(result('getCustomer', { name: 'A' })),
        [],
    );
    assert.deepEqual(assistantHeadline(result('searchCustomers', {})), []);
});
test('headline falls back to the raw month when it is not YYYY-MM', () => {
    for (const month of ['2026-13', '2026-00', 'outubro', '2026-1']) {
        assert.deepEqual(assistantHeadline(billing([], month)), [
            `Não há facturação registada em ${month}.`,
        ]);
    }

    assert.ok(
        assistantHeadline(
            billing([bucket('AOA', aoaActive)], '2026-03'),
        )[0].startsWith('Em março de 2026 facturou'),
    );
});
test('detail lines list active currencies only and name the idle ones', () => {
    const lines = assistantLines(
        billing([
            bucket('AOA', aoaActive),
            bucket('BRL'),
            bucket('CNY'),
            bucket('EUR'),
        ]),
    );
    assert.equal(lines[0], 'Facturação registada · 2026-10');
    assert.equal(
        lines[1],
        'Valores nas moedas originais; não representam receitas, cobranças ou dívida.',
    );
    assert.equal(lines[2], 'Consulta: time');
    assert.equal(lines.filter((line) => line.endsWith(' AOA')).length, 9);
    assert.equal(lines.filter((line) => line.endsWith(' BRL')).length, 0);
    assert.ok(lines.includes(`Facturado bruto: 235${NBSP}500,00 AOA`));
    assert.equal(lines.length, 3 + 10 + 1);
    assert.equal(lines.at(-1), 'Sem movimento noutras moedas: BRL, CNY, EUR.');
    assert.deepEqual(assistantLines(billing([])).length, 3);
});
test('billing card leads with the headline and keeps the breakdown behind a disclosure', async () => {
    const html = await renderToString(
        createSSRApp(Card, {
            context,
            result: billing([bucket('AOA', aoaActive), bucket('BRL')]),
        }),
    );
    assert.ok(
        html.includes(
            `Em outubro de 2026 facturou 235${NBSP}500,00 Kz em 2 documentos.`,
        ),
    );
    assert.match(
        html,
        /<details[^>]*>\s*<summary[^>]*>\s*Ver detalhe\s*<\/summary>/,
    );
    assert.ok(html.includes('Factos consultados'));
    assert.ok(html.includes('Fonte: getMonthlyRecordedBilling'));
    assert.ok(html.includes('Sem movimento noutras moedas: BRL.'));
    assert.ok(!html.includes('Facturado bruto: 0,00 BRL'));
    assert.ok(!html.includes('BRL · Documentos'));
    assert.ok(html.includes('AOA · Documentos: 2'));
});
test('cards for other tools render without a disclosure', async () => {
    const html = await renderToString(
        createSSRApp(Card, {
            context,
            result: result('getCustomer', {
                public_id: 'c',
                name: 'Ana',
                country_code: 'AO',
                is_active: true,
            }),
        }),
    );
    assert.ok(!html.includes('<details'));
    assert.ok(!html.includes('Ver detalhe'));
});
test('page cancels and clears state on context/navigation/unmount with no persistent transcripts', async () => {
    const source = await readFile(
        'resources/js/pages/Assistant/Index.vue',
        'utf8',
    );

    for (const forbidden of [
        'localStorage',
        'sessionStorage',
        'v-html',
        'EventSource',
    ]) {
        assert.ok(!source.includes(forbidden));
    }

    for (const required of [
        'http.cancel()',
        'watch(',
        "router.on('before', clear)",
        'onBeforeUnmount(',
        'http.processing',
        'acceptsResponse(',
    ]) {
        assert.ok(source.includes(required));
    }
});

test('stored directional and control characters are displayed as visible text, preserving ordinary names', () => {
    assert.equal(plainAssistantText('José'), 'José');
    assert.equal(plainAssistantText('اسم'), 'اسم');
    assert.equal(
        plainAssistantText('Client\u202E\u0000'),
        'Client\\u{202E}\\u{0}',
    );
});

const noticeServer = await createServer({
    configFile: false,
    plugins: [
        {
            name: 'phase7-notice-test-shell',
            enforce: 'pre',
            resolveId(source) {
                if (source === '@inertiajs/vue3') {
                    return '\0phase7-inertia';
                }

                if (source.endsWith('/layouts/AppLayout.vue')) {
                    return '\0phase7-layout';
                }
            },
            load(id) {
                if (id === '\0phase7-inertia') {
                    return `export const Head = () => null;
                        export const router = { on: () => () => {} };
                        export const useHttp = input => ({...input, processing: false,
                            cancel() {}, reset() {}, post() { throw new Error('No network in rendering tests'); }});`;
                }

                if (id === '\0phase7-layout') {
                    return `import {h} from 'vue'; export default {setup(_, {slots}) {return () => h('div', slots.default?.());}};`;
                }
            },
        },
        vue(),
    ],
    ssr: { noExternal: ['@inertiajs/vue3'] },
    resolve: { alias: { '@': resolve('resources/js') } },
    server: { middlewareMode: true, ws: false, hmr: false },
    appType: 'custom',
});
after(() => noticeServer.close());
const { default: AssistantPage } = await noticeServer.ssrLoadModule(
    '/resources/js/pages/Assistant/Index.vue',
);

test('assistant page renders provider unavailable and cannot imply consent by default', async () => {
    const html = await renderToString(createSSRApp(AssistantPage, { context }));
    assert.ok(
        html.includes('processamento externo de perguntas está indisponível'),
    );
    assert.ok(!html.includes('Compreendo como funciona'));
    assert.match(html, /<button[^>]*type="submit"[^>]*\sdisabled(?:=|\s|>)/);
});

test('assistant page presents the external disclosure and requires explicit acknowledgement', async () => {
    const provider = {
        policy: 'p7-question-v1',
        notice_url: '/privacidade',
        available: true,
        acknowledged: false,
    };
    const html = await renderToString(
        createSSRApp(AssistantPage, { context, provider }),
    );

    for (const text of [
        'Anthropic',
        'nomes de clientes',
        'Não enviamos registos',
        'Estados Unidos',
        'Compreendo como funciona',
        'p7-question-v1',
    ]) {
        assert.ok(html.includes(text), text);
    }

    assert.ok(!html.includes('checked'));
    assert.match(html, /<button[^>]*type="submit"[^>]*\sdisabled(?:=|\s|>)/);
});

test('acknowledged page exposes withdrawal while preserving a deliberate submit action', async () => {
    const provider = {
        policy: 'p7-question-v1',
        notice_url: '/privacidade',
        available: true,
        acknowledged: true,
    };
    const html = await renderToString(
        createSSRApp(AssistantPage, { context, provider }),
    );
    assert.ok(html.includes('Retirar a confirmação'));
    assert.ok(!html.includes('Compreendo como funciona'));
    const submit = html.match(/<button[^>]*type="submit"[^>]*>/)?.[0];
    assert.ok(submit && !/\sdisabled(?:=|\s|>)/.test(submit));
});

test('withdrawal remains visible when provider admission later becomes unavailable', async () => {
    const provider = {
        policy: 'p7-question-v1',
        notice_url: null,
        available: false,
        acknowledged: true,
    };
    const html = await renderToString(
        createSSRApp(AssistantPage, { context, provider }),
    );
    assert.ok(html.includes('Retirar a confirmação'));
    assert.match(html, /<button[^>]*type="submit"[^>]*\sdisabled(?:=|\s|>)/);
});

const failureCases = [
    ['unauthenticated', 401, 'Inicie sessão'],
    ['forbidden', 403, 'dois passos'],
    ['not_found', 404, 'não encontrámos'],
    ['interaction_conflict', 409, 'Aguarde'],
    ['session_expired', 419, 'Recarregue'],
    ['invalid_input', 422, 'Reformule'],
    ['rate_limited', 429, 'limite diário'],
    ['unavailable', 503, 'temporariamente indisponível'],
];
function failure(status, code) {
    return {
        response: {
            status,
            data: JSON.stringify({
                error: { code, message: 'Não foi possível concluir o pedido.' },
                request_id: 'r',
            }),
        },
    };
}

test('every server failure code maps to a Portuguese next step, never the generic server message', () => {
    for (const [code, status, expected] of failureCases) {
        const message = assistantFailure(failure(status, code));
        assert.equal(assistantFailureCode(failure(status, code)), code);
        assert.ok(
            message.toLowerCase().includes(expected.toLowerCase()),
            `${code}: ${message}`,
        );
        assert.ok(!message.includes('Não foi possível concluir o pedido.'));
    }
});
test('failures fall back to the HTTP status, then to unavailable, and to the fallback without a response', () => {
    const fallback = 'Texto de recurso.';
    assert.equal(
        assistantFailureCode({ response: { status: 429, data: 'not json' } }),
        'rate_limited',
    );
    assert.equal(
        assistantFailureCode({
            response: {
                status: 429,
                data: { error: { code: 'rate_limited' } },
            },
        }),
        'rate_limited',
    );
    assert.equal(
        assistantFailureCode({ response: { status: 502, data: '' } }),
        'unavailable',
    );
    assert.equal(
        assistantFailureCode(failure(500, 'something_new')),
        'unavailable',
    );
    assert.equal(assistantFailureCode(new Error('offline')), null);
    assert.equal(assistantFailureCode(null), null);
    assert.equal(assistantFailure(new Error('offline'), fallback), fallback);
    assert.ok(assistantFailure(undefined).includes('páginas habituais'));
});

const { pickerAnnouncement, shouldShowQuestionCount, QUESTION_MAX_LENGTH } =
    await import('../resources/js/lib/assistant.ts');

test('picker announcement is one short sentence and silent while the list is closed', () => {
    assert.equal(pickerAnnouncement(false, true, 3), '');
    assert.equal(pickerAnnouncement(true, true, 3), 'A procurar…');
    assert.equal(pickerAnnouncement(true, false, 0), 'Nenhum resultado');
    assert.equal(pickerAnnouncement(true, false, 1), '1 resultado');
    assert.equal(pickerAnnouncement(true, false, 4), '4 resultados');
});

test('question counter appears only past 1800 of 2000 characters', () => {
    assert.equal(QUESTION_MAX_LENGTH, 2000);
    assert.equal(shouldShowQuestionCount(0), false);
    assert.equal(shouldShowQuestionCount(1800), false);
    assert.equal(shouldShowQuestionCount(1801), true);
    assert.equal(shouldShowQuestionCount(2000), true);
});

test('acknowledged page grows its question field, keeps one quiet status region and starts with no cancel or skeleton', async () => {
    const provider = {
        policy: 'p7-question-v1',
        notice_url: null,
        available: true,
        acknowledged: true,
    };
    const html = await renderToString(
        createSSRApp(AssistantPage, { context, provider }),
    );

    assert.match(html, /<textarea[^>]*enterkeyhint="enter"/);
    assert.match(html, /<textarea[^>]*field-sizing-content/);
    assert.ok(/<p class="sr-only" role="status"><\/p>/.test(html));
    assert.ok(!html.includes('Cancelar'));
    assert.ok(!html.includes('delayed-show'));
    assert.ok(!html.includes('aria-live'));
});

test('page cancels a question without an error, keeps the generation guard and sends with Cmd/Ctrl+Enter only', async () => {
    const source = await readFile(
        'resources/js/pages/Assistant/Index.vue',
        'utf8',
    );
    const cancel = source.slice(
        source.indexOf('function cancelQuestion'),
        source.indexOf('watch(\n    () => [contextKey'),
    );

    assert.ok(cancel.includes('generation++'));
    assert.ok(cancel.includes('http.cancel()'));
    assert.ok(cancel.includes("error.value = ''"));
    assert.ok(!cancel.includes('assistantFailure'));
    assert.ok(source.includes('@keydown.meta.enter.prevent'));
    assert.ok(source.includes('@keydown.ctrl.enter.prevent'));
    assert.ok(!source.includes('@keydown.enter.prevent="submit'));
    assert.ok(source.includes('if (generation === submitted)'));
});
