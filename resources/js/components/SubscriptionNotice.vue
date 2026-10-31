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
        class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-zinc-900/[0.07] bg-[#f5f5f7] px-4 py-2.5 text-sm text-zinc-800 sm:px-6 lg:px-8 dark:border-white/10 dark:bg-white/[0.05] dark:text-zinc-200"
    >
        <span
            class="size-2 shrink-0 rounded-full bg-accent-400"
            aria-hidden="true"
        />
        <p v-if="access?.status === 'trial'" class="min-w-0 flex-1">
            Experimente todas as funcionalidades até {{ trialDate }}. Depois,
            escolha o plano adequado ao seu negócio.
        </p>
        <p v-else-if="locked" class="min-w-0 flex-1">
            O plano {{ access?.plan_name ?? 'actual' }} não inclui novas
            operações neste módulo. Os registos existentes continuam
            disponíveis.
        </p>
        <p v-else class="min-w-0 flex-1">
            Assinatura sem período activo. Consulte os documentos e exporte o
            SAF-T; escolha um plano para retomar a emissão e as funcionalidades
            pagas.
        </p>
        <Link
            :href="billing.url()"
            class="inline-flex h-8 items-center rounded-full bg-brand-950 px-3.5 text-[0.8125rem] font-semibold text-white focus-ring transition hover:bg-brand-800 active:scale-[0.97] dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
            >Ver planos</Link
        >
    </aside>
</template>
