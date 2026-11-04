<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, Store, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { focusFirstInvalid } from '@/lib/focus';
import {
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

        <div class="px-4 py-6 sm:px-6 lg:px-8 lg:py-7">
            <div class="max-w-4xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Vender · Ponto de venda"
                    title="Caixas"
                    description="Cada caixa é um balcão onde se vende, num estabelecimento. Só uma pessoa de cada vez tem um turno aberto numa caixa."
                >
                    <template #actions>
                        <AppButton
                            variant="outline"
                            :href="sessionsIndex.url()"
                        >
                            Turnos
                        </AppButton>
                        <AppButton variant="outline" :href="posShow.url()">
                            Ponto de venda
                        </AppButton>
                        <AppButton
                            variant="gold"
                            v-if="canManage && establishments.length > 0"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Nova caixa
                        </AppButton>
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

                <EmptyState
                    v-if="registers.length === 0 && establishments.length > 0"
                    :icon="Store"
                    title="Ainda não há caixas"
                    description="Crie a primeira para poder abrir um turno."
                >
                    <AppButton
                        variant="gold"
                        v-if="canManage"
                        @click="openCreate"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Nova caixa
                    </AppButton>
                </EmptyState>

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
                                                ? 'neutral'
                                                : 'draft'
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
                <FormField
                    for="register-name"
                    label="Nome"
                    :error="form.errors.name"
                    error-id="register-name-error"
                    ><input
                        id="register-name"
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="60"
                        autocomplete="off"
                        placeholder="Caixa 1"
                        class="block form-input"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.name ? 'register-name-error' : undefined
                        "
                /></FormField>

                <FormField
                    for="register-establishment"
                    label="Estabelecimento"
                    :error="form.errors.establishment_public_id"
                    error-id="register-establishment-error"
                    ><SelectInput
                        id="register-establishment"
                        v-model="form.establishment_public_id"
                        :options="establishmentOptions"
                        placeholder="Escolha o estabelecimento"
                        :invalid="!!form.errors.establishment_public_id"
                        :describedby="
                            form.errors.establishment_public_id
                                ? 'register-establishment-error'
                                : undefined
                        "
                /></FormField>

                <label
                    class="flex w-fit items-center gap-3 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="form.is_active"
                        type="checkbox"
                        class="form-checkbox"
                        :aria-invalid="!!form.errors.is_active"
                    />
                    Activa: pode abrir-se um turno nesta caixa
                </label>
                <FormError :message="form.errors.is_active" />

                <div
                    class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end"
                >
                    <AppButton variant="outline" @click="dialogOpen = false">
                        Cancelar
                    </AppButton>
                    <AppButton
                        variant="ink"
                        type="submit"
                        :disabled="form.processing"
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
                    </AppButton>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
