<script setup lang="ts" generic="T extends string | number">
import {
    Combobox,
    ComboboxButton,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
} from '@headlessui/vue';
import { Check, ChevronsUpDown } from '@lucide/vue';
import {
    computed,
    defineComponent,
    nextTick,
    onBeforeUnmount,
    ref,
    useAttrs,
    watch,
} from 'vue';
import type { SelectOption } from '@/types/select';

/**
 * Headless UI exposes `open` only through its default slot, so this renderless
 * child turns that slot value into an event the setup block can react to.
 */
const OpenWatcher = defineComponent({
    props: { open: { type: Boolean, required: true } },
    emits: ['change'],
    setup(watched, { emit }) {
        watch(
            () => watched.open,
            (value) => emit('change', value),
        );

        return () => null;
    },
});

/**
 * Attributes are routed on purpose. `class` and `style` describe the control as
 * a block in the page, so they go to the wrapper. Everything else (`autocomplete`,
 * `aria-*`, `data-*`, listeners…) describes the field, so it goes to the
 * combobox input, where assistive technology and form code look for it.
 */
defineOptions({ inheritAttrs: false });

const attrs = useAttrs();

function wrapperAttrs(): Record<string, unknown> {
    return { class: attrs.class, style: attrs.style };
}

function inputAttrs(): Record<string, unknown> {
    return Object.fromEntries(
        Object.entries(attrs).filter(
            ([key]) => key !== 'class' && key !== 'style',
        ),
    );
}

const props = withDefaults(
    defineProps<{
        modelValue: T;
        options: SelectOption<T>[];
        /**
         * Renders a hidden input so the value still posts with a DOM form
         * submission (Inertia's <Form> collects inputs by name).
         */
        name?: string;
        id?: string;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
        /** `sm` matches the compact controls used inside table rows. */
        size?: 'sm' | 'md';
        /** Marks the field as failing validation (`aria-invalid` and an outline). */
        invalid?: boolean;
        /** The id of the error or hint element that describes this field. */
        describedby?: string;
        required?: boolean;
    }>(),
    {
        name: undefined,
        id: undefined,
        placeholder: 'Seleccione uma opção…',
        ariaLabel: undefined,
        disabled: false,
        size: 'md',
        invalid: false,
        describedby: undefined,
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: T];
    change: [value: T];
}>();

const query = ref('');
const anchor = ref<HTMLElement | null>(null);

/** Fold case and diacritics so "orcamento" still matches "Orçamento". */
function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const filtered = computed(() => {
    const q = fold(query.value.trim());

    if (q === '') {
        return props.options;
    }

    return props.options.filter(
        (option) =>
            fold(option.label).includes(q) ||
            fold(option.hint ?? '').includes(q) ||
            fold(String(option.value)).includes(q),
    );
});

const selected = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);

function displayValue(): string {
    return selected.value?.label ?? '';
}

const inputClasses = computed(() =>
    props.size === 'sm'
        ? 'rounded-lg py-2 pr-8 pl-2.5 text-xs'
        : 'rounded-xl py-2.5 pr-9 pl-3 text-sm',
);

const buttonClasses = computed(() =>
    props.size === 'sm' ? 'pr-2 pl-2' : 'pr-2.5 pl-2',
);

/** The query is echoed back in the empty message, so keep it from taking over. */
const echoedQuery = computed(() => {
    const trimmed = query.value.trim();

    return trimmed.length > 40 ? `${trimmed.slice(0, 40)}…` : trimmed;
});

function update(value: T): void {
    emit('update:modelValue', value);
    emit('change', value);
}

/*
 * The options list is teleported to <body> and positioned with `fixed`.
 * Rendering it in place meant any ancestor with `overflow-hidden` — which is
 * most of the surface cards — clipped it, and any ancestor stacking context
 * could bury it. Teleporting removes both failure modes at once.
 */
const floating = ref({ top: '0px', left: '0px', width: '0px' });
const openUpwards = ref(false);

function reposition(): void {
    const el = anchor.value;

    if (!el) {
        return;
    }

    const rect = el.getBoundingClientRect();
    const maxHeight = 260;
    const spaceBelow = window.innerHeight - rect.bottom;

    openUpwards.value = spaceBelow < maxHeight && rect.top > spaceBelow;

    floating.value = {
        top: openUpwards.value ? `${rect.top - 6}px` : `${rect.bottom + 6}px`,
        left: `${rect.left}px`,
        width: `${rect.width}px`,
    };
}

