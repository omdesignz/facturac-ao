<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Boxes,
    Building2,
    FileClock,
    LoaderCircle,
    MapPin,
    Pencil,
    Plus,
    Star,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { show as agtConnection } from '@/routes/agt/connection';
import {
    destroy as destroyEstablishment,
    store as storeEstablishment,
    update as updateEstablishment,
} from '@/routes/establishments';
import type { SelectOption } from '@/types/select';

interface EstablishmentRow {
    public_id: string;
    code: string;
    name: string;
    address_line: string;
    municipality: string;
    province_code: string;
    province_label: string;
    is_head_office: boolean;
    is_active: boolean;
    series_count: number;
    document_count: number;
    stock_count: number;
    can_delete: boolean;
}

const props = defineProps<{
    establishments: EstablishmentRow[];
    provinces: { value: string; label: string }[];
    canManage: boolean;
}>();

const dialogOpen = ref(false);
const editing = ref<EstablishmentRow | null>(null);

const form = useForm({
    code: '',
    name: '',
    address_line: '',
    municipality: '',
    province_code: '',
    is_head_office: false,
    is_active: true,
});

/**
 * A province saved before this list existed is kept as its own option.
 *
 * This field once took a typed code, so some companies hold things like "LU";
 * and Cuando Cubango existed until the 2024 reform split it. Dropping either
 * on the next save would quietly rewrite an address already on documents.
 */
const provinceOptions = computed<SelectOption[]>(() => {
    const known = props.provinces.map((province) => ({
        value: province.value,
        label: province.label,
    }));

    const current = editing.value?.province_code;

    return current && !known.some((option) => option.value === current)
        ? [
              ...known,
              {
                  value: current,
                  label: `${editing.value?.province_label} · manter`,
              },
          ]
        : known;
});

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    dialogOpen.value = true;
}

function openEdit(establishment: EstablishmentRow): void {
    editing.value = establishment;
    form.clearErrors();
    form.code = establishment.code;
    form.name = establishment.name;
    form.address_line = establishment.address_line;
    form.municipality = establishment.municipality;
    form.province_code = establishment.province_code;
    form.is_head_office = establishment.is_head_office;
    form.is_active = establishment.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
    };

    if (editing.value === null) {
        form.post(storeEstablishment.url(), options);

        return;
    }

    form.put(
        updateEstablishment.url({ establishment: editing.value.public_id }),
        options,
    );
}

async function remove(establishment: EstablishmentRow): Promise<void> {
    const confirmed = await confirmAction({
        title: establishment.can_delete
            ? `Remover ${establishment.name}?`
            : `Desactivar ${establishment.name}?`,
        message: establishment.can_delete
            ? 'Nada aponta ainda para este estabelecimento, por isso desaparece por completo.'
            : 'Já emitiu ou guarda existências, por isso fica registado — deixa apenas de aparecer ao emitir.',
        confirmLabel: establishment.can_delete
            ? 'Remover estabelecimento'
            : 'Desactivar estabelecimento',
    });

    if (!confirmed) {
        return;
    }

    router.delete(
        destroyEstablishment.url({ establishment: establishment.public_id }),
        { preserveScroll: true },
    );
}

/** The head office is where the company is, not merely one more address. */
const headOffice = computed(() =>
    props.establishments.find((establishment) => establishment.is_head_office),
);

const withoutSeries = computed(() =>
    props.establishments.filter(
        (establishment) =>
            establishment.is_active && establishment.series_count === 0,
    ),
);
</script>

