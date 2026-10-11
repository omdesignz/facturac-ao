<script setup lang="ts">
import { Check, UserRound } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import FormError from '@/components/FormError.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import { foldSearch, isValidNif, normaliseNif } from '@/lib/pos';
import { buttonOutline } from '@/lib/pos-ui';
import type {
    PosCustomer,
    PosCustomerChoice,
    PosFinalConsumer,
} from '@/types/pos';

const props = defineProps<{
    open: boolean;
    current: PosCustomerChoice;
    customers: PosCustomer[];
    finalConsumer: PosFinalConsumer;
}>();

const emit = defineEmits<{
    close: [];
    afterLeave: [];
    choose: [choice: PosCustomerChoice];
}>();

/** A long list is searched, not scrolled; this is how much is drawn. */
const MAX_ROWS = 50;

const query = ref('');
const highlight = ref(0);
const typedName = ref('');
const typedNif = ref('');
const typedTried = ref(false);
const searchField = ref<HTMLInputElement | null>(null);

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        query.value = '';
        highlight.value = 0;
        typedTried.value = false;
        typedName.value =
            props.current.kind === 'typed' ? props.current.name : '';
        typedNif.value =
            props.current.kind === 'typed' ? props.current.nif : '';
    },
);

const filtered = computed(() => {
    const tokens = foldSearch(query.value.trim()).split(/\s+/).filter(Boolean);

    if (tokens.length === 0) {
        return props.customers;
    }

    return props.customers.filter((customer) => {
        const haystack = foldSearch(
            `${customer.name} ${customer.tax_identification_number}`,
        );

        return tokens.every((token) => haystack.includes(token));
    });
});

const visible = computed(() => filtered.value.slice(0, MAX_ROWS));

/*
 * Someone who has typed a name wants the customer it found, so Enter lands on
 * the first match; with nothing typed, or nothing found, it stays on the
 * final consumer.
 */
watch([query, visible], () => {
    highlight.value =
        query.value.trim() !== '' && visible.value.length > 0 ? 1 : 0;
});

/** Row 0 is always the final consumer; saved customers follow. */
const rowCount = computed(() => visible.value.length + 1);

function optionId(index: number): string {
    return `pos-customer-option-${index}`;
}

function isCurrentFinal(): boolean {
    return props.current.kind === 'final';
}

function isCurrentSaved(customer: PosCustomer): boolean {
    return (
        props.current.kind === 'saved' &&
        props.current.public_id === customer.public_id
    );
}

function chooseFinal(): void {
    emit('choose', { kind: 'final' });
    emit('close');
}

function chooseSaved(customer: PosCustomer): void {
    emit('choose', {
        kind: 'saved',
        public_id: customer.public_id,
        name: customer.name,
        nif: customer.tax_identification_number,
    });
    emit('close');
}

function chooseHighlighted(): void {
    if (highlight.value === 0) {
        chooseFinal();

        return;
    }

    const customer = visible.value[highlight.value - 1];

    if (customer !== undefined) {
        chooseSaved(customer);
    }
}

