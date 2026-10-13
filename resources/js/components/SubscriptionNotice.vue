<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as billing } from '@/routes/billing';

const page = usePage();
const access = computed(() => page.props.subscriptionAccess);
const feature = computed(() => {
    const modules: Record<string, string> = {
        'Stock/Index': 'inventory',
        'Recurring/Index': 'recurring',
        'Analytics/Export': 'reports',
        'TransportDocuments/Index': 'transport',
        'TransportDocuments/Form': 'transport',
        'Pos/Registers/Index': 'pos',
        'Pos/Sessions/Show': 'pos',
    };

    return modules[page.component];
});
const locked = computed(
    () => !!feature.value && access.value?.features[feature.value] === false,
);
const visible = computed(
    () => access.value && (access.value.status !== 'active' || locked.value),
);
const trialDate = computed(() =>
    access.value?.trial_ends_at
        ? new Intl.DateTimeFormat('pt-AO', {
              dateStyle: 'medium',
              timeZone: 'Africa/Luanda',
          }).format(new Date(access.value.trial_ends_at))
        : '',
);
</script>

<template>
    <aside
        v-if="visible"
        aria-label="Acesso da assinatura"
        class="flex flex-wrap items-center gap-x-6 gap-y-2 border-b border-blue-200 bg-blue-50 px-6 py-3 text-sm text-blue-950 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-100"
    >
        <p v-if="access?.status === 'trial'">
            Experimente todas as funcionalidades até {{ trialDate }}. Depois,
            escolha o plano adequado ao seu negócio.
        </p>
        <p v-else-if="locked">
            O plano {{ access?.plan_name ?? 'actual' }} não inclui novas
            operações neste módulo. Os registos existentes continuam
            disponíveis.
        </p>
        <p v-else>
            Assinatura sem período activo. Consulte os documentos e exporte o
            SAF-T; escolha um plano para retomar a emissão e as funcionalidades
            pagas.
        </p>
        <Link
            :href="billing.url()"
            class="inline-flex min-h-11 items-center rounded-lg px-3 font-semibold underline underline-offset-4 focus-ring active:bg-blue-200 dark:active:bg-blue-900"
            >Ver planos</Link
        >
    </aside>
</template>