<template>
    <AppLayout>
        <Head title="Estabelecimentos" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-5xl space-y-6">
                <FlashBanner />

                <header
                    class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <p class="eyebrow text-brand-700 dark:text-brand-300">
                            Onde a empresa opera
                        </p>
                        <h1
                            class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                        >
                            Estabelecimentos
                        </h1>
                        <p
                            class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Cada loja, armazém ou posto tem a sua própria série
                            da AGT e as suas próprias existências. É por isso
                            que uma factura diz de onde foi emitida, e não só
                            por quem.
                        </p>
                    </div>

                    <button
                        v-if="canManage"
                        type="button"
                        class="inline-flex w-fit items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm focus-ring transition hover:bg-brand-600 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                        @click="openCreate"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Novo estabelecimento
                    </button>
                </header>

                <div
                    v-if="withoutSeries.length > 0"
                    class="flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm/6 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-400/10 dark:text-amber-200 dark:ring-amber-400/20"
                >
                    <FileClock
                        class="mt-0.5 size-5 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        {{
                            withoutSeries.length === 1
                                ? `${withoutSeries[0].name} ainda não tem série da AGT`
                                : `${withoutSeries.length} estabelecimentos ainda não têm série da AGT`
                        }}, por isso não é possível emitir de lá.
                        <Link
                            :href="agtConnection.url()"
                            class="font-semibold underline-offset-2 hover:underline"
                            >Sincronize as séries</Link
                        >
                        depois de as abrir no portal.
                    </p>
                </div>

                <ul class="grid gap-4">
                    <li
                        v-for="establishment in establishments"
                        :key="establishment.public_id"
                        class="rounded-2xl surface p-5"
                        :class="establishment.is_active ? '' : 'opacity-70'"
                    >
                        <div
                            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
                        >
                            <div class="flex min-w-0 gap-4">
                                <span
                                    :class="[
                                        establishment.is_head_office
                                            ? 'bg-brand-100 text-brand-700 dark:bg-brand-400/10 dark:text-brand-300'
                                            : 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400',
                                        'grid size-11 shrink-0 place-items-center rounded-xl',
                                    ]"
                                >
                                    <Building2
                                        class="size-5"
                                        aria-hidden="true"
                                    />
                                </span>

                                <div class="min-w-0">
                                    <p
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <span
                                            class="font-semibold text-zinc-950 dark:text-white"
                                            >{{ establishment.name }}</span
                                        >
                                        <span
                                            class="rounded-md bg-zinc-100 px-1.5 py-0.5 numeric text-xs font-medium text-zinc-600 dark:bg-white/10 dark:text-zinc-300"
                                            >{{ establishment.code }}</span
                                        >
                                        <span
                                            v-if="establishment.is_head_office"
                                            class="inline-flex items-center gap-1 rounded-md bg-brand-50 px-1.5 py-0.5 text-xs font-medium text-brand-700 ring-1 ring-brand-600/20 dark:bg-brand-400/10 dark:text-brand-300 dark:ring-brand-400/20"
                                        >
                                            <Star
                                                class="size-3"
                                                aria-hidden="true"
                                            />
                                            Sede
                                        </span>
                                        <StatusBadge
                                            v-if="!establishment.is_active"
                                            label="Inactivo"
                                            tone="neutral"
                                        />
                                    </p>

                                    <p
                                        class="mt-1 flex items-start gap-1.5 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        <MapPin
                                            class="mt-1 size-3.5 shrink-0 text-zinc-400"
                                            aria-hidden="true"
                                        />
                                        <span
                                            >{{ establishment.address_line
                                            }}<template
                                                v-if="
                                                    establishment.municipality
                                                "
                                            >
                                                ·
                                                {{ establishment.municipality }}
                                            </template>
                                            ·
                                            {{
                                                establishment.province_label
                                            }}</span
                                        >
                                    </p>

                                    <p
                                        class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <FileClock
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <span class="numeric"
                                                >{{
                                                    establishment.series_count
                                                }}
                                                série(s)</span
                                            >
                                        </span>
                                        <span class="numeric"
                                            >{{
                                                establishment.document_count
                                            }}
                                            documento(s)</span
                                        >
                                        <span
                                            v-if="establishment.stock_count > 0"
                                            class="inline-flex items-center gap-1.5"
                                        >
                                            <Boxes
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />
                                            <span class="numeric"
                                                >{{
                                                    establishment.stock_count
                                                }}
                                                artigo(s) em stock</span
                                            >
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="canManage"
                                class="flex shrink-0 items-center gap-1"
                            >
                                <button
                                    type="button"
                                    class="icon-button text-zinc-400 focus-ring transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-white/5 dark:hover:text-white"
                                    @click="openEdit(establishment)"
                                >
                                    <span class="sr-only"
                                        >Editar {{ establishment.name }}</span
                                    >
                                    <Pencil class="size-4" aria-hidden="true" />
                                </button>
                                <button
                                    v-if="!establishment.is_head_office"
                                    type="button"
                                    class="icon-button text-zinc-400 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                    @click="remove(establishment)"
                                >
                                    <span class="sr-only"
                                        >{{
                                            establishment.can_delete
                                                ? 'Remover'
                                                : 'Desactivar'
                                        }}
                                        {{ establishment.name }}</span
                                    >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>

                <p
                    v-if="headOffice"
                    class="text-xs text-zinc-500 dark:text-zinc-400"
                >
                    A sede é o endereço que segue para a AGT como morada do
                    contribuinte. Para a mudar, marque outro estabelecimento
                    como sede.
                </p>
            </div>
        </div>

        <RecordDialog
            :open="dialogOpen"
            :title="
                editing === null
                    ? 'Novo estabelecimento'
                    : `Editar ${editing.name}`
            "
            description="O código aparece nos documentos emitidos daqui e não pode repetir-se dentro da empresa."
            @close="dialogOpen = false"
        >
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label
                            for="establishment-code"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Código
                        </label>
                        <input
                            id="establishment-code"
                            v-model="form.code"
                            type="text"
                            required
                            placeholder="LOJA2"
                            class="mt-2 w-full rounded-xl bg-white px-3.5 py-2.5 numeric text-sm text-zinc-950 uppercase ring-1 ring-zinc-200 focus-ring transition dark:bg-white/5 dark:text-white dark:ring-white/15"
                        />
                        <FormError :message="form.errors.code" />
                    </div>

                    <div>
                        <label
                            for="establishment-name"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Nome
                        </label>
                        <input
                            id="establishment-name"
                            v-model="form.name"
                            type="text"
                            required
                            placeholder="Loja do Kilamba"
                            class="mt-2 w-full rounded-xl bg-white px-3.5 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring transition dark:bg-white/5 dark:text-white dark:ring-white/15"
                        />
                        <FormError :message="form.errors.name" />
                    </div>

                    <div class="sm:col-span-2">
                        <label
                            for="establishment-address"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Morada
                        </label>
                        <input
                            id="establishment-address"
                            v-model="form.address_line"
                            type="text"
                            required
                            placeholder="Rua 21 de Janeiro, n.º 4"
                            class="mt-2 w-full rounded-xl bg-white px-3.5 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring transition dark:bg-white/5 dark:text-white dark:ring-white/15"
                        />
                        <FormError :message="form.errors.address_line" />
                    </div>

                    <div>
                        <label
                            for="establishment-municipality"
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Município
                            <span class="text-zinc-400">(opcional)</span>
                        </label>
                        <input
                            id="establishment-municipality"
                            v-model="form.municipality"
                            type="text"
                            placeholder="Belas"
                            class="mt-2 w-full rounded-xl bg-white px-3.5 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring transition dark:bg-white/5 dark:text-white dark:ring-white/15"
                        />
                        <FormError :message="form.errors.municipality" />
                    </div>

                    <div>
                        <label
                            class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                        >
                            Província
                        </label>
                        <div class="mt-2">
                            <SelectInput
                                v-model="form.province_code"
                                :options="provinceOptions"
                                placeholder="Escolha a província"
                            />
                        </div>
                        <FormError :message="form.errors.province_code" />
                    </div>
                </div>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_head_office"
                        type="checkbox"
                        :disabled="editing?.is_head_office"
                        class="size-4 rounded border-zinc-300 text-brand-700 focus-ring disabled:opacity-60 dark:border-white/15 dark:bg-white/5"
                    />
                    É a sede da empresa
                </label>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        :disabled="editing?.is_head_office"
                        class="size-4 rounded border-zinc-300 text-brand-700 focus-ring disabled:opacity-60 dark:border-white/15 dark:bg-white/5"
                    />
                    Disponível ao emitir documentos
                </label>

                <p
                    v-if="editing?.is_head_office"
                    class="text-xs text-zinc-500 dark:text-zinc-400"
                >
                    A sede está sempre activa. Para a mudar, marque outro
                    estabelecimento como sede.
                </p>

                <div class="flex justify-end gap-3 pt-2">
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
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-700 px-4 py-2.5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-600 disabled:opacity-60 dark:bg-accent-400 dark:text-brand-950 dark:hover:bg-accent-300"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin"
                            aria-hidden="true"
                        />
                        {{
                            editing === null
                                ? 'Criar estabelecimento'
                                : 'Guardar alterações'
                        }}
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
