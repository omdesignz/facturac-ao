import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';
import { provideSSRWidth } from '@vueuse/core';

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

const shared = {
    errors: {},
    flash: {},
    auth: {
        user: {
            name: 'Demo',
            email: 'demo@example.test',
            is_support_staff: false,
        },
        workspaces: [
            {
                public_id: 'workspace_1',
                name: 'Empresa de teste',
                role: 'owner',
                role_label: 'Proprietário',
                current: true,
            },
        ],
    },
    currentWorkspace: {
        name: 'Empresa de teste',
        role: 'owner',
        role_label: 'Proprietário',
        legal_entity: null,
    },
    workspaces: [],
    legal: { links: [], terms_reacceptance_required: false },
    workSession: { enabled: false },
    notifications: { unread: 0, items: [] },
};

async function render(
    component,
    props = {},
    url = '/dashboard',
    width,
    inspectSetup,
) {
    const { default: Screen } = await server.ssrLoadModule(
        `/resources/js/${component}.vue`,
    );
    const warnings = [];
    const response = await createInertiaApp({
        page: {
            component: 'Test',
            props: { ...shared, ...props },
            url,
            version: 'test',
            clearHistory: false,
            encryptHistory: false,
        },
        resolve: () =>
            inspectSetup
                ? {
                      ...Screen,
                      setup(props, context) {
                          const state = Screen.setup(props, context);
                          inspectSetup(state);
                          return state;
                      },
                  }
                : Screen,
        setup: ({ App, props, plugin }) => {
            const app = createSSRApp({ render: () => h(App, props) }).use(
                plugin,
            );
            if (width !== undefined) {
                provideSSRWidth(width, app);
            }
            app.config.warnHandler = (warning) => warnings.push(warning);
            return app;
        },
        render: renderToString,
    });
    assert.deepEqual(warnings, []);
    return response.body;
}

test('large document counts remain exact while the decorative plot is bounded', async () => {
    const html = await render('components/dashboard/DocumentBalanceOverview', {
        summary: {
            from: '2026-11-01',
            to: '2026-11-07',
            cleared: 20000,
            current: 10000,
            overdue: 50,
            drafts: 7,
            issued: 30050,
            total: 30057,
        },
        period: 'novembro',
        href: '/documentos',
    });
    assert.equal((html.match(/size-\[5px\]/g) ?? []).length, 320);
    assert.match(html, /250 documentos/);
    assert.match(html, /<dt\b[^]*?Sem saldo/);
    assert.match(html, /20[\s\u00a0\u202f]?000/);
    assert.match(html, /href="\/documentos"/);
});

test('an empty document overview explains its zeros without inventing sales', async () => {
    const html = await render('components/dashboard/DocumentBalanceOverview', {
        summary: {
            from: '2026-11-01',
            to: '2026-11-07',
            cleared: 0,
            current: 0,
            overdue: 0,
            drafts: 0,
            issued: 0,
            total: 0,
        },
        period: 'novembro',
        href: '/documentos',
    });
    assert.match(html, /Ainda não há documentos de venda neste mês/);
    assert.doesNotMatch(html, /Cada ponto colorido corresponde/);
});

