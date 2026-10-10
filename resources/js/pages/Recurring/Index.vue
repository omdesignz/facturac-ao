<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarSync,
    LoaderCircle,
    Pause,
    Play,
    Plus,
    Trash2,
    Zap,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DateInput from '@/components/DateInput.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import {
    destroy as destroyProfile,
    run as runProfiles,
    store as storeProfile,
    update as updateProfile,
} from '@/routes/recurring';
import type { SelectOption } from '@/types/select';

interface ProfileLine {
    product_code: string | null;
    product_description: string;
    unit_of_measure: string;
    quantity: string;
    unit_price: string;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
}

interface Profile {
    public_id: string;
    name: string;
    customer_name: string;
    customer_public_id: string;
    establishment_public_id: string;
    document_type: string;
    document_type_label: string;
    frequency: string;
    frequency_label: string;
    is_active: boolean;
    auto_issue: boolean;
    starts_on: string;
    ends_on: string | null;
    next_run_on: string;
    last_run_at: string | null;
    generated_count: number;
    estimated_total_minor: number;
    notes: string | null;
    lines: ProfileLine[];
}

const props = defineProps<{
    profiles: Profile[];
    customers: SelectOption[];
    establishments: SelectOption[];
    catalogueItems: {
        public_id: string;
        code: string;
        name: string;
        description: string | null;
        unit_of_measure: string;
        unit_price: string;
        tax_type: string;
        tax_code: string | null;
        tax_percentage: string;
        tax_exemption_code: string | null;
    }[];
    frequencies: SelectOption[];
    documentTypes: SelectOption[];
    currencyCode: string;
}>();

const dialogOpen = ref(false);
const editing = ref<Profile | null>(null);

function emptyLine(): ProfileLine {
    return {
        product_code: null,
        product_description: '',
        unit_of_measure: 'UN',
        quantity: '1',
        unit_price: '0.00',
        tax_type: 'IVA',
        tax_code: 'NOR',
        tax_percentage: '14.00',
        tax_exemption_code: null,
    };
}

const form = useForm({
    name: '',
    customer_public_id: '',
    establishment_public_id: '',
    document_type: 'FT',
    frequency: 'monthly',
    starts_on: new Date().toISOString().slice(0, 10),
    ends_on: '',
    is_active: true,
    auto_issue: false,
    notes: '',
    lines: [emptyLine()],
});

const catalogueOptions = computed<SelectOption[]>(() => [
    { value: '', label: 'Escrever manualmente' },
    ...props.catalogueItems.map((item) => ({
        value: item.public_id,
        label: `${item.code} — ${item.name}`,
    })),
]);

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.customer_public_id = String(props.customers[0]?.value ?? '');
    form.establishment_public_id = String(props.establishments[0]?.value ?? '');
    form.lines = [emptyLine()];
    dialogOpen.value = true;
}

function openEdit(profile: Profile): void {
    editing.value = profile;
    form.clearErrors();
    form.name = profile.name;
    form.customer_public_id = profile.customer_public_id;
    form.establishment_public_id = profile.establishment_public_id;
    form.document_type = profile.document_type;
    form.frequency = profile.frequency;
    form.starts_on = profile.starts_on.slice(0, 10);
    form.ends_on = profile.ends_on?.slice(0, 10) ?? '';
    form.is_active = profile.is_active;
    form.auto_issue = profile.auto_issue;
    form.notes = profile.notes ?? '';
    form.lines = profile.lines.map((line) => ({ ...line }));
    dialogOpen.value = true;
}

function applyCatalogueItem(line: ProfileLine, publicId: string): void {
    const item = props.catalogueItems.find(
        (candidate) => candidate.public_id === publicId,
    );

    if (item === undefined) {
        return;
    }

    line.product_code = item.code;
    line.product_description = item.description ?? item.name;
    line.unit_of_measure = item.unit_of_measure;
    line.unit_price = item.unit_price;
    line.tax_type = item.tax_type;
    line.tax_code = item.tax_code;
    line.tax_percentage = item.tax_percentage;
    line.tax_exemption_code = item.tax_exemption_code;
}

function catalogueItemValue(line: ProfileLine): string {
    return (
        props.catalogueItems.find((item) => item.code === line.product_code)
            ?.public_id ?? ''
    );
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value === null) {
        form.post(storeProfile.url(), options);

        return;
    }

    form.put(updateProfile.url(editing.value.public_id), options);
}

