<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Bell,
    BellOff,
    CircleAlert,
    CircleCheck,
    Info,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import type { Component } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import {
    clearRead,
    index as notificationsIndex,
    read as markRead,
    readAll,
} from '@/routes/notifications';
import type { NotificationEntry } from '@/types/notification';
import type { SelectOption } from '@/types/select';

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    notifications: {
        data: NotificationEntry[];
        links: PaginationLink[];
        total: number;
        from: number | null;
        to: number | null;
    };
    filters: { estado: string; tema: string };
    topics: { value: string; label: string }[];
    unread: number;
}>();

const topicFilter = ref(props.filters.tema);

const topicOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Todos os temas' },
    ...props.topics,
]);

watch(topicFilter, (value) => {
    router.get(
        notificationsIndex.url(),
        {
            tema: value || undefined,
            estado:
                props.filters.estado === 'nao-lidas' ? 'nao-lidas' : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

function showState(estado: 'todas' | 'nao-lidas'): void {
    router.get(
        notificationsIndex.url(),
        {
            estado: estado === 'nao-lidas' ? 'nao-lidas' : undefined,
            tema: topicFilter.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function open(entry: NotificationEntry): void {
    router.post(markRead.url({ notification: entry.id }));
}

function markAll(): void {
    router.post(readAll.url(), {}, { preserveScroll: true });
}

async function clear(): Promise<void> {
    const confirmed = await confirmAction({
        title: 'Remover as notificações já lidas?',
        message: 'As que ainda não leu ficam onde estão.',
        confirmLabel: 'Limpar lidas',
    });

    if (!confirmed) {
        return;
    }

    router.delete(clearRead.url(), { preserveScroll: true });
}

const toneChip: Record<string, string> = {
    critical:
        'bg-rose-100 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300',
    warning:
        'bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
    success:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
    neutral: 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
};

const toneIcon: Record<string, Component> = {
    critical: CircleAlert,
    warning: TriangleAlert,
    success: CircleCheck,
    neutral: Info,
};

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

/** Laravel emits &laquo;/&raquo; entities for the previous and next links. */
function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}
</script>

<template>
    <AppLayout>
        <Head title="Notificações" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-4xl space-y-6">
                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            O que aconteceu
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            Notificações
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Só desta empresa, mais o que diz respeito à conta.
                            Escolha o que quer receber em
                            <Link
                                :href="'/settings/security'"
                                class="font-medium text-brand-700 underline-offset-2 hover:underline dark:text-brand-300"
                                >Conta e segurança</Link
                            >.
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="unread > 0"
                            type="button"
                            class="rounded-xl px-3 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                            @click="markAll"
                        >
                            Marcar todas como lidas
                        </button>
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:text-zinc-400 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                            @click="clear"
                        >
                            <Trash2 class="size-4" aria-hidden="true" />
                            Limpar lidas
                        </button>
                    </div>
                </header>

                <div class="flex flex-wrap items-center gap-3">
                    <div
                        class="inline-flex rounded-xl bg-zinc-100 p-1 dark:bg-white/5"
                        role="group"
                        aria-label="Filtrar por estado"
                    >
                        <button
                            v-for="option in [
                                { value: 'todas', label: 'Todas' },
                                {
                                    value: 'nao-lidas',
                                    label: `Por ler${unread > 0 ? ` (${unread})` : ''}`,
                                },
                            ]"
                            :key="option.value"
                            type="button"
                            class="rounded-lg px-3 py-1.5 text-sm font-medium focus-ring transition"
                            :class="
                                filters.estado === option.value
                                    ? 'bg-white text-zinc-950 shadow-sm dark:bg-white/10 dark:text-white'
                                    : 'text-zinc-600 hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white'
                            "
                            :aria-pressed="filters.estado === option.value"
                            @click="
                                showState(option.value as 'todas' | 'nao-lidas')
                            "
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <div class="w-full sm:w-64">
                        <SelectInput
                            v-model="topicFilter"
                            :options="topicOptions"
                            placeholder="Todos os temas"
                        />
                    </div>
                </div>

                <div
                    v-if="notifications.data.length === 0"
                    class="flex flex-col items-center gap-3 rounded-2xl surface px-6 py-16 text-center"
                >
                    <BellOff class="size-8 text-zinc-400" aria-hidden="true" />
                    <p
                        class="text-base font-semibold text-zinc-950 dark:text-white"
                    >
                        {{
                            filters.estado === 'nao-lidas'
                                ? 'Nada por ler'
                                : 'Ainda não há notificações'
                        }}
                    </p>
                    <p
                        class="max-w-md text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Avisamos quando a AGT responder a um documento, quando
                        uma factura vencer, quando um orçamento for aceite e
                        quando uma avença gerar o documento do período.
                    </p>
                </div>

                <ul v-else class="overflow-hidden rounded-2xl surface">
                    <li
                        v-for="entry in notifications.data"
                        :key="entry.id"
                        class="border-b border-zinc-100 last:border-0 dark:border-white/10"
                    >
                        <button
                            type="button"
                            class="flex w-full gap-4 px-5 py-4 text-left focus-ring transition hover:bg-zinc-50 dark:hover:bg-white/5"
                            :class="
                                entry.read
                                    ? ''
                                    : 'bg-brand-50/40 dark:bg-white/5'
                            "
                            @click="open(entry)"
                        >
                            <span
                                :class="[
                                    toneChip[entry.tone] ?? toneChip.neutral,
                                    'mt-0.5 grid size-9 shrink-0 place-items-center rounded-full',
                                ]"
                            >
                                <component
                                    :is="toneIcon[entry.tone] ?? Bell"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline gap-2">
                                    <span
                                        class="min-w-0 flex-1 text-sm font-semibold text-zinc-950 dark:text-white"
                                        >{{ entry.title }}</span
                                    >
                                    <span
                                        v-if="!entry.read"
                                        class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-600 dark:bg-accent-400"
                                        aria-label="Por ler"
                                    />
                                </span>
                                <span
                                    class="mt-1 block text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >{{ entry.body }}</span
                                >
                                <span
                                    class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-zinc-400 dark:text-zinc-500"
                                >
                                    <span v-if="entry.topic_label">{{
                                        entry.topic_label
                                    }}</span>
                                    <span class="numeric">{{
                                        formatDate(entry.created_at)
                                    }}</span>
                                </span>
                            </span>
                        </button>
                    </li>
                </ul>

                <div
                    v-if="notifications.links.length > 3"
                    class="flex flex-wrap items-center justify-between gap-3"
                >
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ notifications.from }}–{{ notifications.to }} de
                        {{ notifications.total }}
                    </p>
                    <div class="flex flex-wrap gap-1">
                        <Link
                            v-for="link in notifications.links"
                            :key="link.label"
                            :href="link.url ?? '#'"
                            :class="[
                                'rounded-lg px-3 py-1.5 text-sm focus-ring transition',
                                link.active
                                    ? 'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950'
                                    : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-white/5',
                                link.url === null
                                    ? 'pointer-events-none opacity-40'
                                    : '',
                            ]"
                            preserve-scroll
                            >{{ decodeEntities(link.label) }}</Link
                        >
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
