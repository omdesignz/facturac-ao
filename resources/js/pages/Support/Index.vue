<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Ban,
    BellRing,
    BellOff,
    CircleDot,
    LifeBuoy,
    LoaderCircle,
    Pencil,
    Search,
    ShieldAlert,
    UserRoundSearch,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { store as startImpersonation } from '@/routes/support/impersonation';

interface SupportUser {
    id: number;
    name: string;
    email: string;
    workspace: string | null;
    email_verified: boolean;
    last_impersonated_at: string | null;
}

interface PastSession {
    public_id: string;
    impersonator: string;
    subject: string;
    subject_email: string;
    reason: string;
    ip_address: string | null;
    started_at: string;
    subject_notified: boolean;
    ended_at: string | null;
    ended_by: string | null;
    duration_seconds: number | null;
    writes: number;
    blocked: number;
    open: boolean;
}

const props = defineProps<{
    filters: { search: string };
    results: SupportUser[];
    sessions: PastSession[];
    maxMinutes: number;
    reasonMinLength: number;
}>();

const search = ref(props.filters.search);
const dialogOpen = ref(false);
const target = ref<SupportUser | null>(null);

let searchTimer: ReturnType<typeof setTimeout> | undefined;

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(
            '/support',
            { search: value || undefined },
            {
                preserveState: true,
                replace: true,
                only: ['results', 'filters'],
            },
        );
    }, 300);
});

const form = useForm({ reason: '' });

function openStart(user: SupportUser): void {
    target.value = user;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function submit(): void {
    if (target.value === null) {
        return;
    }

    form.post(startImpersonation.url({ user: target.value.id }), {
        preserveScroll: true,
    });
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'short',
    timeStyle: 'short',
});

function formatMoment(value: string): string {
    return dateFormatter.format(new Date(value));
}

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    const minutes = Math.floor(seconds / 60);

    return minutes > 0 ? `${minutes} min` : `${seconds} s`;
}

const endedByLabel: Record<string, string> = {
    support: 'Encerrada pelo técnico',
    expired: 'Terminou por tempo',
};
</script>