async function deactivate(profile: Profile): Promise<void> {
    const confirmed = await confirmAction({
        title: `Desactivar “${profile.name}”?`,
        message:
            'Deixa de gerar documentos. Os já emitidos mantêm-se onde estão.',
        confirmLabel: 'Desactivar avença',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyProfile.url(profile.public_id), {
        preserveScroll: true,
    });
}

function runNow(): void {
    router.post(runProfiles.url(), {}, { preserveScroll: true });
}

const moneyFormatter = new Intl.NumberFormat('pt-AO', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

function money(minor: number): string {
    return `${moneyFormatter.format(minor / 100)} ${props.currencyCode}`;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium' });

function formatDate(value: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

const dueCount = computed(
    () =>
        props.profiles.filter(
            (profile) =>
                profile.is_active &&
                new Date(profile.next_run_on) <= new Date(),
        ).length,
);
</script>

<template>
    <AppLayout>
        <Head title="Avenças" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-6xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Documentos · Avenças"
                    title="Avenças"
                    description="Facturação que se repete sozinha, na frequência que escolher. Por omissão deixamos um rascunho para rever — emitir é irreversível, por isso só acontece se o pedir."
                >
                    <template #actions>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                v-if="dueCount > 0"
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-zinc-700 ring-1 ring-zinc-900/10 focus-ring transition hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="runNow"
                            >
                                <Zap class="size-4" aria-hidden="true" />
                                Gerar agora ({{ dueCount }})
                            </button>

                            <button
                                v-if="customers.length > 0"
                                type="button"
                                class="inline-flex h-10 w-fit items-center gap-2 rounded-full bg-accent-400 px-[1.125rem] text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300 disabled:cursor-not-allowed disabled:opacity-50"
                                @click="openCreate"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                Nova avença
                            </button>
                        </div>
                    </template>
                </PageHeader>

                <div
                    v-if="profiles.length === 0"
                    class="flex flex-col items-center gap-2 rounded-3xl bg-zinc-900/[0.04] px-4 py-16 text-center dark:bg-white/[0.04]"
                >
                    <CalendarSync
                        class="size-8 text-zinc-300 dark:text-zinc-600"
                        aria-hidden="true"
                    />
                    <p class="text-sm/6 text-zinc-500 dark:text-zinc-400">
                        Sem avenças. Crie uma para facturar um serviço contínuo
                        sem ter de se lembrar todos os meses.
                    </p>
                </div>

                <ul v-else class="space-y-4">
                    <li
                        v-for="profile in profiles"
                        :key="profile.public_id"
                        class="rounded-3xl bg-zinc-900/[0.04] p-5 dark:bg-white/[0.04]"
                    >
                        <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                            <div class="min-w-0 flex-1">
                                <p
                                    class="text-sm font-semibold text-zinc-950 dark:text-white"
                                >
                                    {{ profile.name }}
                                </p>
                                <p
                                    class="text-sm text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ profile.customer_name }} ·
                                    {{ profile.frequency_label }} ·
                                    {{ profile.document_type_label }}
                                </p>
                            </div>

                            <StatusBadge
                                :tone="
                                    profile.is_active ? 'success' : 'neutral'
                                "
                                :label="profile.is_active ? 'Activa' : 'Parada'"
                            />
                            <StatusBadge
                                v-if="profile.auto_issue"
                                tone="warning"
                                label="Emite sozinha"
                            />

                            <p
                                class="numeric text-sm font-medium text-zinc-950 dark:text-white"
                            >
                                {{ money(profile.estimated_total_minor) }}
                            </p>
                        </div>

                        <dl
                            class="mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-4"
                        >
                            <div>
                                <dt
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Próxima
                                </dt>
                                <dd
                                    class="text-zinc-950 dark:text-white"
                                    :class="
                                        profile.is_active &&
                                        new Date(profile.next_run_on) <=
                                            new Date()
                                            ? 'font-semibold text-amber-700 dark:text-amber-400'
                                            : ''
                                    "
                                >
                                    {{ formatDate(profile.next_run_on) }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Início
                                </dt>
                                <dd class="text-zinc-700 dark:text-zinc-300">
                                    {{ formatDate(profile.starts_on) }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Fim
                                </dt>
                                <dd class="text-zinc-700 dark:text-zinc-300">
                                    {{
                                        profile.ends_on
                                            ? formatDate(profile.ends_on)
                                            : 'Sem fim definido'
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Já gerou
                                </dt>
                                <dd
                                    class="numeric text-zinc-700 dark:text-zinc-300"
                                >
                                    {{ profile.generated_count }} documento(s)
                                </dd>
                            </div>
                        </dl>

                        <div class="mt-4 flex flex-wrap justify-end gap-2">
                            <button
                                type="button"
                                class="inline-flex h-10 items-center justify-center rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="openEdit(profile)"
                            >
                                Editar
                            </button>
                            <button
                                v-if="profile.is_active"
                                type="button"
                                class="inline-flex h-10 items-center justify-center gap-2 rounded-full px-[1.125rem] text-sm font-semibold text-rose-700 ring-1 ring-rose-300 focus-ring transition ring-inset hover:bg-rose-50 dark:text-rose-300 dark:ring-rose-400/30 dark:hover:bg-rose-400/10"
                                @click="deactivate(profile)"
                            >
                                <Pause class="size-4" aria-hidden="true" />
                                Parar
                            </button>
                            <span
                                v-else
                                class="inline-flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <Play class="size-3.5" aria-hidden="true" />
                                Reactive em Editar
                            </span>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <RecordDialog
            eyebrow="Avenças"
            :open="dialogOpen"
            :title="editing ? `Editar ${editing.name}` : 'Nova avença'"
            description="Facturação que se repete sozinha na frequência escolhida."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label
                            for="profile-name"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Nome da avença</label
                        >
                        <input
                            id="profile-name"
                            v-model="form.name"
                            type="text"
                            placeholder="Ex.: manutenção mensal"
                            class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                        />
                        <FormError :message="form.errors.name" />
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Cliente</label
                        >
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.customer_public_id"
                                :options="customers"
                            />
                        </div>
                        <FormError :message="form.errors.customer_public_id" />
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Estabelecimento</label
                        >
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.establishment_public_id"
                                :options="establishments"
                            />
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Frequência</label
                        >
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.frequency"
                                :options="frequencies"
                            />
                        </div>
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Documento a emitir</label
                        >
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.document_type"
                                :options="documentTypes"
                            />
                        </div>
                    </div>

                    <div>
                        <label
                            for="profile-start"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Começa em</label
                        >
                        <DateInput
                            id="profile-start"
                            v-model="form.starts_on"
                            class="mt-2"
                            :clearable="false"
                            aria-label="Data em que a avença começa"
                        />
                        <FormError :message="form.errors.starts_on" />
                    </div>

                    <div>
                        <label
                            for="profile-end"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >Termina em
                            <span class="text-zinc-400">(opcional)</span></label
                        >
                        <DateInput
                            id="profile-end"
                            v-model="form.ends_on"
                            class="mt-2"
                            :min-date="form.starts_on"
                            placeholder="Sem fim"
                            aria-label="Data em que a avença termina"
                        />
                        <FormError :message="form.errors.ends_on" />
                    </div>
                </div>

                <div
                    class="space-y-3 rounded-xl bg-zinc-50 p-4 dark:bg-white/5"
                >
                    <div
                        v-for="(line, index) in form.lines"
                        :key="index"
                        class="grid gap-3 sm:grid-cols-12"
                    >
                        <div class="sm:col-span-6">
                            <SelectInput
                                :model-value="catalogueItemValue(line)"
                                :options="catalogueOptions"
                                @update:model-value="
                                    (value) =>
                                        applyCatalogueItem(line, String(value))
                                "
                            />
                            <input
                                v-model="line.product_description"
                                type="text"
                                placeholder="Descrição"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                            <FormError
                                :message="
                                    form.errors[
                                        `lines.${index}.product_description`
                                    ]
                                "
                            />
                        </div>
                        <div class="sm:col-span-2">
                            <input
                                v-model="line.quantity"
                                type="text"
                                inputmode="decimal"
                                placeholder="Qtd."
                                class="w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </div>
                        <div class="sm:col-span-3">
                            <input
                                v-model="line.unit_price"
                                type="text"
                                inputmode="decimal"
                                placeholder="Preço"
                                class="w-full rounded-xl border-0 bg-white px-3 py-2.5 numeric text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                        </div>
                        <div class="flex items-start sm:col-span-1">
                            <button
                                v-if="form.lines.length > 1"
                                type="button"
                                class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-400/10"
                                @click="form.lines.splice(index, 1)"
                            >
                                <span class="sr-only">Remover linha</span>
                                <Trash2 class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                    </div>

                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-white dark:text-zinc-200 dark:ring-white/15"
                        @click="form.lines.push(emptyLine())"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Linha
                    </button>
                </div>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                    />
                    Activa
                </label>

                <label
                    class="flex w-fit items-start gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.auto_issue"
                        type="checkbox"
                        class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                    />
                    <span>
                        Autorizar emissão automática por 30 dias
                        <span
                            class="block text-xs text-zinc-500 dark:text-zinc-400"
                        >
                            Sem isto fica um rascunho para rever. Com isto o
                            documento é comunicado à AGT sem ninguém confirmar.
                        </span>
                    </span>
                </label>

                <div
                    class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        class="inline-flex h-10 items-center justify-center rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                        @click="dialogOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="inline-flex h-10 items-center justify-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
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
