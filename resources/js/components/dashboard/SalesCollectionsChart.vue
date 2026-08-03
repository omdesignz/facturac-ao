<script setup lang="ts">
import { LineChart } from 'echarts/charts';
import { GridComponent, TooltipComponent } from 'echarts/components';
import { init, use } from 'echarts/core';
import type { ECharts } from 'echarts/core';
import { CanvasRenderer } from 'echarts/renderers';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

use([LineChart, GridComponent, TooltipComponent, CanvasRenderer]);

const chartElement = ref<HTMLDivElement | null>(null);
let chart: ECharts | null = null;
let resizeObserver: ResizeObserver | null = null;
let themeObserver: MutationObserver | null = null;

const months = ['Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul'];
const issued = [18.2, 21.4, 19.8, 27.6, 31.1, 36.8];
const collected = [14.8, 18.9, 17.3, 23.4, 28.8, 30.6];

function renderChart(): void {
    if (!chartElement.value) {
        return;
    }

    const isDark = document.documentElement.classList.contains('dark');
    const textColor = isDark ? '#a1a1aa' : '#71717a';
    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(24,24,27,0.08)';

    chart ??= init(chartElement.value, undefined, { renderer: 'canvas' });
    chart.setOption({
        animationDuration: window.matchMedia('(prefers-reduced-motion: reduce)')
            .matches
            ? 0
            : 650,
        color: ['#25a97e', '#f59e0b'],
        grid: { left: 8, right: 12, top: 18, bottom: 2, containLabel: true },
        tooltip: {
            trigger: 'axis',
            valueFormatter: (value: unknown) =>
                `${Number(value).toFixed(1)} M Kz`,
            backgroundColor: isDark ? '#18181b' : '#ffffff',
            borderColor: isDark ? '#3f3f46' : '#e4e4e7',
            textStyle: { color: isDark ? '#fafafa' : '#18181b' },
        },
        xAxis: {
            type: 'category',
            boundaryGap: false,
            data: months,
            axisLine: { lineStyle: { color: gridColor } },
            axisTick: { show: false },
            axisLabel: {
                color: textColor,
                fontFamily: 'Instrument Sans',
                fontSize: 11,
            },
        },
        yAxis: {
            type: 'value',
            axisLine: { show: false },
            axisTick: { show: false },
            splitLine: { lineStyle: { color: gridColor } },
            axisLabel: {
                color: textColor,
                fontFamily: 'Instrument Sans',
                fontSize: 11,
                formatter: '{value} M',
            },
        },
        series: [
            {
                name: 'Emitido',
                type: 'line',
                data: issued,
                smooth: 0.35,
                symbol: 'circle',
                symbolSize: 6,
                lineStyle: { width: 3 },
                areaStyle: {
                    color: isDark
                        ? 'rgba(37,169,126,0.16)'
                        : 'rgba(37,169,126,0.10)',
                },
            },
            {
                name: 'Cobrado',
                type: 'line',
                data: collected,
                smooth: 0.35,
                symbol: 'circle',
                symbolSize: 6,
                lineStyle: { width: 2 },
            },
        ],
    });
}

onMounted(async () => {
    await nextTick();
    renderChart();

    if (chartElement.value) {
        resizeObserver = new ResizeObserver(() => chart?.resize());
        resizeObserver.observe(chartElement.value);
    }

    themeObserver = new MutationObserver(renderChart);
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    themeObserver?.disconnect();
    chart?.dispose();
});
</script>

<template>
    <div>
        <div
            class="flex justify-end gap-4 text-[0.7rem] font-medium text-zinc-500 dark:text-zinc-400"
            aria-hidden="true"
        >
            <span class="inline-flex items-center gap-1.5"
                ><span
                    class="h-0.5 w-4 rounded-full bg-[#25a97e]"
                />Emitido</span
            >
            <span class="inline-flex items-center gap-1.5"
                ><span
                    class="h-0.5 w-4 rounded-full bg-amber-500"
                />Cobrado</span
            >
        </div>
        <div
            ref="chartElement"
            class="mt-2 h-64 w-full"
            role="img"
            aria-label="Gráfico de facturação emitida comparada com valores cobrados nos últimos seis meses"
        />
        <table class="sr-only">
            <caption>
                Facturação emitida e valores cobrados em milhões de kwanzas
            </caption>
            <thead>
                <tr>
                    <th scope="col">Mês</th>
                    <th scope="col">Emitido</th>
                    <th scope="col">Cobrado</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(month, index) in months" :key="month">
                    <th scope="row">{{ month }}</th>
                    <td>{{ issued[index] }} milhões Kz</td>
                    <td>{{ collected[index] }} milhões Kz</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
