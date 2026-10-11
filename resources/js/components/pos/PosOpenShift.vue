<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { LoaderCircle, LockKeyhole, Plus, Store } from '@lucide/vue';
import { computed, ref } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { focusFirstInvalid } from '@/lib/focus';
import { minorToDecimalString, parseMoneyToMinor } from '@/lib/pos';
import {
    buttonGold,
    buttonOutline,
    currencyLabel,
    formatClock,
    wellItem,
    wellSection,
} from '@/lib/pos-ui';
import { show as agtConnection } from '@/routes/agt/connection';
import { index as registersIndex } from '@/routes/pos/registers';
import {
    index as sessionsIndex,
    store as storeSession,
} from '@/routes/pos/sessions';
import type { PosRegisterChoice } from '@/types/pos';

const props = defineProps<{
    registers: PosRegisterChoice[];
    canSell: boolean;
    canManageRegisters: boolean;
    currencyCode: string;
}>();

type RegisterState = 'free' | 'busy' | 'no-series';

/** A switched-off till is not offered at all. */
const visibleRegisters = computed(() =>
    props.registers.filter((register) => register.is_active),
);

function stateOf(register: PosRegisterChoice): RegisterState {
    if (register.open_session !== null) {
        return 'busy';
    }

    return register.has_series ? 'free' : 'no-series';
}

const freeRegisters = computed(() =>
    visibleRegisters.value.filter((register) => stateOf(register) === 'free'),
);

const form = useForm({
    pos_register_public_id:
        freeRegisters.value.length === 1
            ? (freeRegisters.value[0]?.public_id ?? '')
            : '',
    opening_float: '0',
});

const formElement = ref<HTMLFormElement | null>(null);

const floatMinor = computed(() => parseMoneyToMinor(form.opening_float));
const floatProblem = computed(() =>
    floatMinor.value === null
        ? 'Indique o fundo de caixa em kwanzas, por exemplo 5 000 ou 2 500,50.'
        : undefined,
);
const floatError = computed(
    () =>
        form.errors.opening_float ??
        (touchedFloat.value ? floatProblem.value : undefined),
);
const touchedFloat = ref(false);

const canSubmit = computed(
    () =>
        props.canSell &&
        form.pos_register_public_id !== '' &&
        floatMinor.value !== null &&
        !form.processing,
);

function submit(): void {
    touchedFloat.value = true;

    if (floatMinor.value === null) {
        void focusFirstInvalid(formElement.value);

        return;
    }

    if (!canSubmit.value) {
        return;
    }

    const amount = minorToDecimalString(floatMinor.value);

    form.transform((data) => ({ ...data, opening_float: amount })).post(
        storeSession.url(),
        {
            onError: () => void focusFirstInvalid(formElement.value),
        },
    );
}
</script>

