import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { test, after } from 'node:test';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import { connectionRecovery } from '../resources/js/lib/connection-recovery.ts';

const server = await createServer({ configFile: false, plugins: [vue()], resolve: { alias: { '@': resolve('resources/js') } }, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { default: Progress } = await server.ssrLoadModule('/resources/js/components/CompanySetupProgress.vue');
const progress = { ready_for_draft: false, production_ready: false, environment: 'homologation', next_step: 'company', items: [{ key: 'company', label: 'Dados da empresa', detail: 'Confirme o cadastro.', done: false }] };

test('setup provides the actual next action and never labels testing as production ready', async () => {
    const html = await renderToString(createSSRApp(Progress, { progress, onCompanyPage: true }));
    assert.match(html, /href="#company-profile"/);
    assert.match(html, /aria-current="step"/);
    assert.match(html, /Produção por autorizar/);
    assert.match(html, /Ambiente de testes/);
    assert.match(html, /não activam a emissão em produção/);
});

test('each remaining connection step points to its own section', async () => {
    for (const [step, target] of [['association', 'taxpayer-association'], ['verification', 'connection-check'], ['series', 'authorized-series']]) {
        const html = await renderToString(createSSRApp(Progress, { progress: { ...progress, next_step: step }, onConnectionPage: true }));
        assert.match(html, new RegExp(`href="#${target}"`));
        assert.doesNotMatch(html, /Preparar primeira factura de teste/);
    }
});

test('a ready setup links to the existing draft workflow with no issuing action', async () => {
    const html = await renderToString(createSSRApp(Progress, { progress: { ...progress, next_step: 'draft', ready_for_draft: true } }));
    assert.match(html, /Preparar primeira factura de teste/);
    assert.match(html, /Guardar um rascunho não emite/);
    assert.doesNotMatch(html, /method="post"/);
});

test('recovery differentiates local requirements authentication and temporary outages without echoing server text', () => {
    assert.match(connectionRecovery(undefined), /não emite facturas/);
    assert.match(connectionRecovery({ status: 'running', http_status: null, error_codes: [] }), /Aguarde e actualize/);
    assert.match(connectionRecovery({ status: 'failed', http_status: null, error_codes: ['LOCAL_TAXPAYER_KEY'] }), /requisitos assinalados/);

    for (const status of [401, 403]) {
        assert.match(connectionRecovery({ status: 'failed', http_status: status, error_codes: [] }), /Não altere a palavra-passe do Portal/);
    }

    for (const status of [null, 429, 500, 503]) {
        assert.match(connectionRecovery({ status: 'failed', http_status: status, error_codes: [] }), /Aguarde um minuto/);
    }

    const message = connectionRecovery({ status: 'failed', http_status: 400, error_codes: ['<script>secret</script>'] });
    assert.match(message, /Confira o NIF/);
    assert.doesNotMatch(message, /script|secret/);
});