function watchViewport(active: boolean): void {
    const method = active ? 'addEventListener' : 'removeEventListener';
    // `true` captures scrolls on inner containers, not just the window.
    window[method]('scroll', reposition, true);
    window[method]('resize', reposition);
}

function onOpenChange(open: boolean): void {
    if (open) {
        query.value = '';
        void nextTick(reposition);
        watchViewport(true);

        return;
    }

    watchViewport(false);
}

onBeforeUnmount(() => watchViewport(false));
</script>

<template>
    <Combobox
        :model-value="modelValue"
        :disabled="disabled"
        as="div"
        v-bind="wrapperAttrs()"
        @update:model-value="update"
    >
        <template #default="{ open }">
            <OpenWatcher :open="open" @change="onOpenChange" />

            <div ref="anchor" class="relative">
                <input
                    v-if="name"
                    type="hidden"
                    :name="name"
                    :value="modelValue"
                />

                <ComboboxInput
                    :id="id"
                    :aria-label="ariaLabel"
                    :display-value="displayValue"
                    :placeholder="placeholder"
                    :required="required || undefined"
                    :aria-invalid="invalid ? 'true' : undefined"
                    :aria-describedby="describedby"
                    autocomplete="off"
                    spellcheck="false"
                    autocapitalize="off"
                    v-bind="inputAttrs()"
                    :class="[
                        inputClasses,
                        'w-full bg-white text-zinc-900 outline-1 -outline-offset-1 outline-zinc-300 placeholder:text-zinc-400 focus:outline-2 focus:-outline-offset-2 focus:outline-brand-600 disabled:cursor-not-allowed disabled:opacity-60 aria-invalid:outline-2 aria-invalid:-outline-offset-2 aria-invalid:outline-rose-500 aria-invalid:focus:outline-rose-500 dark:bg-white/5 dark:text-white dark:outline-white/10 dark:placeholder:text-zinc-500 dark:focus:outline-brand-400 dark:aria-invalid:outline-rose-400 dark:aria-invalid:focus:outline-rose-400',
                    ]"
                    @change="query = $event.target.value"
                />

                <ComboboxButton
                    aria-label="Mostrar opções"
                    :class="[
                        buttonClasses,
                        'absolute inset-y-0 right-0 flex items-center rounded-r-xl focus:outline-hidden pointer-coarse:pl-3',
                    ]"
                >
                    <ChevronsUpDown
                        class="size-4 text-zinc-400"
                        aria-hidden="true"
                    />
                </ComboboxButton>
            </div>

            <Teleport to="body">
                <div
                    v-if="open"
                    :style="floating"
                    :class="[
                        openUpwards ? '-translate-y-full' : '',
                        'fixed z-[100]',
                    ]"
                >
                    <ComboboxOptions
                        static
                        class="max-h-64 w-full overflow-auto overscroll-contain rounded-xl bg-white p-1.5 text-sm shadow-xl ring-1 ring-zinc-900/10 focus:outline-hidden dark:bg-zinc-900 dark:ring-white/10"
                    >
                        <p
                            v-if="filtered.length === 0"
                            class="px-2.5 py-2 text-sm [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400"
                        >
                            Sem resultados para “{{ echoedQuery }}”.
                        </p>

                        <ComboboxOption
                            v-for="option in filtered"
                            v-slot="{ active, selected: isSelected }"
                            :key="option.value"
                            :value="option.value"
                            :disabled="option.disabled"
                            as="template"
                        >
                            <li
                                :class="[
                                    active ? 'bg-zinc-100 dark:bg-white/5' : '',
                                    option.disabled ? 'opacity-50' : '',
                                    'flex cursor-default items-start gap-2 rounded-lg px-2.5 py-2 text-zinc-900 select-none dark:text-white',
                                ]"
                            >
                                <span class="min-w-0 flex-1">
                                    <span
                                        :class="[
                                            isSelected ? 'font-semibold' : '',
                                            'block truncate',
                                        ]"
                                        >{{ option.label }}</span
                                    >
                                    <span
                                        v-if="option.hint"
                                        class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                        >{{ option.hint }}</span
                                    >
                                </span>
                                <Check
                                    v-if="isSelected"
                                    class="mt-0.5 size-4 shrink-0 text-brand-700 dark:text-brand-300"
                                    aria-hidden="true"
                                />
                            </li>
                        </ComboboxOption>
                    </ComboboxOptions>
                </div>
            </Teleport>
        </template>
    </Combobox>
</template>
