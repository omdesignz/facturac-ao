<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ClipboardList,
    LoaderCircle,
    Mail,
    Phone,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { update } from '@/routes/support/complaints';
import type { SelectOption } from '@/types/select';

interface Complaint {
    public_id: string;
    reference: string;
    subject: string;
    body: string;
    category_label: string;
    status: string;
    status_label: string;
    resolution: string | null;
    contact_name: string;
    contact_email: string;
    contact_phone: string | null;
    account_email: string | null;
    handled_by: string | null;
    created_at: string | null;
    response_due_at: string;
    resolved_at: string | null;
    overdue: boolean;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

const props = defineProps<{
    complaints: {
        data: Complaint[];
        links: PaginationLink[];
        total: number;
    };
    filters: { status: string };
    openCount: number;
    overdueCount: number;
    statuses: { value: string; label: string }[];
}>();

const dialogOpen = ref(false);
const editing = ref<Complaint | null>(null);

const form = useForm({ status: 'in_progress', resolution: '' });

const statusOptions = computed<SelectOption[]>(() =>
    props.statuses.map((status) => ({
        value: status.value,
        label: status.label,
    })),
);

const filterOptions = computed<SelectOption[]>(() => [
    { value: 'open', label: 'Por resolver' },
    { value: 'all', label: 'Todas' },
    ...props.statuses.map((status) => ({
        value: status.value,
        label: status.label,
    })),
]);

const statusFilter = ref(props.filters.status);

function changeFilter(value: string): void {
    statusFilter.value = value;
    router.get(
        '/support/reclamacoes',
        { status: value },
        { preserveState: true, replace: true, only: ['complaints', 'filters'] },
    );
}

function openComplaint(complaint: Complaint): void {
    editing.value = complaint;
    form.clearErrors();
    form.status = complaint.status;
    form.resolution = complaint.resolution ?? '';
    dialogOpen.value = true;
}

function submit(): void {
    if (editing.value === null) {
        return;
    }

    form.put(update.url({ complaint: editing.value.public_id }), {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    });
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

/** Turns Laravel's &laquo;/&raquo; pagination labels into plain characters. */
function decodeEntities(label: string): string {
    return label
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»')
        .replace(/&nbsp;/g, ' ')
        .trim();
}

const statusTone: Record<string, 'success' | 'warning' | 'neutral'> = {
    open: 'warning',
    in_progress: 'warning',
    resolved: 'success',
    rejected: 'neutral',
};
</script>

<template>
    <AppLayout>
        <Head title="Reclamações" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-6">
                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                            Interno
                        </p>
                        <h1
                            class="mt-2.5 text-[2.125rem] leading-[1.08] display text-zinc-950 dark:text-white"
                        >
                            Livro de reclamações
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            {{ openCount }} por resolver.
                            <span
                                v-if="overdueCount > 0"
                                class="font-semibold text-rose-700 dark:text-rose-300"
                            >
                                {{ overdueCount }} fora do prazo.
                            </span>
                        </p>
                    </div>

                    <div class="w-full sm:max-w-[14rem]">
                        <SelectInput
                            :model-value="statusFilter"
                            :options="filterOptions"
                            @update:model-value="
                                (value) => changeFilter(String(value))
                            "
                        />
                    </div>
                </header>

                <div
                    v-if="overdueCount > 0"
                    class="flex items-start gap-3 rounded-2xl bg-rose-50 p-4 text-sm/6 text-rose-900 ring-1 ring-rose-200 dark:bg-rose-400/10 dark:text-rose-200 dark:ring-rose-400/20"
                >
                    <TriangleAlert
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Há reclamações por responder além do prazo que assumimos
                        publicamente. Um cliente nesta situação pode recorrer ao
                        INADEC.
                    </p>
                </div>

                <div
                    v-if="complaints.data.length === 0"
                    class="flex flex-col items-center gap-2 rounded-2xl surface px-4 py-16 text-center"
                >
                    <ClipboardList
                        class="size-8 text-zinc-300 dark:text-zinc-600"
                        aria-hidden="true"
                    />
                    <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                        Nenhuma reclamação neste filtro.
                    </p>
                </div>

                <ul v-else class="space-y-4">
                    <li
                        v-for="complaint in complaints.data"
                        :key="complaint.public_id"
                        class="rounded-2xl surface p-5"
                    >
                        <div class="flex flex-wrap items-center gap-3">
                            <span
                                class="numeric text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                {{ complaint.reference }}
                            </span>
                            <StatusBadge
                                :tone="statusTone[complaint.status]"
                                :label="complaint.status_label"
                            />
                            <span
                                v-if="complaint.overdue"
                                class="rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                            >
                                Fora do prazo
                            </span>
                            <span
                                class="ms-auto text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                Entrou {{ formatDate(complaint.created_at) }} ·
                                resposta até
                                {{ formatDate(complaint.response_due_at) }}
                            </span>
                        </div>

                        <p
                            class="mt-3 text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            {{ complaint.subject }}
                        </p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            {{ complaint.category_label }}
                        </p>

                        <p
                            class="mt-3 text-sm/6 whitespace-pre-line text-zinc-700 dark:text-zinc-300"
                        >
                            {{ complaint.body }}
                        </p>

                        <div
                            class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            <span class="font-medium">{{
                                complaint.contact_name
                            }}</span>
                            <a
                                :href="`mailto:${complaint.contact_email}`"
                                class="inline-flex items-center gap-1.5 hover:text-zinc-950 dark:hover:text-white"
                            >
                                <Mail class="size-3.5" aria-hidden="true" />
                                {{ complaint.contact_email }}
                            </a>
                            <a
                                v-if="complaint.contact_phone"
                                :href="`tel:${complaint.contact_phone}`"
                                class="inline-flex items-center gap-1.5 hover:text-zinc-950 dark:hover:text-white"
                            >
                                <Phone class="size-3.5" aria-hidden="true" />
                                {{ complaint.contact_phone }}
                            </a>
                            <span v-if="complaint.handled_by">
                                Tratada por {{ complaint.handled_by }}
                            </span>
                        </div>

                        <p
                            v-if="complaint.resolution"
                            class="mt-4 rounded-xl bg-zinc-50 p-3 text-sm/6 whitespace-pre-line text-zinc-700 dark:bg-white/5 dark:text-zinc-300"
                        >
                            {{ complaint.resolution }}
                        </p>

                        <div class="mt-4 flex justify-end">
                            <button
                                type="button"
                                class="rounded-xl px-3 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="openComplaint(complaint)"
                            >
                                Responder
                            </button>
                        </div>
                    </li>
                </ul>

                <nav
                    v-if="complaints.links.length > 3"
                    class="flex flex-wrap gap-1"
                    aria-label="Paginação"
                >
                    <Link
                        v-for="link in complaints.links"
                        :key="link.label"
                        :href="link.url ?? '#'"
                        :class="[
                            link.active
                                ? 'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950'
                                : 'text-zinc-600 ring-1 ring-zinc-200 dark:text-zinc-400 dark:ring-white/10',
                            link.url === null
                                ? 'pointer-events-none opacity-40'
                                : '',
                            'rounded-lg px-3 py-1.5 text-sm focus-ring',
                        ]"
                    >
                        <!-- Laravel emits &laquo;/&raquo; entities for the
                             previous and next links. -->
                        <span v-text="decodeEntities(link.label)" />
                    </Link>
                </nav>
            </div>
        </div>

        <RecordDialog
            :open="dialogOpen"
            :title="`Responder à reclamação ${editing?.reference ?? ''}`"
            description="A resposta fica visível para o cliente na página de Ajuda."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label
                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Estado
                    </label>
                    <div class="mt-2">
                        <SelectInput
                            v-model="form.status"
                            :options="statusOptions"
                        />
                    </div>
                    <FormError :message="form.errors.status" />
                </div>

                <div>
                    <label
                        for="complaint-resolution"
                        class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                    >
                        Resposta ao cliente
                    </label>
                    <textarea
                        id="complaint-resolution"
                        v-model="form.resolution"
                        rows="5"
                        class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                    />
                    <FormError :message="form.errors.resolution" />
                </div>

                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        class="rounded-xl px-4 py-2.5 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
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
                        Guardar
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
