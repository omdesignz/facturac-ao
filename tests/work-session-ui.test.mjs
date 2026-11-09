import assert from 'node:assert/strict';
import { after, test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { createServer } from 'vite';
import vue from '@vitejs/plugin-vue';
import { createSSRApp, h } from 'vue';
import { renderToString } from '@vue/server-renderer';
import { createInertiaApp } from '@inertiajs/vue3';

const server = await createServer({ configFile: false, plugins: [vue()], resolve: { alias: { '@': fileURLToPath(new URL('../resources/js', import.meta.url)) } }, server: { middlewareMode: true, ws: false, hmr: false }, appType: 'custom' });
after(() => server.close());
const { default: Timer } = await server.ssrLoadModule('/resources/js/components/WorkSessionTimer.vue');

for (const remaining of [30, 600]) {
    test(`session timer renders safely with ${remaining} seconds remaining`, async () => {
        const response = await createInertiaApp({
            page: { component: 'Timer', props: { workSession: { enabled: true, remaining_seconds: remaining, total_seconds: 1500, warning_seconds: 60 }, errors: {} }, url: '/customer', version: 'test', clearHistory: false, encryptHistory: false },
            resolve: () => Timer,
            setup: ({ App, props, plugin }) => createSSRApp({ render: () => h(App, props) }).use(plugin),
            render: renderToString,
        });
        assert.ok(response.body.includes('data-page'));
        if (remaining === 30) assert.ok(response.body.includes('Sessão de trabalho termina'));
    });
}