<template>
    <AppLayout>
        <Head title="Apoio" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <header>
                    <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                        Interno
                    </p>
                    <h1
                        class="mt-2.5 text-[2.125rem] leading-[1.08] display text-zinc-950 dark:text-white"
                    >
                        Apoio ao cliente
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Entre na conta de um cliente para reproduzir o problema
                        que ele descreveu. Cada sessão dura no máximo
                        {{ maxMinutes }} minutos, fica registada com o motivo
                        que escrever, e as operações irreversíveis continuam
                        bloqueadas. O cliente é avisado por email assim que
                        entrar.
                    </p>
                </header>

                <div
                    class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                >
                    <ShieldAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Está a ver dados reais de um cliente. Ele recebe um
                        email com o motivo que escrever, por isso escreva-o a
                        pensar em quem o vai ler. Emitir documentos, mexer em
                        pagamentos ou alterar credenciais fica bloqueado — para
                        isso, oriente o cliente.
                    </p>
                </div>

                <div class="overflow-hidden rounded-2xl surface">
                    <div
                        class="border-b border-zinc-100 p-4 dark:border-white/10"
                    >
                        <label class="relative block w-full sm:max-w-md">
                            <span class="sr-only">Procurar cliente</span>
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-zinc-400"
                                aria-hidden="true"
                            />
                            <input
                                v-model="search"
                                type="search"
                                placeholder="Email ou nome do cliente"
                                class="w-full rounded-xl border-0 bg-zinc-50 py-2.5 pr-3 pl-9 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset placeholder:text-zinc-400 dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </label>
                    </div>

                    <div
                        v-if="filters.search === ''"
                        class="flex flex-col items-center gap-2 px-4 py-12 text-center"
                    >
                        <UserRoundSearch
                            class="size-8 text-zinc-300 dark:text-zinc-600"
                            aria-hidden="true"
                        />
                        <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                            Procure pelo email que o cliente usou para o
                            contactar.
                        </p>
                    </div>

                    <div
                        v-else-if="results.length === 0"
                        class="px-4 py-12 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Nenhuma conta corresponde a “{{ filters.search }}”.
                    </div>

                    <ul
                        v-else
                        class="divide-y divide-zinc-100 dark:divide-white/10"
                    >
                        <li
                            v-for="user in results"
                            :key="user.id"
                            class="flex flex-wrap items-center gap-x-4 gap-y-2 p-4"
                        >
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ user.name }}
                                </p>
                                <p
                                    class="truncate text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ user.email }}
                                    <span v-if="user.workspace">
                                        · {{ user.workspace }}</span
                                    >
                                </p>
                            </div>

                            <span
                                v-if="user.last_impersonated_at"
                                class="text-xs text-zinc-400 dark:text-zinc-500"
                            >
                                Último diagnóstico
                                {{ formatMoment(user.last_impersonated_at) }}
                            </span>

                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                                @click="openStart(user)"
                            >
                                <LifeBuoy class="size-4" aria-hidden="true" />
                                Diagnosticar
                            </button>
                        </li>
                    </ul>
                </div>

                <section class="overflow-hidden rounded-2xl surface">
                    <header
                        class="border-b border-zinc-100 p-4 dark:border-white/10"
                    >
                        <h2
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Sessões recentes
                        </h2>
                        <p
                            class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                        >
                            O registo completo fica no histórico de actividade;
                            aqui ficam as últimas 25.
                        </p>
                    </header>

                    <div
                        v-if="sessions.length === 0"
                        class="px-4 py-12 text-center text-sm/6 text-zinc-500 dark:text-zinc-400"
                    >
                        Ainda não houve nenhuma sessão de diagnóstico.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead
                                class="border-b border-zinc-100 dark:border-white/10"
                            >
                                <tr>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Cliente
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Técnico
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Motivo
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Início
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Aviso
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Duração
                                    </th>
                                    <th class="px-4 py-3 eyebrow text-zinc-500">
                                        Alterações
                                    </th>
                                </tr>
                            </thead>
                            <tbody
                                class="divide-y divide-zinc-100 dark:divide-white/10"
                            >
                                <tr
                                    v-for="session in sessions"
                                    :key="session.public_id"
                                >
                                    <td class="px-4 py-3 align-top">
                                        <p
                                            class="font-medium text-zinc-950 dark:text-white"
                                        >
                                            {{ session.subject }}
                                        </p>
                                        <p
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ session.subject_email }}
                                        </p>
                                    </td>
                                    <td
                                        class="px-4 py-3 align-top text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ session.impersonator }}
                                        <p
                                            v-if="session.ip_address"
                                            class="text-xs text-zinc-400 dark:text-zinc-500"
                                        >
                                            {{ session.ip_address }}
                                        </p>
                                    </td>
                                    <td
                                        class="max-w-xs px-4 py-3 align-top text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ session.reason }}
                                    </td>
                                    <td
                                        class="px-4 py-3 align-top whitespace-nowrap text-zinc-600 tabular-nums dark:text-zinc-400"
                                    >
                                        {{ formatMoment(session.started_at) }}
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            v-if="session.subject_notified"
                                            class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                                            title="O cliente recebeu o email de aviso"
                                        >
                                            <BellRing
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Avisado
                                        </span>
                                        <span
                                            v-else
                                            class="inline-flex items-center gap-1.5 text-xs font-medium text-amber-700 dark:text-amber-300"
                                            title="O email de aviso ainda não saiu"
                                        >
                                            <BellOff
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            Por enviar
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            v-if="session.open"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300"
                                        >
                                            <CircleDot
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            A decorrer
                                        </span>
                                        <template v-else>
                                            <span
                                                class="whitespace-nowrap text-zinc-700 tabular-nums dark:text-zinc-300"
                                            >
                                                {{
                                                    formatDuration(
                                                        session.duration_seconds,
                                                    )
                                                }}
                                            </span>
                                            <p
                                                v-if="session.ended_by"
                                                class="text-xs text-zinc-400 dark:text-zinc-500"
                                            >
                                                {{
                                                    endedByLabel[
                                                        session.ended_by
                                                    ] ?? session.ended_by
                                                }}
                                            </p>
                                        </template>
                                    </td>
                                    <td class="px-4 py-3 align-top">
                                        <span
                                            class="inline-flex items-center gap-1.5 text-zinc-700 tabular-nums dark:text-zinc-300"
                                        >
                                            <Pencil
                                                class="size-3.5 text-zinc-400"
                                                aria-hidden="true"
                                            />
                                            {{ session.writes }}
                                        </span>
                                        <p
                                            v-if="session.blocked > 0"
                                            class="mt-1 inline-flex items-center gap-1.5 text-xs text-amber-700 dark:text-amber-300"
                                        >
                                            <Ban
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {{ session.blocked }} bloqueada(s)
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <RecordDialog
            :open="dialogOpen"
            :title="`Diagnosticar a conta de ${target?.name ?? ''}`"
            description="O motivo fica no registo permanente e é enviado ao cliente por email. Escreva o que precisa de reproduzir."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="space-y-2">
                    <label
                        for="impersonation-reason"
                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Motivo
                    </label>
                    <textarea
                        id="impersonation-reason"
                        v-model="form.reason"
                        rows="3"
                        :minlength="reasonMinLength"
                        placeholder="Ex.: cliente não consegue guardar o rascunho da factura FT 2026/14."
                        class="w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset placeholder:text-zinc-400 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    />
                    <FormError :message="form.errors.reason" />
                </div>

                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                        @click="dialogOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        Começar sessão
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