async function invoiceEditor(overrides = {}) {
    let editor;
    const html = await render(
        'pages/Invoices/Create',
        {
            recoveryScope: 'test-entity',
            company: { legal_name: 'Empresa de teste', currency_code: 'AOA' },
            establishments: [
                { public_id: 'head-office', name: 'Sede', code: 'SEDE' },
            ],
            customers: [
                {
                    public_id: 'customer-1',
                    name: 'Cliente guardado',
                    tax_identification_number: '5000000001',
                    country_code: 'AO',
                    address_line: 'Luanda',
                    payment_terms_days: 0,
                    agreed_prices: { 'article-1': '4123.45' },
                    withholding: null,
                },
            ],
            catalogueItems: [
                {
                    public_id: 'article-1',
                    code: 'CAFE',
                    name: 'Café',
                    description: null,
                    unit_of_measure: 'UN',
                    unit_price: '4500.00',
                    tax_type: 'IVA',
                    tax_code: 'NOR',
                    tax_percentage: '14',
                    tax_exemption_code: null,
                },
            ],
            transportLines: [],
            adjustableDocuments: [],
            paymentMethods: [],
            currencies: ['AOA'],
            withholdingTypes: [],
            eligibleSeries: [],
            operationTypes: [{ value: 'TB', label: 'Transmissão de bens' }],
            documentTypes: [
                {
                    value: 'FT',
                    label: 'Factura',
                    is_adjustment: false,
                    is_feminine: true,
                    is_receipt: false,
                    settles_other_documents: false,
                    requires_lines: true,
                    requires_line_operation_date: false,
                },
            ],
            taxTreatments: [
                {
                    value: 'IVA_NOR_14',
                    label: 'IVA 14%',
                    type: 'IVA',
                    code: 'NOR',
                    percentage: '14',
                    exemption_code: null,
                },
            ],
            document: {
                public_id: null,
                revision: 0,
                status: 'draft',
                document_no: null,
                document_type: 'FT',
                document_date: '2026-11-07',
                due_date: '2026-12-07',
                goods_available_at: '',
                goods_available_location: '',
                currency_code: 'AOA',
                exchange_rate: '1',
                withholdings: [],
                withholding_total: '0',
                establishment_public_id: 'head-office',
                customer_public_id: null,
                customer: {
                    name: '',
                    tax_identification_number: '',
                    country_code: 'AO',
                    address_line: '',
                },
                notes: '',
                references_document_public_id: null,
                references_document_no: null,
                adjustment_reason: '',
                payment_method: '',
                payment_date: '',
                settlements: [],
                lines: [
                    {
                        operation_type: 'TB',
                        operation_date: '',
                        product_code: '',
                        product_description: '',
                        quantity: '1',
                        unit_of_measure: 'UN',
                        unit_price: '0',
                        discount_percentage: '0',
                        tax_treatment: 'IVA_NOR_14',
                    },
                ],
                totals: { settlement: '0', net: '0', tax: '0', gross: '0' },
            },
            permissions: { issue: false },
            guardrails: {
                draft_only: true,
                schema_version: '1',
                number_assigned: false,
                mfa_enabled: false,
            },
            ...overrides,
        },
        '/documents/invoices/create',
        1440,
        (state) => {
            editor = state;
        },
    );
    return { ...editor, html };
}

test('empty optional invoice sections start closed', async () => {
    const { html } = await invoiceEditor();
    for (const title of ['withholding-title', 'notes-title']) {
        const details = html.match(
            new RegExp(`<details\\b[^>]*aria-labelledby="${title}"[^>]*>`),
        )?.[0];
        assert.ok(details);
        assert.doesNotMatch(details, /\sopen(?:[\s=>])/);
    }
});

test('saved optional invoice content stays expanded on arrival', async () => {
    const baseline = await invoiceEditor();
    const { html, form } = await invoiceEditor({
        document: {
            ...baseline.props.document,
            withholdings: [{ type: 'IS', rate_percentage: '6.5' }],
            notes: 'Entregar na recepção.',
        },
    });
    for (const title of ['withholding-title', 'notes-title']) {
        const details = html.match(
            new RegExp(`<details\\b[^>]*aria-labelledby="${title}"[^>]*>`),
        )?.[0];
        assert.match(details, /\sopen(?:[\s=>])/);
    }
    assert.equal(form.notes, 'Entregar na recepção.');
    assert.equal(form.withholdings[0].rate_percentage, '6.5');
});

test('saved customer details are compact without removing their fiscal snapshot', async () => {
    const baseline = await invoiceEditor();
    const editor = await invoiceEditor({
        document: {
            ...baseline.props.document,
            customer_public_id: 'customer-1',
            customer: {
                name: 'Nome guardado no rascunho',
                tax_identification_number: '5000000001',
                country_code: 'AO',
                address_line: 'Morada guardada',
            },
        },
    });
    assert.match(editor.html, /Ver dados do cliente/);
    assert.match(
        editor.html,
        /id="customer-snapshot-fields"[^>]*style="display:none;"/,
    );
    assert.equal(editor.form.customer.name, 'Nome guardado no rascunho');
    assert.equal(editor.form.customer.address_line, 'Morada guardada');
});