<template>
    <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
        <div class="mx-auto max-w-3xl space-y-6">
            <FlashBanner />

            <PageHeader
                eyebrow="Vendas · Ponto de venda"
                title="Ponto de venda"
                description="Abra uma caixa para começar a vender. Cada venda sai como factura-recibo, já comunicada à AGT."
            >
                <template #actions>
                    <Link :href="sessionsIndex.url()" :class="buttonOutline">
                        Turnos
                    </Link>
                    <Link
                        v-if="canManageRegisters"
                        :href="registersIndex.url()"
                        :class="buttonOutline"
                    >
                        Caixas
                    </Link>
                </template>
            </PageHeader>

            <div v-if="!canSell" :class="[wellSection, 'flex gap-3 p-5']">
                <LockKeyhole
                    class="mt-0.5 size-5 shrink-0 text-zinc-500 dark:text-zinc-400"
                    aria-hidden="true"
                />
                <p class="text-sm/6 text-zinc-700 dark:text-zinc-300">
                    O seu perfil permite consultar, não vender.
                </p>
            </div>

            <div
                v-else-if="visibleRegisters.length === 0"
                :class="[wellSection, 'px-6 py-14 text-center']"
            >
                <Store
                    class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                    aria-hidden="true"
                />
                <p class="mt-4 font-semibold text-zinc-900 dark:text-white">
                    Ainda não há caixas.
                </p>
                <template v-if="canManageRegisters">
                    <p
                        class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Uma caixa é o balcão onde se vende. Crie a primeira para
                        abrir um turno.
                    </p>
                    <Link
                        :href="registersIndex.url()"
                        :class="[buttonGold, 'mt-6']"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Criar caixa
                    </Link>
                </template>
                <p
                    v-else
                    class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                >
                    Peça a um administrador para criar a primeira caixa.
                </p>
            </div>

            <form
                v-else
                ref="formElement"
                class="space-y-6"
                novalidate
                @submit.prevent="submit"
            >
                <fieldset
                    :aria-describedby="
                        form.errors.pos_register_public_id
                            ? 'register-error'
                            : undefined
                    "
                >
                    <legend
                        class="mb-3 eyebrow text-zinc-500 dark:text-zinc-400"
                    >
                        Escolha a caixa
                    </legend>

                    <ul
                        class="grid gap-2.5"
                        role="radiogroup"
                        aria-label="Caixa"
                    >
                        <li
                            v-for="(register, index) in visibleRegisters"
                            :key="register.public_id"
                        >
                            <label
                                :class="[
                                    wellItem,
                                    'flex min-h-16 items-center gap-4 p-4 has-focus-visible:outline-2 has-focus-visible:outline-offset-2 has-focus-visible:outline-brand-600 dark:has-focus-visible:outline-brand-300',
                                    stateOf(register) === 'free'
                                        ? 'cursor-pointer has-checked:bg-white has-checked:ring-2 has-checked:ring-brand-950 dark:has-checked:bg-white/[0.08] dark:has-checked:ring-zinc-200'
                                        : 'cursor-not-allowed',
                                ]"
                            >
                                <input
                                    v-model="form.pos_register_public_id"
                                    type="radio"
                                    name="pos_register_public_id"
                                    :value="register.public_id"
                                    :disabled="stateOf(register) !== 'free'"
                                    :aria-invalid="
                                        form.errors.pos_register_public_id &&
                                        index === 0
                                            ? 'true'
                                            : undefined
                                    "
                                    class="size-4 shrink-0 disabled:opacity-40"
                                />
                                <span
                                    :class="[
                                        'min-w-0 flex-1',
                                        stateOf(register) === 'free'
                                            ? ''
                                            : 'opacity-70',
                                    ]"
                                >
                                    <span
                                        class="block font-semibold [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                        >{{ register.name }}</span
                                    >
                                    <span
                                        class="mt-0.5 block text-sm [overflow-wrap:anywhere] text-zinc-600 dark:text-zinc-400"
                                        >{{ register.establishment.name }}</span
                                    >
                                </span>
                                <StatusBadge
                                    v-if="stateOf(register) === 'free'"
                                    label="Livre"
                                    tone="success"
                                />
                                <StatusBadge
                                    v-else-if="register.open_session"
                                    class="shrink-0"
                                    :label="`Em uso por ${register.open_session.opened_by_name} desde ${formatClock(register.open_session.opened_at)}`"
                                    tone="neutral"
                                />
                                <StatusBadge
                                    v-else
                                    class="shrink-0"
                                    label="Sem série FR"
                                    tone="warning"
                                />
                            </label>
                            <p
                                v-if="stateOf(register) === 'no-series'"
                                class="mt-1.5 ps-4 text-sm/6 text-zinc-600 dark:text-zinc-400"
                            >
                                Esta caixa não pode vender até a AGT atribuir
                                uma série de Factura/Recibo.
                                <Link
                                    :href="agtConnection.url()"
                                    class="rounded font-semibold text-zinc-900 underline underline-offset-4 focus-ring dark:text-white"
                                    >Pedir ou sincronizar séries</Link
                                >
                            </p>
                        </li>
                    </ul>
                    <FormError
                        id="register-error"
                        :message="form.errors.pos_register_public_id"
                    />
                    <p
                        v-if="freeRegisters.length === 0"
                        class="mt-3 text-sm/6 text-zinc-600 dark:text-zinc-400"
                        role="status"
                    >
                        Nenhuma caixa está livre neste momento.
                    </p>
                </fieldset>

                <div :class="[wellSection, 'p-5 sm:p-6']">
                    <label
                        for="pos-opening-float"
                        class="block text-sm font-medium text-zinc-900 dark:text-white"
                        >Fundo de caixa</label
                    >
                    <div class="relative mt-2 max-w-xs">
                        <input
                            id="pos-opening-float"
                            v-model="form.opening_float"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            spellcheck="false"
                            class="block h-12 form-input pe-12 text-end numeric text-lg"
                            :aria-invalid="floatError ? 'true' : undefined"
                            :aria-describedby="
                                floatError
                                    ? 'pos-opening-float-hint pos-opening-float-error'
                                    : 'pos-opening-float-hint'
                            "
                            @blur="touchedFloat = true"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <span
                            class="pointer-events-none absolute inset-y-0 inset-e-4 grid place-items-center text-sm text-zinc-500 dark:text-zinc-400"
                            aria-hidden="true"
                            >{{ currencyLabel(currencyCode) }}</span
                        >
                    </div>
                    <p
                        id="pos-opening-float-hint"
                        class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                    >
                        O dinheiro que já está na gaveta ao abrir.
                    </p>
                    <FormError
                        id="pos-opening-float-error"
                        :message="floatError"
                    />
                </div>

                <div class="flex justify-end">
                    <button
                        type="submit"
                        :disabled="!canSubmit"
                        :class="[buttonGold, 'w-full sm:w-auto sm:min-w-44']"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin-delayed"
                            aria-hidden="true"
                        />
                        Abrir caixa
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
