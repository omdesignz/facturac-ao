import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createInertiaApp } from '@inertiajs/vue3';
import vue from '@vitejs/plugin-vue';
import { renderToString } from '@vue/server-renderer';
import ts from 'typescript';
import { createServer } from 'vite';
import { createSSRApp, h } from 'vue';

const server = await createServer({
    configFile: false,
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('../resources/js', import.meta.url)),
        },
    },
    server: { middlewareMode: true, ws: false, hmr: false },
    appType: 'custom',
});
after(() => server.close());
const { default: Landing } = await server.ssrLoadModule(
    '/resources/js/pages/Landing.vue',
);

async function render({ packages = [], plans = [], email = null } = {}) {
    const response = await createInertiaApp({
        page: {
            component: 'Landing',
            props: {
                packages,
                plans,
                trialDays: 14,
                provinceCount: 21,
                contact: { support_email: email, company: null },
                legal: { cookie_consent_required: false, links: [] },
            },
            url: '/',
            version: 'test',
            clearHistory: false,
            encryptHistory: false,
        },
        resolve: () => Landing,
        setup: ({ App, props, plugin }) =>
            createSSRApp({ render: () => h(App, props) }).use(plugin),
        render: renderToString,
    });

    return response.body;
}

function section(html) {
    const start = html.indexOf('<section id="integracoes"');
    assert.ok(start >= 0);

    return html.slice(start, html.indexOf('</section>', start));
}

const offer = (name, features) => ({
    name,
    features,
    summary: null,
    trial_days: 14,
    is_featured: false,
    prices: [
        {
            interval: 'monthly',
            interval_label: 'Mensal',
            period_label: '/ mês',
            months: 1,
            amount: '25 000',
            currency: 'AOA',
            monthly_equivalent: '25 000',
            saving_percentage: null,
        },
    ],
});

test('visitors can find the SDK, run a synthetic demo and understand its limits without buying a plan', async () => {
    const html = await render();
    const sdk = section(html);
    assert.match(html, /href="#integracoes"/);
    assert.match(
        sdk,
        /https:\/\/github.com\/omdesignz\/facturac-sdk#try-an-integration-in-five-minutes/,
    );
    assert.match(
        sdk,
        /https:\/\/www.npmjs.com\/package\/@vapsolucoes.ao\/facturac/,
    );
    assert.match(sdk, /facturac-export --demo/);
    assert.match(sdk, /Sandbox local/);
    assert.match(sdk, /facturac.documents.iterate/);
    assert.match(sdk, /FACTURAC_TOKEN/);
    assert.match(sdk, /Copiar código/);
    assert.match(sdk, /Criar um cliente/);
    assert.match(sdk, /FT DEMO\/3/);
    // Every example is in the page, hidden rather than missing, under one tab list.
    assert.match(sdk, /role="tablist"/);
    assert.equal((sdk.match(/role="tabpanel"/g) ?? []).length, 4);
    assert.match(sdk, /cut -d, -f2,7,8 facturac-demo.csv/);
    assert.doesNotMatch(sdk, /uppercase|·/);
    assert.match(sdk, /Não simula autenticação/);
    assert.match(sdk, /dados fictícios/i);
    assert.match(sdk, /SDK 0.3.0 no npm/);
    assert.match(sdk, /Emitir factura/);
    assert.match(sdk, /facturac-issue-demo --demo/);
    assert.match(sdk, /documents:issue/);
    assert.match(sdk, /não emite documentos reais/);
    assert.match(sdk, /activação no servidor/);
    assert.match(sdk, /não confirma a aceitação pela AGT/);
    assert.doesNotMatch(sdk, /facturac@0\.2\.0|não inclui emissão fiscal/);
    assert.match(sdk, /requer um pacote com integrações externas/);
    assert.doesNotMatch(sdk, /mailto:|Acesso à API incluído:|25 000|Kz/);
});