test('server errors reveal optional and saved-customer fields before focus moves', async () => {
    const editor = await invoiceEditor();
    editor.form.setError({
        'customer.country_code': 'País inválido.',
        'withholdings.0.rate_percentage': 'Taxa inválida.',
        'lines.0.product_description': 'A descrição é obrigatória.',
        notes: 'As observações são demasiado longas.',
    });
    editor.revealFormProblems();
    assert.equal(editor.customerDetailsOpen.value, true);
    assert.equal(editor.withholdingsOpen.value, true);
    assert.equal(editor.notesOpen.value, true);
    assert.equal(editor.lineDetailsOpen.value[editor.form.lines[0].key], true);
    assert.equal(Object.keys(editor.form.errors).length, 4);
});

test('manual customer and article fields remain visible without opening disclosures', async () => {
    const { html } = await invoiceEditor();
    for (const id of [
        'customer-snapshot-fields',
        'mobile-line-details-line-1',
    ]) {
        const fields = html.match(
            new RegExp(`<div\\b[^>]*id="${id}"[^>]*>`),
        )?.[0];
        assert.ok(fields);
        assert.doesNotMatch(fields, /display:none/);
    }
});

test('catalogue metadata is optional but operation dates remain visible when the document requires them', async () => {
    const baseline = await invoiceEditor();
    const document = {
        ...baseline.props.document,
        lines: [
            {
                ...baseline.props.document.lines[0],
                product_code: 'CAFE',
                product_description: 'Descrição guardada',
            },
        ],
    };
    const ordinary = await invoiceEditor({ document });
    assert.match(
        ordinary.html,
        /id="mobile-line-details-line-1"[^>]*style="display:none;"/,
    );
    assert.equal(
        ordinary.form.lines[0].product_description,
        'Descrição guardada',
    );

    const generic = await invoiceEditor({
        document: { ...document, document_type: 'GF' },
        documentTypes: [
            {
                ...baseline.props.documentTypes[0],
                value: 'GF',
                requires_line_operation_date: true,
            },
        ],
    });
    const fields = generic.html.match(
        /<div\b[^>]*id="mobile-line-details-line-1"[^>]*>/,
    )?.[0];
    assert.ok(fields);
    assert.doesNotMatch(fields, /display:none/);
    assert.match(generic.html, /Data da operação/);
});

test('invoice lines choose their layout from the editor width rather than the browser width', async () => {
    const { html } = await invoiceEditor();
    assert.match(html, /@container\/invoice-lines/);
    assert.match(html, /@min-\[36rem\]\/invoice-lines:block/);
    assert.match(html, /@min-\[36rem\]\/invoice-lines:hidden/);
    assert.match(html, /role="group" aria-label="Linha 1"/);
    assert.match(html, /aria-label="Tratamento fiscal da linha 1"/);
    assert.doesNotMatch(html, /min-w-\[58rem\]/);
});

test('choosing a saved customer removes obsolete snapshot errors while preserving unrelated errors', async () => {
    const editor = await invoiceEditor();
    editor.form.setError({
        'customer.name': 'O nome é obrigatório.',
        'customer.tax_identification_number': 'O NIF é obrigatório.',
        'lines.0.product_code': 'O código é obrigatório.',
        revision: 'Este rascunho foi alterado por outra pessoa.',
    });
    editor.form.customer_public_id = 'customer-1';
    editor.selectCustomer();
    assert.equal(editor.form.customer.name, 'Cliente guardado');
    assert.equal(editor.form.customer.tax_identification_number, '5000000001');
    assert.equal(editor.form.due_date, '2026-11-07');
    assert.deepEqual(Object.keys(editor.form.errors).sort(), [
        'lines.0.product_code',
        'revision',
    ]);
});

