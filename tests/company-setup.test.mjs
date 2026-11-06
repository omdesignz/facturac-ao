import assert from 'node:assert/strict';
import { test, after } from 'node:test';
import { fileURLToPath } from 'node:url';
import vue from '@vitejs/plugin-vue';
import { renderToString } from '@vue/server-renderer';
import { createServer } from 'vite';
import { createSSRApp, h } from 'vue';

const server = await createServer({ configFile: false, plugins: [vue()], resolve: { alias: { '@': fileURLToPath(new URL('../resources/js', import.meta.url)) } }, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { default: Progress } = await server.ssrLoadModule('/resources/js/components/CompanySetupProgress.vue');

for (const [step, anchor] of [['association', 'taxpayer-association'], ['verification', 'connection-check'], ['series', 'authorized-series']]) {
    test(`the ${step} action opens its exact section from another page`, async () => {
        const html = await renderToString(createSSRApp({ render: () => h(Progress, { progress: { ready_for_draft: false, environment: 'homologation', production_ready: false, next_step: step, items: [] } }) }));
        assert.ok(html.includes(`href="/agt/connection#${anchor}"`));
        assert.ok(html.includes('Produção por autorizar'));
    });
}
