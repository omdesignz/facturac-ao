import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { test, after } from 'node:test';
import vue from '@vitejs/plugin-vue';
import { createServer } from 'vite';
import { createSSRApp } from 'vue';
import { renderToString } from 'vue/server-renderer';
import {
    axisAmount,
    axisNumber,
    niceTicks,
} from '../resources/js/lib/chart-axis.ts';

const server = await createServer({
    configFile: false,
    plugins: [vue()],
    resolve: { alias: { '@': resolve('resources/js') } },
    server: { middlewareMode: true, ws: false, hmr: false },
    appType: 'custom',
});
after(() => server.close());

async function render(path, props) {
    const { default: component } = await server.ssrLoadModule(path);

    return renderToString(createSSRApp(component, props));
}

/** Server rendering hands spaces back as non-breaking ones; compare as a reader sees them. */
function text(html) {
    return html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ');
}

test('gridlines land on round values from zero', () => {
    assert.deepEqual(
        niceTicks(982_680, 4),
        [0, 250_000, 500_000, 750_000, 1_000_000],
    );
    assert.deepEqual(niceTicks(38, 4), [0, 10, 20, 30, 40]);
    assert.deepEqual(niceTicks(0, 4), [0]);
});

test('axis ticks read the way people here say amounts', () => {
    assert.equal(axisNumber(0), '0');
    assert.equal(axisNumber(750), '750');
    assert.equal(axisNumber(2_500).replace(/\s/g, ' '), '2,5 mil');
    assert.equal(axisNumber(1_200_000).replace(/\s/g, ' '), '1,2 M');
    assert.equal(axisAmount(75_000_000).replace(/\s/g, ' '), '750 mil');
});

test('the trend chart labels its value axis and can be read from the keyboard', async () => {
    const html = await render(
        '/resources/js/components/charts/ChartTrend.vue',
        {
            points: [
                { label: 'jan', value: 0, comparison: 10_000 },
                { label: 'fev', value: 98_268_000, comparison: 20_000 },
                { label: 'mar', value: 50_000_000, comparison: 0 },
            ],
            format: (value) => `${value}`,
            tickFormat: axisAmount,
            seriesLabel: 'Este ano',
            comparisonLabel: 'Ano anterior',
        },
    );
    const words = text(html);

    assert.match(words, /1 M/);
    assert.match(words, /500 mil/);
    assert.match(html, /tabindex="0"/);
    // The comparison is a quieter solid line, not a dashed "projection".
    assert.doesNotMatch(html, /stroke-dasharray/);
    assert.match(html, /var\(--viz-comparison\)/);
});

test('a lone bar is shown as its figure, without a full bar that compares to nothing', async () => {
    const html = await render('/resources/js/components/charts/ChartBars.vue', {
        bars: [{ label: 'Factura', value: 290_000, detail: '5×' }],
        format: (value) => `${value} Kz`,
        shares: true,
    });

    assert.match(html, /Factura/);
    assert.match(html, /290000 Kz/);
    assert.doesNotMatch(html, /--ui-chart-bar/);
    assert.doesNotMatch(html, /%/);
});

test('bars that add up to a whole print their share, and stay one ink', async () => {
    const html = await render('/resources/js/components/charts/ChartBars.vue', {
        bars: [
            { label: 'Factura', value: 1_030_000 },
            { label: 'Factura/Recibo', value: 2_830_000 },
        ],
        format: (value) => `${value}`,
        shares: true,
    });
    const words = text(html);

    assert.match(words, /27\s?%/);
    assert.match(words, /73\s?%/);
    assert.doesNotMatch(html, /--viz-series-/);
});

test('a top list never claims a share of a whole it leaves out', async () => {
    const html = await render('/resources/js/components/charts/ChartBars.vue', {
        bars: [
            { label: 'Ana', value: 3 },
            { label: 'Rui', value: 1 },
        ],
        format: (value) => `${value}`,
    });

    assert.doesNotMatch(text(html), /\d\s?%/);
});

test('the legend only appears when there is more than one line to tell apart', async () => {
    const one = await render(
        '/resources/js/components/charts/ChartLegend.vue',
        {
            items: [{ label: 'Este ano', color: 'var(--viz-primary)' }],
        },
    );
    const two = await render(
        '/resources/js/components/charts/ChartLegend.vue',
        {
            items: [
                { label: 'Este ano', color: 'var(--viz-primary)' },
                {
                    label: 'Ano anterior',
                    color: 'var(--viz-comparison)',
                    muted: true,
                },
            ],
        },
    );

    assert.doesNotMatch(one, /Este ano/);
    assert.match(two, /Este ano/);
    assert.match(two, /Ano anterior/);
});

test('a stat tile signs its change so it reads without colour', async () => {
    const down = await render('/resources/js/components/charts/StatTile.vue', {
        label: 'Facturado',
        value: '38 532,00 Kz',
        current: 8,
        previous: 100,
        comparisonLabel: 'Mês anterior',
    });
    const up = await render('/resources/js/components/charts/StatTile.vue', {
        label: 'Facturado',
        value: '1 Kz',
        current: 112,
        previous: 100,
    });

    assert.match(text(down), /−92%/);
    assert.match(text(down), /vs mês anterior/);
    assert.match(down, /--ui-critical-wash/);
    assert.match(text(up), /\+12%/);
    assert.match(up, /--ui-positive-wash/);
});