test('a catalogue selection clears only errors for fields it fills and keeps exact agreed prices', async () => {
    const editor = await invoiceEditor();
    editor.form.customer_public_id = 'customer-1';
    editor.selectCustomer();
    editor.form.setError({
        'lines.0.product_code': 'O código é obrigatório.',
        'lines.0.product_description': 'A descrição é obrigatória.',
        'lines.0.quantity': 'A quantidade tem de ser positiva.',
        revision: 'Reveja a versão guardada.',
    });
    editor.applyCatalogueItem(editor.form.lines[0], 'article-1');
    assert.equal(editor.form.lines[0].unit_price, '4123.45');
    assert.equal(editor.form.lines[0].product_description, 'Café');
    assert.deepEqual(Object.keys(editor.form.errors).sort(), [
        'lines.0.quantity',
        'revision',
    ]);
    assert.match(
        editor.catalogueOptions.value[1].hint,
        /4[\s\u00a0\u202f]?123,45\sKz \(acordado\)/,
    );
});

test('daily navigation keeps a rare active destination open under More', async () => {
    const html = await render('components/AppSidebar', {}, '/orcamentos');
    assert.match(
        html,
        /href="\/orcamentos"[^]*?aria-current="page"|aria-current="page"[^]*?href="\/orcamentos"/,
    );
    for (const href of [
        '/customers',
        '/catalogue',
        '/documentos',
        '/analises',
        '/agt/submissions',
        '/saft',
    ]) {
        assert.ok(html.includes(`href="${href}"`), href);
    }
    assert.doesNotMatch(html, /href="\/support"/);
});

test('a viewer cannot see an invoice creation shortcut', async () => {
    const html = await render('components/AppSidebar', {
        auth: {
            ...shared.auth,
            workspaces: [{ ...shared.auth.workspaces[0], role: 'viewer' }],
        },
        currentWorkspace: { ...shared.currentWorkspace, role: 'viewer' },
    });
    assert.doesNotMatch(html, /href="\/documents\/invoices\/create"/);
});

test('support staff retain access to the internal destinations under More', async () => {
    const html = await render(
        'components/AppSidebar',
        {
            auth: {
                ...shared.auth,
                user: { ...shared.auth.user, is_support_staff: true },
            },
        },
        '/support/reclamacoes',
    );
    assert.match(html, /href="\/support\/reclamacoes"/);
    assert.match(html, /href="\/support\/pacotes"/);
});

test('appearance choices and account navigation expose named keyboard controls', async () => {
    const appearance = await render('components/AppearancePicker');
    for (const label of ['Claro', 'Escuro', 'Sistema']) {
        assert.ok(appearance.includes(label));
    }
    assert.equal((appearance.match(/aria-pressed=/g) ?? []).length, 3);
    const nav = await render('components/AccountSettingsNav');
    assert.match(nav, /href="\/settings\/conta"/);
    assert.match(nav, /href="\/settings\/security"/);
    assert.match(nav, /href="\/settings\/billing"/);
});

const dashboard = {
    companyReadiness: {
        configured: true,
        status: 'configured',
        status_label: 'Completa',
    },
    agtReadiness: {
        configured: true,
        verified: true,
        status: 'ready',
        status_label: 'Ligada',
    },
    now: '2026-11-07T12:00:00+01:00',
    currencyCode: 'AOA',
    kpis: {
        billed_today_minor: 0,
        outstanding_minor: 0,
        overdue_minor: 0,
        issued_today: 0,
        overdue_count: 0,
    },
    focus: null,
    review: {
        attention_count: 0,
        in_flight_count: 0,
        overdue_count: 0,
        drafts_open: 0,
    },
    canRemindCustomers: false,
};

test('the first-use dashboard gives a viewer no write actions', async () => {
    const html = await render('pages/Dashboard', {
        ...dashboard,
        auth: {
            ...shared.auth,
            workspaces: [{ ...shared.auth.workspaces[0], role: 'viewer' }],
        },
        currentWorkspace: { ...shared.currentWorkspace, role: 'viewer' },
    });
    assert.match(html, /Ainda não emitiu nenhum documento/);
    assert.doesNotMatch(html, /href="\/documents\/invoices\/create"/);
    assert.doesNotMatch(html, /href="\/imports"/);
});

