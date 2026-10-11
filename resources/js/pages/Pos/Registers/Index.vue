<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, Store, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { focusFirstInvalid } from '@/lib/focus';
import {
    buttonGold,
    buttonInk,
    buttonOutline,
    formatClock,
    wellItem,
    wellSection,
} from '@/lib/pos-ui';
import { index as establishmentsIndex } from '@/routes/establishments';
import { show as posShow } from '@/routes/pos';
import {
    destroy as destroyRegister,
    store as storeRegister,
    update as updateRegister,
} from '@/routes/pos/registers';
import { index as sessionsIndex } from '@/routes/pos/sessions';
import type { SelectOption } from '@/types/select';

interface RegisterRow {
    public_id: string;
    name: string;
    is_active: boolean;
    establishment: { public_id: string; name: string };
    sessions_count: number;
    open_session: {
        public_id: string;
        opened_by_name: string;
        opened_at: string;
        is_mine: boolean;
    } | null;
}

const props = defineProps<{
    registers: RegisterRow[];
    establishments: { public_id: string; name: string }[];
    canManage: boolean;
}>();

const dialogOpen = ref(false);
const editing = ref<RegisterRow | null>(null);
const formElement = ref<HTMLFormElement | null>(null);

const form = useForm({
    establishment_public_id: '',
    name: '',
    is_active: true,
});

const establishmentOptions = computed<SelectOption[]>(() =>
    props.establishments.map((establishment) => ({
        value: establishment.public_id,
        label: establishment.name,
    })),
);

function openCreate(): void {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.establishment_public_id = props.establishments[0]?.public_id ?? '';
    dialogOpen.value = true;
}

function openEdit(register: RegisterRow): void {
    editing.value = register;
    form.clearErrors();
    form.establishment_public_id = register.establishment.public_id;
    form.name = register.name;
    form.is_active = register.is_active;
    dialogOpen.value = true;
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            dialogOpen.value = false;
        },
        onError: () => void focusFirstInvalid(formElement.value),
    };

    if (editing.value === null) {
        form.post(storeRegister.url(), options);

        return;
    }

    form.put(updateRegister.url(editing.value.public_id), options);
}

