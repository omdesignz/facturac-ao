<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    CheckCircle2,
    CircleAlert,
    Database,
    Download,
    FileSpreadsheet,
    FileUp,
    History,
    LoaderCircle,
    LockKeyhole,
    Package,
    ShieldCheck,
    Sparkles,
    Trash2,
    Users,
} from '@lucide/vue';
import { computed, watch } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import PageStat from '@/components/PageStat.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { commit, destroy, index, store } from '@/routes/imports';
import { update as updateMapping } from '@/routes/imports/mapping';
import { security } from '@/routes/settings';
import type { SelectOption } from '@/types/select';

type BadgeTone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';
type StepState = 'complete' | 'current' | 'upcoming';

interface ImportTypeOption {
    value: string;
    label: string;
    template_url: string;
}

interface ImportSourceOption {
    value: string;
    label: string;
}

interface MappingField {
    key: string;
    label: string;
    required: boolean;
    example: string;
}

interface ImportRow {
    row_number: number;
    status: string;
    status_label: string;
    source_values: Record<string, string>;
    validation_errors: Record<string, string[]>;
}

interface ImportSummary {
    public_id: string;
    type: string;
    type_label: string;
    source: string;
    source_label: string;
    status: string;
    status_label: string;
    status_tone: BadgeTone;
    original_name: string;
    file_extension: string;
    file_size: number;
    total_rows: number;
    valid_rows: number;
    invalid_rows: number;
    imported_rows: number;
    created_rows: number;
    updated_rows: number;
    uploaded_by: string | null;
    created_at: string | null;
}

interface SelectedImport extends ImportSummary {
    headers: string[];
    column_mapping: Record<string, string>;
    sha256: string;
    failure_code: string | null;
    failure_message: string | null;
    mapped_at: string | null;
    validated_at: string | null;
    committed_at: string | null;
    rows: ImportRow[];
    visible_row_limit: number;
    mapping_fields: MappingField[];
    permissions: {
        map: boolean;
        commit: boolean;
        cancel: boolean;
    };
}

const props = defineProps<{
    company: {
        legal_name: string;
        tax_identification_number: string | null;
    };
    types: ImportTypeOption[];
    sources: ImportSourceOption[];
    imports: ImportSummary[];
    selected: SelectedImport | null;
    permissions: {
        create: boolean;
    };
    guardrails: {
        maximum_file_size_mb: number;
        maximum_rows: number;
        accepted_extensions: string[];
        source_files_private: boolean;
        source_deleted_after_commit: boolean;
        mfa_enabled: boolean;
        saft_available: boolean;
    };
}>();

const typeOptions = computed<SelectOption[]>(() =>
    props.types.map((type) => ({ value: type.value, label: type.label })),
);

const sourceOptions = computed<SelectOption[]>(() =>
    props.sources.map((source) => ({
        value: source.value,
        label: source.label,
    })),
);

function headerOptions(required: boolean): SelectOption[] {
    return [
        {
            value: '',
            label: required
                ? 'Seleccione uma coluna'
                : 'Não importar este campo',
        },
        ...(props.selected?.headers ?? []).map((header) => ({
            value: header,
            label: header,
        })),
    ];
}

const uploadForm = useForm<{
    type: string;
    source: string;
    file: File | null;
}>({
    type: props.types[0]?.value ?? 'customers',
    source: props.sources[0]?.value ?? 'excel',
    file: null,
});
const mappingForm = useForm<{ mapping: Record<string, string> }>({
    mapping: { ...(props.selected?.column_mapping ?? {}) },
});
const commitForm = useForm({});
const cancelForm = useForm({});

const selectedTemplate = computed(
    () =>
        props.types.find((type) => type.value === uploadForm.type) ??
        props.types[0],
);
const selectedFileName = computed(
    () => uploadForm.file?.name ?? 'Nenhum ficheiro seleccionado',
);
const canValidate = computed(
    () =>
        props.selected?.permissions.map === true &&
        ['awaiting_mapping', 'ready', 'has_errors'].includes(
            props.selected.status,
        ),
);
const invalidRowsVisible = computed(
    () => props.selected?.rows.filter((row) => row.status === 'invalid') ?? [],
);
const workflowSteps = computed(() => [
    {
        id: 'Passo 1',
        name: 'Ficheiro',
        state: stepState(1),
    },
    {
        id: 'Passo 2',
        name: 'Colunas',
        state: stepState(2),
    },
    {
        id: 'Passo 3',
        name: 'Validação',
        state: stepState(3),
    },
    {
        id: 'Passo 4',
        name: 'Confirmação',
        state: stepState(4),
    },
]);