function moveHighlight(step: number): void {
    highlight.value =
        (highlight.value + step + rowCount.value) % rowCount.value;

    void nextTick(() =>
        document
            .getElementById(optionId(highlight.value))
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onSearchKeydown(event: KeyboardEvent): void {
    if (event.isComposing) {
        return;
    }

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        moveHighlight(1);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        moveHighlight(-1);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        chooseHighlighted();
    }
}

const nameProblem = computed(() => {
    const name = typedName.value.trim();

    return name.length >= 2 && name.length <= 200
        ? undefined
        : 'Escreva o nome, com pelo menos duas letras.';
});

const nifProblem = computed(() =>
    isValidNif(typedNif.value)
        ? undefined
        : 'O NIF tem 9 a 32 letras ou números.',
);

function useTyped(): void {
    typedTried.value = true;

    if (nameProblem.value !== undefined || nifProblem.value !== undefined) {
        void nextTick(() => {
            document
                .querySelector<HTMLElement>(
                    '#pos-customer-typed [aria-invalid="true"]',
                )
                ?.focus();
        });

        return;
    }

    emit('choose', {
        kind: 'typed',
        name: typedName.value.trim(),
        nif: normaliseNif(typedNif.value),
    });
    emit('close');
}

function uppercaseNif(): void {
    typedNif.value = normaliseNif(typedNif.value);
}

defineExpose({ searchField });
</script>

<template>
    <RecordDialog
        :open="open"
        eyebrow="Venda"
        title="Cliente da venda"
        description="Sem escolher ninguém, a factura-recibo sai para consumidor final."
        @close="emit('close')"
        @after-leave="emit('afterLeave')"
    >
        <div class="space-y-6">
            <div>
                <label
                    for="pos-customer-search"
                    class="block text-sm font-medium text-zinc-900 dark:text-white"
                    >Procurar cliente guardado</label
                >
                <input
                    id="pos-customer-search"
                    ref="searchField"
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    aria-controls="pos-customer-list"
                    :aria-activedescendant="optionId(highlight)"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="Nome ou NIF…"
                    class="mt-2 block form-input"
                    @keydown="onSearchKeydown"
                />

                <ul
                    id="pos-customer-list"
                    role="listbox"
                    aria-label="Clientes"
                    class="mt-3 max-h-64 space-y-1 overflow-y-auto overscroll-contain"
                >
                    <li
                        :id="optionId(0)"
                        role="option"
                        :aria-selected="highlight === 0"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl px-3 py-2 hover:bg-zinc-900/[0.05] dark:hover:bg-white/5"
                        :class="
                            highlight === 0
                                ? 'bg-zinc-900/[0.06] dark:bg-white/10'
                                : ''
                        "
                        @click="chooseFinal"
                        @mousemove="highlight = 0"
                    >
                        <UserRound
                            class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
                            aria-hidden="true"
                        />
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-medium text-zinc-950 dark:text-white"
                                >{{ finalConsumer.name }}</span
                            >
                            <span
                                class="block text-xs text-zinc-500 dark:text-zinc-400"
                                >Sem NIF na factura</span
                            >
                        </span>
                        <Check
                            v-if="isCurrentFinal()"
                            class="size-4 shrink-0 text-zinc-900 dark:text-white"
                            aria-hidden="true"
                        />
                        <span v-if="isCurrentFinal()" class="sr-only"
                            >(escolhido)</span
                        >
                    </li>

                    <li
                        v-for="(customer, index) in visible"
                        :id="optionId(index + 1)"
                        :key="customer.public_id"
                        role="option"
                        :aria-selected="highlight === index + 1"
                        class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl px-3 py-2 hover:bg-zinc-900/[0.05] dark:hover:bg-white/5"
                        :class="
                            highlight === index + 1
                                ? 'bg-zinc-900/[0.06] dark:bg-white/10'
                                : ''
                        "
                        @click="chooseSaved(customer)"
                        @mousemove="highlight = index + 1"
                    >
                        <span class="min-w-0 flex-1">
                            <span
                                class="block text-sm font-medium [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                >{{ customer.name }}</span
                            >
                            <span
                                class="block font-mono numeric text-xs text-zinc-500 dark:text-zinc-400"
                                >{{ customer.tax_identification_number }}</span
                            >
                        </span>
                        <Check
                            v-if="isCurrentSaved(customer)"
                            class="size-4 shrink-0 text-zinc-900 dark:text-white"
                            aria-hidden="true"
                        />
                        <span v-if="isCurrentSaved(customer)" class="sr-only"
                            >(escolhido)</span
                        >
                    </li>
                </ul>

                <p
                    v-if="query.trim() !== '' && visible.length === 0"
                    class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                    role="status"
                >
                    Nenhum cliente guardado corresponde à procura.
                </p>
                <p
                    v-else-if="filtered.length > MAX_ROWS"
                    class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                >
                    A mostrar {{ MAX_ROWS }} de {{ filtered.length }}. Refine a
                    procura.
                </p>
            </div>

            <form
                id="pos-customer-typed"
                class="rounded-2xl bg-zinc-900/[0.04] p-4 sm:p-5 dark:bg-white/[0.04]"
                novalidate
                @submit.prevent="useTyped"
            >
                <h3 class="text-sm font-semibold text-zinc-950 dark:text-white">
                    Outro cliente
                </h3>
                <p class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">
                    Para quem quer o NIF no talão sem ficar guardado.
                </p>

                <div
                    class="mt-4 grid gap-4 sm:grid-cols-[minmax(0,1fr)_minmax(0,11rem)]"
                >
                    <div>
                        <label
                            for="pos-typed-name"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >Nome</label
                        >
                        <input
                            id="pos-typed-name"
                            v-model="typedName"
                            type="text"
                            autocomplete="off"
                            maxlength="200"
                            class="mt-2 block form-input"
                            :aria-invalid="
                                typedTried && nameProblem ? 'true' : undefined
                            "
                            :aria-describedby="
                                typedTried && nameProblem
                                    ? 'pos-typed-name-error'
                                    : undefined
                            "
                        />
                        <FormError
                            id="pos-typed-name-error"
                            :message="typedTried ? nameProblem : undefined"
                        />
                    </div>
                    <div>
                        <label
                            for="pos-typed-nif"
                            class="block text-sm font-medium text-zinc-900 dark:text-white"
                            >NIF</label
                        >
                        <input
                            id="pos-typed-nif"
                            v-model="typedNif"
                            type="text"
                            autocomplete="off"
                            autocapitalize="characters"
                            spellcheck="false"
                            maxlength="40"
                            class="mt-2 block form-input font-mono uppercase"
                            :aria-invalid="
                                typedTried && nifProblem ? 'true' : undefined
                            "
                            :aria-describedby="
                                typedTried && nifProblem
                                    ? 'pos-typed-nif-error'
                                    : undefined
                            "
                            @blur="uppercaseNif"
                        />
                        <FormError
                            id="pos-typed-nif-error"
                            :message="typedTried ? nifProblem : undefined"
                        />
                    </div>
                </div>

                <div class="mt-4 flex justify-end">
                    <button type="submit" :class="buttonOutline">
                        Usar estes dados
                    </button>
                </div>
            </form>
        </div>
    </RecordDialog>
</template>
