<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, CircleDashed } from '@lucide/vue';
import { computed } from 'vue';
import { onboarding } from '@/routes';
import { show as connectionShow } from '@/routes/agt/connection';
import { create as createInvoice } from '@/routes/invoices';
import { security } from '@/routes/settings';

export interface SetupProgress {
    ready_for_draft: boolean;
    environment: string;
    production_ready: boolean;
    next_step: string;
    items: { key: string; label: string; detail: string; done: boolean }[];
}

const props = defineProps<{
    progress: SetupProgress;
    onCompanyPage?: boolean;
    onConnectionPage?: boolean;
}>();
const nextItem = computed(() =>
    props.progress.items.find((item) => item.key === props.progress.next_step),
);
const nextLink = computed(() => {
    if (props.progress.next_step === 'permission') {
        return {
            href: '#setup-heading',
            label: 'Contacte o administrador da empresa',
        };
    }

    if (props.progress.ready_for_draft) {
        return {
            href: createInvoice.url(),
            label: 'Preparar primeira factura de teste',
        };
    }

    if (props.progress.next_step === 'company') {
        return {
            href: props.onCompanyPage ? '#company-profile' : onboarding.url(),
            label: 'Completar dados da empresa',
        };
    }

    if (props.progress.next_step === 'security') {
        return { href: security.url(), label: 'Proteger a minha conta' };
    }

    return {
        href: props.onConnectionPage
            ? `#${props.progress.next_step === 'association' ? 'taxpayer-association' : props.progress.next_step === 'verification' ? 'connection-check' : 'authorized-series'}`
            : connectionShow.url(),
        label:
            props.progress.next_step === 'association'
                ? 'Preparar o meu acesso'
                : props.progress.next_step === 'verification'
                  ? 'Verificar o meu acesso'
                  : 'Preparar a série FT',
    };
});
</script>

<template>
    <section
        class="rounded-2xl surface p-5 sm:p-7"
        aria-labelledby="setup-heading"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                    Da empresa à primeira factura
                </p>
                <h2 id="setup-heading" class="mt-1 text-xl font-semibold">
                    {{
                        progress.ready_for_draft
                            ? 'Pode preparar a sua factura de teste'
                            : 'Vamos preparar a sua empresa'
                    }}
                </h2>
                <p
                    class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                >
                    Ambiente de testes. Estes passos não activam a emissão em
                    produção. A homologação do software é responsabilidade do
                    produtor Facturac.
                </p>
            </div>
            <span
                class="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-400/10 dark:text-amber-300"
                >Produção por autorizar</span
            >
        </div>
        <ol
            class="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-5"
            aria-label="Progresso de configuração"
        >
            <li
                v-for="item in progress.items"
                :key="item.key"
                :aria-current="
                    item.key === progress.next_step ? 'step' : undefined
                "
                class="flex gap-3"
            >
                <component
                    :is="item.done ? Check : CircleDashed"
                    class="mt-0.5 size-5 shrink-0"
                    :class="item.done ? 'text-emerald-600' : 'text-zinc-400'"
                    aria-hidden="true"
                />
                <div>
                    <p class="text-sm font-semibold">
                        {{ item.label
                        }}<span class="sr-only">{{
                            item.done ? ': concluído' : ': por concluir'
                        }}</span>
                    </p>
                    <p class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400">
                        {{ item.detail }}
                    </p>
                </div>
            </li>
        </ol>
        <div
            class="mt-6 flex flex-wrap items-center gap-4 border-t border-zinc-100 pt-5 dark:border-white/10"
        >
            <a
                v-if="nextLink.href.startsWith('#')"
                :href="nextLink.href"
                class="inline-flex min-h-11 items-center rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 focus-ring hover:bg-accent-300"
                >{{ nextLink.label }}</a
            >
            <Link
                v-else
                :href="nextLink.href"
                class="inline-flex min-h-11 items-center rounded-full bg-accent-400 px-5 text-sm font-semibold text-brand-950 focus-ring hover:bg-accent-300"
                >{{ nextLink.label }}</Link
            >
            <p class="max-w-xl text-sm/6 text-zinc-500 dark:text-zinc-400">
                {{
                    nextItem
                        ? `Próximo passo: ${nextItem.label.toLowerCase()}.`
                        : 'Comece pelo cliente e pelos artigos. Guardar um rascunho não emite a factura.'
                }}
            </p>
        </div>
    </section>
</template>