test('the document register preserves cents and currency beside independent collection and AGT states', async () => {
    const html = await render(
        'pages/Documents/Index',
        {
            documents: {
                total: 1,
                data: [
                    {
                        public_id: 'doc_1',
                        document_no: 'FT TEST/1',
                        document_type: 'FT',
                        document_type_label: 'Factura',
                        family: 'invoice',
                        document_date: '2026-11-07',
                        due_date: '2026-11-01',
                        customer_public_id: null,
                        customer_name: 'Cliente de teste',
                        gross_total_minor: 123456,
                        currency_code: 'USD',
                        status: 'valid',
                        workflow_status: 'valid',
                        workflow_label: 'Validada pela AGT',
                        balance: {
                            label: 'Em atraso',
                            tone: 'danger',
                            outstanding_minor: 123456,
                        },
                        can_print: true,
                    },
                ],
            },
            summary: { total: 1, draft: 0, active: 0, valid: 1, attention: 0 },
            filters: {
                q: '',
                status: 'all',
                family: 'all',
                type: '',
                establishment: '',
                from: '',
                to: '',
            },
            options: {
                types: [],
                statuses: [],
                families: [],
                establishments: [],
            },
            permissions: { create: false },
            canRemindCustomers: false,
            selected: null,
        },
        '/documentos',
    );
    assert.match(html, /1[\s\u00a0\u202f]?234,56/);
    assert.match(html, /USD/);
    assert.match(html, /Em atraso/);
    assert.match(html, /Validada pela AGT/);
    // Inertia observes the items' ancestors. A horizontal scroller around its
    // triggers makes the entire list visible and loads repeated pages on arrival.
    const register = html.match(
        /<section\b[^>]*aria-label="Documentos"[^>]*>/,
    )?.[0];
    assert.ok(register);
    assert.doesNotMatch(register, /overflow-[xy]?-?(?:auto|scroll)/);
    assert.match(html, /<div class="overflow-x-auto rounded-xl">/);
});

test('the POS catalogue keeps exact prices and full article names on keyboard-operable tiles', async () => {
    const html = await render('components/pos/PosCatalogue', {
        items: [
            {
                public_id: 'item_1',
                name: 'Serviço de instalação',
                code: 'S-001',
                barcode: null,
                type: 'service',
                unit_price_minor: 1250,
                tax_percentage: '14',
                price_includes_tax: false,
                unit_of_measure: 'un',
            },
        ],
        stock: {},
        quantities: {},
        currencyCode: 'AOA',
    });
    assert.match(html, /Serviço de instalação/);
    assert.match(html, /id="pos-search"/);
    assert.match(html, /<button\b/);
    assert.match(html, /Kz/);
    assert.match(html, /14,25/);
});

test('an unissued draft is not presented as money already owed in the inspector', async () => {
    const draft = {
        public_id: 'draft_1',
        document_no: null,
        document_type: 'FT',
        document_type_label: 'Factura',
        family: 'invoice',
        document_date: '2026-11-07',
        due_date: '2026-12-07',
        customer_public_id: null,
        customer_name: 'Cliente de teste',
        gross_total_minor: 123456,
        currency_code: 'AOA',
        status: 'draft',
        workflow_status: 'draft',
        workflow_label: 'Rascunho',
        balance: null,
        can_edit: true,
        can_print: false,
        can_send: false,
    };
    const html = await render(
        'pages/Documents/Index',
        {
            documents: { total: 1, data: [draft] },
            summary: { total: 1, draft: 1, active: 0, valid: 0, attention: 0 },
            filters: {
                q: '',
                status: 'all',
                family: 'all',
                type: '',
                establishment: '',
                from: '',
                to: '',
            },
            options: {
                types: [],
                statuses: [],
                families: [],
                establishments: [],
            },
            permissions: { create: false },
            canRemindCustomers: false,
            selected: {
                ...draft,
                snapshot: {
                    gross_total_minor: 123456,
                    outstanding_minor: 123456,
                    days_past_due: 0,
                    due_date: '2026-12-07',
                    steps: [],
                    agt_operational: { observed_at: null },
                },
                history: [],
                credit_note: null,
            },
        },
        '/documentos',
        1440,
    );
    assert.match(html, /Continuar rascunho/);
    assert.doesNotMatch(html, />Por receber[^<]*</);
});
