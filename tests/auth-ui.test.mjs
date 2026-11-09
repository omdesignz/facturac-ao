import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';

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

const screens = [
    ['Login', ['email', 'password', 'remember']],
    [
        'Register',
        [
            'name',
            'workspace_name',
            'email',
            'password',
            'password_confirmation',
        ],
    ],
    ['ForgotPassword', ['email']],
    ['ResetPassword', ['email', 'password', 'password_confirmation', 'token']],
    ['ConfirmPassword', ['password']],
    ['TwoFactorChallenge', ['code']],
    ['VerifyEmail', []],
];

for (const [name, fields] of screens) {
    test(`the ${name} screen renders its form and legal navigation in the shared layout`, async () => {
        const { default: Screen } = await server.ssrLoadModule(
            `/resources/js/pages/Auth/${name}.vue`,
        );
        const response = await createInertiaApp({
            page: {
                component: name,
                props: {
                    errors: {},
                    flash: {},
                    auth: {
                        user: { name: 'Demo', email: 'demo@example.test' },
                    },
                    legal: {
                        links: [
                            { slug: 'termos', label: 'Termos e Condições' },
                        ],
                    },
                    canResetPassword: true,
                    googleEnabled: false,
                    email: 'demo@example.test',
                    token: 'test-reset-token',
                },
                url: `/auth/${name}`,
                version: 'test',
                clearHistory: false,
                encryptHistory: false,
            },
            resolve: () => Screen,
            setup: ({ App, props, plugin }) =>
                createSSRApp({ render: () => h(App, props) }).use(plugin),
            render: renderToString,
        });

        assert.equal((response.body.match(/<h1\b/g) ?? []).length, 1);
        assert.match(response.body, /<form\b[^>]*method="post"/i);
        assert.match(response.body, /href="\/termos"/);
        assert.match(response.body, /Saltar para o conteúdo/);
        assert.match(response.body, /Empresa de demonstração/);
        for (const field of fields) {
            assert.ok(
                response.body.includes(`name="${field}"`),
                `Missing field: ${field}`,
            );
        }
        assert.doesNotMatch(response.body, /Software certificado/);
        if (name === 'ConfirmPassword') {
            assert.match(response.body, /href="\/dashboard"[^]*?Voltar à app/);
        }
    });
}

test('the public entry page renders without unresolved components', async () => {
    const { default: Landing } = await server.ssrLoadModule(
        '/resources/js/pages/Landing.vue',
    );
    const warnings = [];
    const response = await createInertiaApp({
        page: {
            component: 'Landing',
            props: {
                errors: {},
                auth: { user: null },
                plans: [],
                packages: [],
                trialDays: 14,
                provinceCount: 21,
                contact: { support_email: null, company: null },
                legal: { links: [] },
            },
            url: '/',
            version: 'test',
            clearHistory: false,
            encryptHistory: false,
        },
        resolve: () => Landing,
        setup: ({ App, props, plugin }) => {
            const app = createSSRApp({ render: () => h(App, props) }).use(
                plugin,
            );
            app.config.warnHandler = (message) => warnings.push(message);
            return app;
        },
        render: renderToString,
    });

    assert.match(response.body, /Abrir conta grátis/);
    assert.deepEqual(warnings, []);
});