watch(
    () => props.selected?.public_id,
    () => {
        mappingForm.defaults({
            mapping: { ...(props.selected?.column_mapping ?? {}) },
        });
        mappingForm.reset();
    },
);

function stepState(step: number): StepState {
    const status = props.selected?.status;

    if (status === 'completed') {
        return 'complete';
    }

    if (status === 'ready' || status === 'importing') {
        return step < 4 ? 'complete' : 'current';
    }

    if (status === 'has_errors') {
        return step < 3 ? 'complete' : step === 3 ? 'current' : 'upcoming';
    }

    if (status === 'awaiting_mapping') {
        return step === 1 ? 'complete' : step === 2 ? 'current' : 'upcoming';
    }

    return step === 1 ? 'current' : 'upcoming';
}

function chooseFile(event: Event): void {
    const target = event.target as HTMLInputElement;
    uploadForm.file = target.files?.[0] ?? null;
}

function submitUpload(): void {
    uploadForm.post(store.url(), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => uploadForm.reset('file'),
    });
}

function submitMapping(): void {
    if (props.selected === null) {
        return;
    }

    mappingForm.put(updateMapping.url(props.selected.public_id), {
        preserveScroll: true,
    });
}

function submitCommit(): void {
    if (props.selected === null) {
        return;
    }

    commitForm.post(commit.url(props.selected.public_id), {
        preserveScroll: true,
    });
}