async function remove(register: RegisterRow): Promise<void> {
    const confirmed = await confirmAction({
        title: `Remover ${register.name}?`,
        message:
            'Esta caixa nunca teve turnos, por isso desaparece por completo. Uma caixa que já vendeu só se desactiva.',
        confirmLabel: 'Remover caixa',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyRegister.url(register.public_id), {
        preserveScroll: true,
    });
}

/** A till that has run a shift is kept for the books and switched off instead. */
function canDelete(register: RegisterRow): boolean {
    return register.sessions_count === 0;
}
</script>

<template>
    <AppLayout>
        <Head title="Caixas" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-4xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vendas · Ponto de venda"
                    title="Caixas"
                    description="Cada caixa é um balcão onde se vende, num estabelecimento. Só uma pessoa de cada vez tem um turno aberto numa caixa."
                >
                    <template #actions>
                        <Link
                            :href="sessionsIndex.url()"
                            :class="buttonOutline"
                        >
                            Turnos
                        </Link>
                        <Link :href="posShow.url()" :class="buttonOutline">
                            Ponto de venda
                        </Link>
                        <button
                            v-if="canManage && establishments.length > 0"
                            type="button"
                            :class="buttonGold"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Nova caixa
                        </button>
                    </template>
                </PageHeader>

                <div
                    v-if="establishments.length === 0"
                    :class="[wellSection, 'px-6 py-10 text-center']"
                >
                    <p class="font-semibold text-zinc-900 dark:text-white">
                        Falta um estabelecimento activo
                    </p>
                    <p
                        class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Cada caixa pertence a um estabelecimento.
                    </p>
                    <Link
                        :href="establishmentsIndex.url()"
                        :class="[buttonOutline, 'mt-5']"
                    >
                        Abrir estabelecimentos
                    </Link>
                </div>

                <div
                    v-if="registers.length === 0 && establishments.length > 0"
                    :class="[wellSection, 'px-6 py-14 text-center']"
                >
                    <Store
                        class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold text-zinc-900 dark:text-white">
                        Ainda não há caixas.
                    </p>
                    <p
                        class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Crie a primeira para poder abrir um turno.
                    </p>
                    <button
                        v-if="canManage"
                        type="button"
                        :class="[buttonGold, 'mt-6']"
                        @click="openCreate"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Nova caixa
                    </button>
                </div>

                <ul v-if="registers.length > 0" class="grid gap-2.5">
                    <li
                        v-for="register in registers"
                        :key="register.public_id"
                        :class="[wellItem, 'p-4 sm:p-5']"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5"
                                >
                                    <span
                                        class="font-semibold [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                        >{{ register.name }}</span
                                    >
                                    <StatusBadge
                                        :label="
                                            register.is_active
                                                ? 'Activa'
                                                : 'Inactiva'
                                        "
                                        :tone="
                                            register.is_active
                                                ? 'success'
                                                : 'neutral'
                                        "
                                    />
                                    <StatusBadge
                                        v-if="register.open_session"
                                        label="Em uso"
                                        tone="info"
                                    />
                                </p>
                                <p
                                    class="mt-1 text-sm [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                                >
                                    {{ register.establishment.name }}
                                </p>
                                <p
                                    class="mt-1.5 numeric text-xs text-zinc-600 dark:text-zinc-400"
                                >
                                    <template v-if="register.open_session">
                                        Turno de
                                        {{
                                            register.open_session.opened_by_name
                                        }}
                                        desde
                                        {{
                                            formatClock(
                                                register.open_session.opened_at,
                                            )
                                        }}
                                        ·
                                    </template>
                                    {{ register.sessions_count }}
                                    {{
                                        register.sessions_count === 1
                                            ? 'turno'
                                            : 'turnos'
                                    }}
                                </p>
                            </div>

                            <div
                                v-if="canManage"
                                class="flex shrink-0 items-center gap-1"
                            >
                                <button
                                    type="button"
                                    class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.06] hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                                    @click="openEdit(register)"
                                >
                                    <span class="sr-only"
                                        >Editar {{ register.name }}</span
                                    >
                                    <Pencil class="size-4" aria-hidden="true" />
                                </button>
                                <button
                                    v-if="canDelete(register)"
                                    type="button"
                                    class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:text-zinc-400 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                    @click="remove(register)"
                                >
                                    <span class="sr-only"
                                        >Remover {{ register.name }}</span
                                    >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <RecordDialog
            eyebrow="Caixas"
            :open="dialogOpen"
            :title="editing === null ? 'Nova caixa' : `Editar ${editing.name}`"
            description="O nome identifica a caixa nos turnos e no fecho. Não pode repetir-se no mesmo estabelecimento."
            @close="dialogOpen = false"
        >
            <form
                ref="formElement"
                class="space-y-5"
                novalidate
                @submit.prevent="submit"
            >
                <div>
                    <label
                        for="register-name"
                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                        >Nome</label
                    >
                    <input
                        id="register-name"
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="60"
                        autocomplete="off"
                        placeholder="Caixa 1"
                        class="mt-2 block form-input"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.name ? 'register-name-error' : undefined
                        "
                    />
                    <FormError
                        id="register-name-error"
                        :message="form.errors.name"
                    />
                </div>

                <div>
                    <label
                        for="register-establishment"
                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                        >Estabelecimento</label
                    >
                    <SelectInput
                        id="register-establishment"
                        v-model="form.establishment_public_id"
                        class="mt-2"
                        :options="establishmentOptions"
                        placeholder="Escolha o estabelecimento"
                        :invalid="!!form.errors.establishment_public_id"
                        :describedby="
                            form.errors.establishment_public_id
                                ? 'register-establishment-error'
                                : undefined
                        "
                    />
                    <FormError
                        id="register-establishment-error"
                        :message="form.errors.establishment_public_id"
                    />
                </div>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/15 dark:bg-white/5"
                    />
                    Activa: pode abrir-se um turno nesta caixa
                </label>
                <FormError :message="form.errors.is_active" />

                <div
                    class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        :class="buttonOutline"
                        @click="dialogOpen = false"
                    >
                        Cancelar
                    </button>
                    <button
                        type="submit"
                        :disabled="form.processing"
                        :class="buttonInk"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin-delayed"
                            aria-hidden="true"
                        />
                        {{
                            editing === null
                                ? 'Criar caixa'
                                : 'Guardar alterações'
                        }}
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