test('API offer follows published package features and uses the configured contact', async () => {
    const sdk = section(
        await render({
            packages: [
                offer('Essencial', ['Facturação']),
                offer('Avançado', ['API e integrações externas']),
            ],
            email: 'integracoes@example.test',
        }),
    );
    assert.match(sdk, /Acesso à API incluído:/);
    assert.match(sdk, /<strong[^>]*>Avançado<\/strong>/);
    assert.doesNotMatch(sdk, /Essencial/);
    assert.match(sdk, /mailto:integracoes@example.test\?subject=API/);
    assert.match(sdk, /confirme a activação/);
});

test('legacy API plans are deduplicated, but do not override current packages', async () => {
    const plans = [1, 2].map(() => ({
        name: 'Integração legada',
        summary: null,
        amount: '10 000',
        currency: 'AOA',
        interval: 'Mensal',
        trial_days: 0,
        features: ['API e integrações externas'],
    }));
    const legacy = section(await render({ plans }));
    assert.equal((legacy.match(/Integração legada/g) ?? []).length, 1);
    const current = section(
        await render({ plans, packages: [offer('Essencial', ['Facturação'])] }),
    );
    assert.doesNotMatch(current, /Acesso à API incluído:|Integração legada/);
});

test('the displayed issuance example sends string totals and the persisted operation key', async () => {
    const { developerExamples, demoCommand } = await server.ssrLoadModule(
        '/resources/js/lib/developer-examples.ts',
    );
    const example = developerExamples.find((item) => item.id === 'issue');
    const calls = [];
    const env = {
        FACTURAC_TOKEN: 'synthetic-token',
        FACTURAC_WORKSPACE_ID: '01J00000000000000000000000',
        FACTURAC_ENTITY_ID: '01J00000000000000000000001',
        FACTURAC_CUSTOMER_ID: '01J00000000000000000000002',
        FACTURAC_ITEM_ID: '01J00000000000000000000003',
        FACTURAC_EXPECTED_TOTAL_MINOR: '22800',
        FACTURAC_OPERATION_KEY: 'persisted-order-1001',
    };
    class Client {
        constructor(options) {
            this.options = options;
        }
        documents = {
            issue: async (payload, options) => {
                calls.push({ connection: this.options, payload, options });

                return {
                    data: { public_id: 'synthetic-id' },
                    meta: { operation_id: 'synthetic-operation' },
                };
            },
        };
    }
    const source = example.code.replace(
        "import { Facturac } from '@vapsolucoes.ao/facturac';",
        '',
    );
    const { outputText } = ts.transpileModule(source, {
        compilerOptions: {
            target: ts.ScriptTarget.ES2022,
            module: ts.ModuleKind.ESNext,
        },
    });
    const AsyncFunction = Object.getPrototypeOf(
        async function () {},
    ).constructor;
    const execute = new AsyncFunction(
        'Facturac',
        'process',
        'console',
        outputText,
    );
    await execute(Client, { env }, { log() {} });
    await execute(Client, { env }, { log() {} });
    assert.deepEqual(calls[0], calls[1]);
    assert.equal(calls[0].connection.environment, 'production');
    assert.deepEqual(calls[0].payload, {
        document_type: 'FT',
        customer_public_id: env.FACTURAC_CUSTOMER_ID,
        lines: [
            { catalogue_item_public_id: env.FACTURAC_ITEM_ID, quantity: '2' },
        ],
        expected_total_minor: '22800',
    });
    assert.deepEqual(calls[0].options, {
        idempotencyKey: env.FACTURAC_OPERATION_KEY,
    });
    assert.deepEqual(demoCommand.split('\n'), [
        'npm exec --yes --package=@vapsolucoes.ao/facturac@0.3.0 -- facturac-issue-demo --demo',
        'npm exec --yes --package=@vapsolucoes.ao/facturac@0.3.0 -- facturac-export --demo ./facturac-demo.csv',
    ]);
});