async function submitCancellation(): Promise<void> {
    if (props.selected === null) {
        return;
    }

    const confirmed = await confirmAction({
        title: 'Cancelar esta importação?',
        message:
            'O ficheiro original é eliminado. O histórico de validação fica auditável.',
        confirmLabel: 'Cancelar importação',
        cancelLabel: 'Continuar a importar',
    });

    if (!confirmed) {
        return;
    }

    cancelForm.delete(destroy.url(props.selected.public_id), {
        preserveScroll: true,
    });
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toLocaleString('pt-AO', {
            maximumFractionDigits: 1,
        })} KB`;
    }

    return `${(bytes / 1024 / 1024).toLocaleString('pt-AO', {
        maximumFractionDigits: 1,
    })} MB`;
}

function formatDate(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Africa/Luanda',
    }).format(new Date(value));
}

function fieldErrors(row: ImportRow): string[] {
    return Object.values(row.validation_errors).flat();
}

function historyUrl(publicId: string): string {
    return index.url({ query: { import: publicId } });
}
</script>

<template>
    <AppLayout>
        <Head title="Importar dados" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-[100rem] space-y-8">
                <PageHeader
                    eyebrow="Configuração · Importar dados"
                    title="Importar dados"
                    description="Importe clientes, produtos e serviços a partir do Excel, CSV ou da exportação de outra aplicação. Mostramos tudo o que vai entrar antes de gravar seja o que for."
                >
                    <template #meta>
                        <StatusBadge
                            label="Nada é gravado sem confirmar"
                            tone="info"
                        />
                        <StatusBadge
                            label="Ficheiros privados"
                            tone="neutral"
                        />
                    </template>
                    <template #stats>
                        <PageStat
                            label="Limite"
                            :value="`${guardrails.maximum_rows.toLocaleString('pt-AO')} linhas`"
                            compact
                        />
                        <PageStat label="Integridade" value="SHA-256" compact />
                        <PageStat
                            label="Empresa"
                            :value="company.legal_name"
                            compact
                        />
                    </template>
                </PageHeader>

                <section
                    v-if="selected"
                    class="rounded-2xl surface p-5 sm:p-7"
                    aria-label="Progresso da importação"
                >
                    <nav aria-label="Progresso">
                        <ol
                            role="list"
                            class="space-y-4 md:flex md:space-y-0 md:space-x-8"
                        >
                            <li
                                v-for="step in workflowSteps"
                                :key="step.id"
                                class="md:flex-1"
                            >
                                <div
                                    v-if="step.state === 'complete'"
                                    class="group flex flex-col border-l-4 border-emerald-600 py-2 pl-4 md:border-t-4 md:border-l-0 md:pt-4 md:pb-0 md:pl-0 dark:border-emerald-500"
                                >
                                    <span
                                        class="flex items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-400"
                                    >
                                        <Check
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {{ step.id }}
                                    </span>
                                    <span
                                        class="text-sm font-medium text-zinc-950 dark:text-white"
                                        >{{ step.name }}</span
                                    >
                                </div>
                                <div
                                    v-else-if="step.state === 'current'"
                                    class="flex flex-col border-l-4 border-brand-600 py-2 pl-4 md:border-t-4 md:border-l-0 md:pt-4 md:pb-0 md:pl-0 dark:border-accent-400"
                                    aria-current="step"
                                >
                                    <span
                                        class="text-sm font-medium text-brand-700 dark:text-amber-300"
                                        >{{ step.id }}</span
                                    >
                                    <span
                                        class="text-sm font-medium text-zinc-950 dark:text-white"
                                        >{{ step.name }}</span
                                    >
                                </div>
                                <div
                                    v-else
                                    class="flex flex-col border-l-4 border-zinc-200 py-2 pl-4 md:border-t-4 md:border-l-0 md:pt-4 md:pb-0 md:pl-0 dark:border-white/10"
                                >
                                    <span
                                        class="text-sm font-medium text-zinc-400 dark:text-zinc-500"
                                        >{{ step.id }}</span
                                    >
                                    <span
                                        class="text-sm font-medium text-zinc-600 dark:text-zinc-400"
                                        >{{ step.name }}</span
                                    >
                                </div>
                            </li>
                        </ol>
                    </nav>
                </section>

                <div
                    v-if="selected"
                    class="grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(21rem,0.65fr)]"
                >
                    <div class="min-w-0 space-y-6">
                        <section
                            class="overflow-hidden rounded-2xl surface"
                            aria-labelledby="selected-import-heading"
                        >
                            <div
                                class="flex flex-col gap-4 border-b border-zinc-200 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7 dark:border-white/10"
                            >
                                <div class="min-w-0">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h2
                                            id="selected-import-heading"
                                            class="truncate text-base font-semibold text-zinc-950 dark:text-white"
                                        >
                                            {{ selected.original_name }}
                                        </h2>
                                        <StatusBadge
                                            :label="selected.status_label"
                                            :tone="selected.status_tone"
                                            :pulse="
                                                selected.status === 'importing'
                                            "
                                        />
                                    </div>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        {{ selected.type_label }} ·
                                        {{ selected.source_label }} ·
                                        {{ formatBytes(selected.file_size) }}
                                    </p>
                                </div>
                                <button
                                    v-if="selected.permissions.cancel"
                                    type="button"
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-rose-700 ring-1 ring-rose-600/20 transition ring-inset hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-rose-600 disabled:opacity-50 dark:text-rose-300 dark:ring-rose-400/20 dark:hover:bg-rose-400/10"
                                    :disabled="cancelForm.processing"
                                    @click="submitCancellation"
                                >
                                    <LoaderCircle
                                        v-if="cancelForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <Trash2
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Cancelar
                                </button>
                            </div>

                            <dl
                                class="grid divide-y divide-zinc-100 sm:grid-cols-4 sm:divide-x sm:divide-y-0 dark:divide-white/5"
                            >
                                <div class="px-5 py-4 sm:px-6">
                                    <dt
                                        class="text-xs font-semibold tracking-wider text-zinc-400 uppercase"
                                    >
                                        Linhas
                                    </dt>
                                    <dd
                                        class="mt-1 text-xl font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{
                                            selected.total_rows.toLocaleString(
                                                'pt-AO',
                                            )
                                        }}
                                    </dd>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <dt
                                        class="text-xs font-semibold tracking-wider text-zinc-400 uppercase"
                                    >
                                        Válidas
                                    </dt>
                                    <dd
                                        class="mt-1 text-xl font-semibold text-emerald-700 dark:text-emerald-400"
                                    >
                                        {{ selected.valid_rows }}
                                    </dd>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <dt
                                        class="text-xs font-semibold tracking-wider text-zinc-400 uppercase"
                                    >
                                        Com erros
                                    </dt>
                                    <dd
                                        class="mt-1 text-xl font-semibold"
                                        :class="
                                            selected.invalid_rows > 0
                                                ? 'text-rose-700 dark:text-rose-400'
                                                : 'text-zinc-950 dark:text-white'
                                        "
                                    >
                                        {{ selected.invalid_rows }}
                                    </dd>
                                </div>
                                <div class="px-5 py-4 sm:px-6">
                                    <dt
                                        class="text-xs font-semibold tracking-wider text-zinc-400 uppercase"
                                    >
                                        Importadas
                                    </dt>
                                    <dd
                                        class="mt-1 text-xl font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{ selected.imported_rows }}
                                    </dd>
                                </div>
                            </dl>

                            <div
                                class="border-t border-zinc-100 px-5 py-4 sm:px-7 dark:border-white/5"
                            >
                                <div
                                    class="grid gap-3 text-xs text-zinc-500 sm:grid-cols-2 dark:text-zinc-400"
                                >
                                    <p class="truncate">
                                        <span class="font-semibold"
                                            >SHA-256:</span
                                        >
                                        <span class="ml-1 font-mono">{{
                                            selected.sha256
                                        }}</span>
                                    </p>
                                    <p class="sm:text-right">
                                        Carregado por
                                        {{
                                            selected.uploaded_by ?? 'utilizador'
                                        }}
                                        · {{ formatDate(selected.created_at) }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="selected.status === 'failed'"
                            class="rounded-md bg-red-50 p-4 dark:bg-red-500/15 dark:outline dark:outline-red-500/25"
                            aria-labelledby="failed-import-heading"
                        >
                            <div class="flex">
                                <CircleAlert
                                    class="size-5 shrink-0 text-red-400"
                                    aria-hidden="true"
                                />
                                <div class="ml-3">
                                    <h3
                                        id="failed-import-heading"
                                        class="text-sm font-medium text-red-800 dark:text-red-200"
                                    >
                                        Não foi possível preparar este ficheiro
                                    </h3>
                                    <p
                                        class="mt-2 text-sm text-red-700 dark:text-red-200/80"
                                    >
                                        {{ selected.failure_message }}
                                    </p>
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="selected.status === 'has_errors'"
                            class="rounded-md bg-red-50 p-4 dark:bg-red-500/15 dark:outline dark:outline-red-500/25"
                            aria-labelledby="validation-errors-heading"
                        >
                            <div class="flex">
                                <CircleAlert
                                    class="size-5 shrink-0 text-red-400"
                                    aria-hidden="true"
                                />
                                <div class="ml-3">
                                    <h3
                                        id="validation-errors-heading"
                                        class="text-sm font-medium text-red-800 dark:text-red-200"
                                    >
                                        {{ selected.invalid_rows }} linhas
                                        precisam de correcção
                                    </h3>
                                    <div
                                        class="mt-2 text-sm text-red-700 dark:text-red-200/80"
                                    >
                                        <ul
                                            role="list"
                                            class="list-disc space-y-1 pl-5"
                                        >
                                            <li>
                                                Reveja primeiro a
                                                correspondência das colunas.
                                            </li>
                                            <li>
                                                Se os dados estiverem
                                                incorrectos, corrija o ficheiro
                                                e crie uma nova importação.
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section
                            v-if="
                                [
                                    'awaiting_mapping',
                                    'ready',
                                    'has_errors',
                                ].includes(selected.status)
                            "
                            class="rounded-2xl surface p-5 sm:p-7"
                            aria-labelledby="mapping-heading"
                        >
                            <div
                                class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                            >
                                <div>
                                    <p
                                        class="eyebrow text-brand-700 dark:text-amber-300"
                                    >
                                        Correspondência
                                    </p>
                                    <h2
                                        id="mapping-heading"
                                        class="mt-1 text-lg font-semibold text-zinc-950 dark:text-white"
                                    >
                                        Diga-nos o que significa cada coluna
                                    </h2>
                                    <p
                                        class="mt-1 max-w-2xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        As sugestões foram feitas pelos nomes
                                        dos cabeçalhos. Confirme antes de
                                        validar.
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="`${selected.headers.length} colunas detectadas`"
                                    tone="neutral"
                                />
                            </div>

                            <form
                                class="mt-6 space-y-4"
                                @submit.prevent="submitMapping"
                            >
                                <div
                                    v-for="field in selected.mapping_fields"
                                    :key="field.key"
                                    class="grid gap-2 rounded-xl border border-zinc-200 p-4 sm:grid-cols-[minmax(12rem,0.9fr)_minmax(14rem,1.1fr)] sm:items-center dark:border-white/10"
                                >
                                    <div>
                                        <label
                                            :for="`mapping-${field.key}`"
                                            class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >
                                            {{ field.label }}
                                            <span
                                                v-if="field.required"
                                                class="text-rose-600 dark:text-rose-400"
                                                >*</span
                                            >
                                        </label>
                                        <p
                                            class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400"
                                        >
                                            Exemplo: {{ field.example }}
                                        </p>
                                    </div>
                                    <div>
                                        <SelectInput
                                            :id="`mapping-${field.key}`"
                                            v-model="
                                                mappingForm.mapping[field.key]
                                            "
                                            :options="
                                                headerOptions(field.required)
                                            "
                                        />
                                        <p
                                            v-if="
                                                mappingForm.errors[
                                                    `mapping.${field.key}`
                                                ]
                                            "
                                            class="mt-1 text-xs text-rose-600 dark:text-rose-400"
                                        >
                                            {{
                                                mappingForm.errors[
                                                    `mapping.${field.key}`
                                                ]
                                            }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    v-if="mappingForm.errors.mapping"
                                    class="rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700 dark:bg-rose-400/10 dark:text-rose-300"
                                >
                                    {{ mappingForm.errors.mapping }}
                                </div>

                                <div
                                    class="flex flex-col-reverse gap-3 pt-2 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <p
                                        class="text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Validar não cria nem altera clientes ou
                                        artigos.
                                    </p>
                                    <button
                                        type="submit"
                                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                                        :disabled="
                                            !canValidate ||
                                            mappingForm.processing
                                        "
                                    >
                                        <LoaderCircle
                                            v-if="mappingForm.processing"
                                            class="size-4 animate-spin"
                                            aria-hidden="true"
                                        />
                                        <CheckCircle2
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Validar {{ selected.total_rows }} linhas
                                    </button>
                                </div>
                            </form>
                        </section>

                        <section
                            v-if="selected.rows.length > 0"
                            class="overflow-hidden rounded-2xl surface"
                            aria-labelledby="preview-heading"
                        >
                            <div
                                class="flex flex-col gap-2 border-b border-zinc-200 px-5 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7 dark:border-white/10"
                            >
                                <div>
                                    <h2
                                        id="preview-heading"
                                        class="text-base font-semibold text-zinc-950 dark:text-white"
                                    >
                                        {{
                                            invalidRowsVisible.length > 0
                                                ? 'Linhas a corrigir'
                                                : 'Pré-visualização dos dados'
                                        }}
                                    </h2>
                                    <p
                                        class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                    >
                                        Mostramos até
                                        {{ selected.visible_row_limit }} linhas;
                                        os erros aparecem primeiro.
                                    </p>
                                </div>
                                <StatusBadge
                                    :label="`${selected.rows.length} visíveis`"
                                    tone="neutral"
                                />
                            </div>

                            <div class="flow-root">
                                <div class="overflow-x-auto">
                                    <div
                                        class="inline-block min-w-full align-middle"
                                    >
                                        <table
                                            class="relative min-w-full divide-y divide-zinc-300 dark:divide-white/15"
                                        >
                                            <thead
                                                class="bg-zinc-50 dark:bg-zinc-800/75"
                                            >
                                                <tr>
                                                    <th
                                                        scope="col"
                                                        class="py-3.5 pr-3 pl-5 text-left text-sm font-semibold whitespace-nowrap text-zinc-900 sm:pl-7 dark:text-zinc-200"
                                                    >
                                                        Linha
                                                    </th>
                                                    <th
                                                        v-for="header in selected.headers"
                                                        :key="header"
                                                        scope="col"
                                                        class="px-3 py-3.5 text-left text-sm font-semibold whitespace-nowrap text-zinc-900 dark:text-zinc-200"
                                                    >
                                                        {{ header }}
                                                    </th>
                                                    <th
                                                        scope="col"
                                                        class="py-3.5 pr-5 pl-3 text-right text-sm font-semibold whitespace-nowrap text-zinc-900 sm:pr-7 dark:text-zinc-200"
                                                    >
                                                        Estado
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody
                                                class="divide-y divide-zinc-200 bg-white dark:divide-white/10 dark:bg-zinc-900"
                                            >
                                                <template
                                                    v-for="row in selected.rows"
                                                    :key="row.row_number"
                                                >
                                                    <tr>
                                                        <td
                                                            class="py-3 pr-3 pl-5 text-sm font-semibold whitespace-nowrap text-zinc-900 sm:pl-7 dark:text-white"
                                                        >
                                                            {{ row.row_number }}
                                                        </td>
                                                        <td
                                                            v-for="header in selected.headers"
                                                            :key="header"
                                                            class="max-w-72 truncate px-3 py-3 text-sm whitespace-nowrap text-zinc-600 dark:text-zinc-300"
                                                            :title="
                                                                row
                                                                    .source_values[
                                                                    header
                                                                ]
                                                            "
                                                        >
                                                            {{
                                                                row
                                                                    .source_values[
                                                                    header
                                                                ] || '—'
                                                            }}
                                                        </td>
                                                        <td
                                                            class="py-3 pr-5 pl-3 text-right whitespace-nowrap sm:pr-7"
                                                        >
                                                            <StatusBadge
                                                                :label="
                                                                    row.status_label
                                                                "
                                                                :tone="
                                                                    row.status ===
                                                                    'invalid'
                                                                        ? 'danger'
                                                                        : row.status ===
                                                                            'imported'
                                                                          ? 'success'
                                                                          : row.status ===
                                                                              'valid'
                                                                            ? 'info'
                                                                            : 'neutral'
                                                                "
                                                            />
                                                        </td>
                                                    </tr>
                                                    <tr
                                                        v-if="
                                                            row.status ===
                                                            'invalid'
                                                        "
                                                        class="bg-rose-50/70 dark:bg-rose-400/5"
                                                    >
                                                        <td
                                                            :colspan="
                                                                selected.headers
                                                                    .length + 2
                                                            "
                                                            class="px-5 py-3 text-sm text-rose-700 sm:px-7 dark:text-rose-300"
                                                        >
                                                            <ul
                                                                role="list"
                                                                class="flex flex-wrap gap-x-6 gap-y-1"
                                                            >
                                                                <li
                                                                    v-for="error in fieldErrors(
                                                                        row,
                                                                    )"
                                                                    :key="error"
                                                                    class="flex items-start gap-1.5"
                                                                >
                                                                    <CircleAlert
                                                                        class="mt-0.5 size-3.5 shrink-0"
                                                                        aria-hidden="true"
                                                                    />
                                                                    {{ error }}
                                                                </li>
                                                            </ul>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <aside class="space-y-6">
                        <section
                            v-if="selected.status === 'ready'"
                            class="overflow-hidden rounded-2xl bg-brand-950 text-white shadow-sm ring-1 ring-black/10"
                            aria-labelledby="commit-heading"
                        >
                            <div class="p-6">
                                <div
                                    class="flex size-11 items-center justify-center rounded-xl bg-accent-400 text-brand-950"
                                >
                                    <Database
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <h2
                                    id="commit-heading"
                                    class="mt-5 text-lg font-semibold"
                                >
                                    Pronto para gravar
                                </h2>
                                <p class="mt-2 text-sm/6 text-brand-100/70">
                                    {{ selected.valid_rows }} linhas estão
                                    válidas. Registos com o mesmo NIF ou código
                                    serão actualizados; os restantes serão
                                    criados.
                                </p>
                                <div
                                    class="mt-5 rounded-xl border border-white/10 bg-white/[0.06] p-4"
                                >
                                    <div class="flex gap-3">
                                        <LockKeyhole
                                            class="mt-0.5 size-4 shrink-0 text-accent-400"
                                            aria-hidden="true"
                                        />
                                        <p class="text-xs/5 text-brand-100/70">
                                            A confirmação exige palavra-passe e
                                            MFA. É uma operação auditada e
                                            idempotente.
                                        </p>
                                    </div>
                                </div>
                                <Link
                                    v-if="!guardrails.mfa_enabled"
                                    :href="security.url()"
                                    class="mt-4 inline-flex text-sm font-semibold text-accent-400 underline underline-offset-4 hover:no-underline"
                                >
                                    Activar MFA primeiro
                                </Link>
                                <button
                                    type="button"
                                    class="mt-5 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-accent-400 px-4 py-3 text-sm font-semibold text-brand-950 shadow-sm focus-ring-inverted transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                                    :disabled="
                                        !selected.permissions.commit ||
                                        !guardrails.mfa_enabled ||
                                        commitForm.processing
                                    "
                                    @click="submitCommit"
                                >
                                    <LoaderCircle
                                        v-if="commitForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <CheckCircle2
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Confirmar importação
                                </button>
                            </div>
                        </section>

                        <section
                            v-else-if="selected.status === 'completed'"
                            class="rounded-2xl bg-emerald-50 p-6 ring-1 ring-emerald-600/15 dark:bg-emerald-400/10 dark:ring-emerald-400/20"
                            aria-labelledby="completed-heading"
                        >
                            <CheckCircle2
                                class="size-9 text-emerald-600 dark:text-emerald-400"
                                aria-hidden="true"
                            />
                            <h2
                                id="completed-heading"
                                class="mt-4 text-lg font-semibold text-emerald-950 dark:text-emerald-100"
                            >
                                Importação concluída
                            </h2>
                            <p
                                class="mt-2 text-sm/6 text-emerald-800/80 dark:text-emerald-100/75"
                            >
                                O original foi eliminado do armazenamento
                                privado. O hash e a evidência de validação foram
                                preservados.
                            </p>
                            <dl
                                class="mt-5 grid grid-cols-2 gap-3 border-t border-emerald-600/15 pt-5 dark:border-emerald-300/15"
                            >
                                <div>
                                    <dt
                                        class="text-xs font-semibold text-emerald-700 uppercase dark:text-emerald-300"
                                    >
                                        Novos
                                    </dt>
                                    <dd
                                        class="mt-1 text-2xl font-semibold text-emerald-950 dark:text-white"
                                    >
                                        {{ selected.created_rows }}
                                    </dd>
                                </div>
                                <div>
                                    <dt
                                        class="text-xs font-semibold text-emerald-700 uppercase dark:text-emerald-300"
                                    >
                                        Actualizados
                                    </dt>
                                    <dd
                                        class="mt-1 text-2xl font-semibold text-emerald-950 dark:text-white"
                                    >
                                        {{ selected.updated_rows }}
                                    </dd>
                                </div>
                            </dl>
                        </section>

                        <section
                            class="rounded-2xl surface p-6"
                            aria-labelledby="privacy-heading"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="grid size-9 place-items-center rounded-lg bg-sky-50 text-sky-700 dark:bg-sky-400/10 dark:text-sky-300"
                                >
                                    <ShieldCheck
                                        class="size-4.5"
                                        aria-hidden="true"
                                    />
                                </div>
                                <h2
                                    id="privacy-heading"
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    Controlos de privacidade
                                </h2>
                            </div>
                            <ul
                                role="list"
                                class="mt-4 space-y-3 text-sm/6 text-zinc-600 dark:text-zinc-300"
                            >
                                <li class="flex gap-2.5">
                                    <Check
                                        class="mt-1 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    Ficheiro fora da pasta pública
                                </li>
                                <li class="flex gap-2.5">
                                    <Check
                                        class="mt-1 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    Conteúdo das linhas cifrado
                                </li>
                                <li class="flex gap-2.5">
                                    <Check
                                        class="mt-1 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    Isolamento por empresa e NIF
                                </li>
                                <li class="flex gap-2.5">
                                    <Check
                                        class="mt-1 size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    Original removido após confirmação
                                </li>
                            </ul>
                        </section>
                    </aside>
                </div>

                <div
                    class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(22rem,0.8fr)]"
                >
                    <section
                        class="rounded-2xl surface p-5 sm:p-7"
                        aria-labelledby="new-import-heading"
                    >
                        <div
                            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div>
                                <p
                                    class="eyebrow text-brand-700 dark:text-amber-300"
                                >
                                    Nova importação
                                </p>
                                <h2
                                    id="new-import-heading"
                                    class="mt-1 text-lg font-semibold text-zinc-950 dark:text-white"
                                >
                                    Escolha a origem e o ficheiro
                                </h2>
                                <p
                                    class="mt-1 text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Use o nosso modelo para reduzir correcções.
                                </p>
                            </div>
                            <a
                                v-if="selectedTemplate"
                                :href="selectedTemplate.template_url"
                                class="inline-flex shrink-0 items-center gap-2 text-sm font-semibold text-brand-700 hover:text-brand-600 dark:text-amber-300 dark:hover:text-amber-200"
                            >
                                <Download class="size-4" aria-hidden="true" />
                                Descarregar modelo
                            </a>
                        </div>

                        <form
                            class="mt-6 space-y-5"
                            @submit.prevent="submitUpload"
                        >
                            <div class="grid gap-4 sm:grid-cols-2">
                                <label class="block">
                                    <span
                                        class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >O que vai importar?</span
                                    >
                                    <SelectInput
                                        v-model="uploadForm.type"
                                        class="mt-2"
                                        aria-label="O que vai importar?"
                                        :options="typeOptions"
                                    />
                                </label>
                                <label class="block">
                                    <span
                                        class="text-sm font-semibold text-zinc-900 dark:text-white"
                                        >De onde vem?</span
                                    >
                                    <SelectInput
                                        v-model="uploadForm.source"
                                        class="mt-2"
                                        aria-label="De onde vem?"
                                        :options="sourceOptions"
                                    />
                                </label>
                            </div>

                            <label
                                class="relative block w-full cursor-pointer rounded-lg border-2 border-dashed border-zinc-300 p-8 text-center transition focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-brand-600 hover:border-zinc-400 dark:border-white/15 dark:focus-within:outline-amber-300 dark:hover:border-white/25"
                            >
                                <input
                                    type="file"
                                    class="sr-only"
                                    accept=".xlsx,.xls,.csv"
                                    @change="chooseFile"
                                />
                                <FileSpreadsheet
                                    class="mx-auto size-11 text-zinc-400 dark:text-zinc-500"
                                    aria-hidden="true"
                                />
                                <span
                                    class="mt-3 block text-sm font-semibold text-zinc-900 dark:text-white"
                                    >{{ selectedFileName }}</span
                                >
                                <span
                                    class="mt-1 block text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    XLSX, XLS ou CSV · até
                                    {{ guardrails.maximum_file_size_mb }} MB
                                </span>
                            </label>

                            <p
                                v-if="uploadForm.errors.file"
                                class="text-sm text-rose-600 dark:text-rose-400"
                            >
                                {{ uploadForm.errors.file }}
                            </p>

                            <div
                                class="flex flex-col-reverse gap-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <p
                                    class="flex items-start gap-2 text-xs/5 text-zinc-500 dark:text-zinc-400"
                                >
                                    <LockKeyhole
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    O ficheiro nunca recebe um endereço público.
                                </p>
                                <button
                                    type="submit"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                                    :disabled="
                                        !permissions.create ||
                                        uploadForm.file === null ||
                                        uploadForm.processing
                                    "
                                >
                                    <LoaderCircle
                                        v-if="uploadForm.processing"
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    <FileUp
                                        v-else
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Analisar ficheiro
                                </button>
                            </div>
                        </form>
                    </section>

                    <section
                        class="overflow-hidden rounded-2xl surface"
                        aria-labelledby="history-heading"
                    >
                        <div
                            class="flex items-center justify-between border-b border-zinc-200 px-5 py-5 sm:px-6 dark:border-white/10"
                        >
                            <div>
                                <p class="eyebrow text-zinc-400">Histórico</p>
                                <h2
                                    id="history-heading"
                                    class="mt-1 text-base font-semibold text-zinc-950 dark:text-white"
                                >
                                    Importações recentes
                                </h2>
                            </div>
                            <History
                                class="size-5 text-zinc-400"
                                aria-hidden="true"
                            />
                        </div>

                        <ul
                            v-if="imports.length > 0"
                            role="list"
                            class="divide-y divide-zinc-100 dark:divide-white/5"
                        >
                            <li
                                v-for="dataImport in imports"
                                :key="dataImport.public_id"
                            >
                                <Link
                                    :href="historyUrl(dataImport.public_id)"
                                    preserve-scroll
                                    class="group flex items-start gap-3 px-5 py-4 transition hover:bg-zinc-50 sm:px-6 dark:hover:bg-white/[0.03]"
                                >
                                    <div
                                        class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300"
                                    >
                                        <Users
                                            v-if="
                                                dataImport.type === 'customers'
                                            "
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        <Package
                                            v-else
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <div
                                            class="flex items-start justify-between gap-3"
                                        >
                                            <p
                                                class="truncate text-sm font-semibold text-zinc-900 dark:text-white"
                                            >
                                                {{ dataImport.original_name }}
                                            </p>
                                            <ArrowRight
                                                class="mt-0.5 size-4 shrink-0 text-zinc-300 transition group-hover:translate-x-0.5 group-hover:text-brand-600 dark:text-zinc-600 dark:group-hover:text-accent-400"
                                                aria-hidden="true"
                                            />
                                        </div>
                                        <div
                                            class="mt-1 flex flex-wrap items-center gap-2"
                                        >
                                            <StatusBadge
                                                :label="dataImport.status_label"
                                                :tone="dataImport.status_tone"
                                            />
                                            <span
                                                class="text-xs text-zinc-500 dark:text-zinc-400"
                                            >
                                                {{ dataImport.total_rows }}
                                                linhas
                                            </span>
                                        </div>
                                    </div>
                                </Link>
                            </li>
                        </ul>

                        <div v-else class="px-6 py-10 text-center">
                            <Sparkles
                                class="mx-auto size-8 text-zinc-300 dark:text-zinc-600"
                                aria-hidden="true"
                            />
                            <p
                                class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white"
                            >
                                Ainda não há importações
                            </p>
                            <p
                                class="mt-1 text-xs/5 text-zinc-500 dark:text-zinc-400"
                            >
                                A primeira aparecerá aqui com o seu estado e
                                evidência.
                            </p>
                        </div>
                    </section>
                </div>

                <section
                    class="rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/70 p-5 sm:p-6 dark:border-white/10 dark:bg-white/[0.02]"
                    aria-labelledby="saft-heading"
                >
                    <div
                        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="grid size-10 shrink-0 place-items-center rounded-xl surface text-zinc-500 dark:text-zinc-300"
                            >
                                <Database class="size-4.5" aria-hidden="true" />
                            </div>
                            <div>
                                <h2
                                    id="saft-heading"
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    SAF-T (AO) terá um fluxo dedicado
                                </h2>
                                <p
                                    class="mt-1 max-w-3xl text-sm/6 text-zinc-500 dark:text-zinc-400"
                                >
                                    Um SAF-T não é uma folha de cálculo
                                    qualquer. Estamos a preparar a validação do
                                    esquema, das contas e a entrega controlada à
                                    AGT.
                                </p>
                            </div>
                        </div>
                        <StatusBadge label="Em preparação" tone="neutral" />
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
