<script setup lang="ts" generic="T extends string | number">
import { Check, ChevronsUpDown } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useAttrs,
    useId,
    watch,
} from 'vue';
import { useFloatingPanel } from '@/lib/floating-panel';
import type { SelectOption } from '@/types/select';

/**
 * Attributes are routed on purpose. `class` and `style` describe the control as
 * a block in the page, so they go to the wrapper. Everything else (`id`-like
 * helpers, `aria-*`, `data-*`, listeners…) describes the field, so it goes to
 * the button, where assistive technology and form code look for it.
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

/** A list longer than this gets a filter field at its top. */
const FILTER_THRESHOLD = 8;
const TYPEAHEAD_RESET_MS = 700;

const uid = useId();
const listId = `select-${uid}-list`;

const open = ref(false);
const query = ref('');
const activeIndex = ref(-1);
const openedByPointer = ref(false);
const trigger = ref<HTMLButtonElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const list = ref<HTMLElement | null>(null);
const filterField = ref<HTMLInputElement | null>(null);
const filterFocused = ref(false);

let typeahead = '';
let typeaheadTimer: ReturnType<typeof setTimeout> | undefined;

/** Fold case and diacritics so "orcamento" still matches "Orçamento". */
function fold(value: string): string {
    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

const showFilter = computed(() => props.options.length > FILTER_THRESHOLD);

const visible = computed(() => {
    const needle = fold(query.value.trim());

    if (needle === '') {
        return props.options;
    }

    return props.options.filter(
        (option) =>
            fold(option.label).includes(needle) ||
            fold(option.hint ?? '').includes(needle) ||
            fold(String(option.value)).includes(needle),
    );
});

const selected = computed(() =>
    props.options.find((option) => option.value === props.modelValue),
);

function optionId(index: number): string {
    return `${listId}-option-${index}`;
}

const activeId = computed(() =>
    open.value && activeIndex.value >= 0
        ? optionId(activeIndex.value)
        : undefined,
);

const { style: floatingStyle } = useFloatingPanel({
    anchor: trigger,
    panel,
    open,
    minWidth: 176,
    estimatedHeight: Math.min(
        props.options.length * 44 + 12 + (showFilter.value ? 56 : 0),
        340,
    ),
});

function isCoarsePointer(): boolean {
    return window.matchMedia('(pointer: coarse)').matches;
}

function enabled(index: number): boolean {
    const option = visible.value[index];

    return option !== undefined && !option.disabled;
}

/** The next enabled option from `from` in `step` direction, or -1. */
function seek(from: number, step: 1 | -1): number {
    for (
        let index = from;
        index >= 0 && index < visible.value.length;
        index += step
    ) {
        if (enabled(index)) {
            return index;
        }
    }

    return -1;
}

function setActive(index: number): void {
    if (index < 0) {
        return;
    }

    activeIndex.value = index;
    void nextTick(() =>
        document
            .getElementById(optionId(index))
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function move(step: 1 | -1, size = 1): void {
    const count = visible.value.length;

    if (count === 0) {
        return;
    }

    const target = Math.min(
        Math.max(activeIndex.value + step * size, 0),
        count - 1,
    );
    // The nearest enabled row in the direction of travel, else the other way.
    const index =
        seek(target, step) >= 0
            ? seek(target, step)
            : seek(target, -step as 1 | -1);

    if (index >= 0) {
        setActive(index);
    }
}

function update(value: T): void {
    emit('update:modelValue', value);
    emit('change', value);
}

function close(returnFocus: boolean): void {
    if (!open.value) {
        return;
    }

    open.value = false;
    filterFocused.value = false;
    typeahead = '';

    if (returnFocus) {
        void nextTick(() => trigger.value?.focus({ preventScroll: true }));
    }
}

function openList(
    byPointer: boolean,
    start: 'selected' | 'first' | 'last' = 'selected',
): void {
    if (props.disabled || open.value) {
        return;
    }

    query.value = '';
    openedByPointer.value = byPointer;
    open.value = true;

    const selectedIndex = props.options.findIndex(
        (option) => option.value === props.modelValue,
    );

    activeIndex.value =
        start === 'last'
            ? seek(props.options.length - 1, -1)
            : start === 'selected' && selectedIndex >= 0
              ? selectedIndex
              : seek(0, 1);

    void nextTick(() => {
        if (activeIndex.value >= 0) {
            document
                .getElementById(optionId(activeIndex.value))
                ?.scrollIntoView({ block: 'nearest' });
        }

        // A phone's keyboard would cover the list it was opened for, so the
        // filter is only focused for a keyboard or a mouse.
        if (showFilter.value && (!byPointer || !isCoarsePointer())) {
            filterField.value?.focus({ preventScroll: true });
        }
    });
}

function choose(index: number): void {
    const option = visible.value[index];

    if (!option || option.disabled) {
        return;
    }

    update(option.value);
    close(true);
}

/** Jump to the next option whose label starts with what has been typed. */
function runTypeahead(character: string): void {
    typeahead += fold(character);
    clearTimeout(typeaheadTimer);
    typeaheadTimer = setTimeout(() => (typeahead = ''), TYPEAHEAD_RESET_MS);

    const count = visible.value.length;
    // One repeated letter cycles through the matches; a word keeps its match.
    const from =
        typeahead.length === 1 ? activeIndex.value + 1 : activeIndex.value;

    for (let offset = 0; offset < count; offset += 1) {
        const index = (Math.max(from, 0) + offset) % count;

        if (
            enabled(index) &&
            fold(visible.value[index].label).startsWith(typeahead)
        ) {
            setActive(index);

            return;
        }
    }
}

function isPrintable(event: KeyboardEvent): boolean {
    return (
        event.key.length === 1 &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey
    );
}

function onTriggerKeydown(event: KeyboardEvent): void {
    if (props.disabled) {
        return;
    }

    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp':
            event.preventDefault();

            if (!open.value) {
                openList(false, event.key === 'ArrowUp' ? 'last' : 'selected');
            } else {
                move(event.key === 'ArrowDown' ? 1 : -1);
            }

            return;
        case 'Enter':
        case ' ':
            if (open.value && event.key === ' ' && typeahead !== '') {
                event.preventDefault();
                runTypeahead(' ');

                return;
            }

            event.preventDefault();

            if (open.value) {
                choose(activeIndex.value);
            } else {
                openList(false);
            }

            return;
        case 'Home':
        case 'End':
            if (open.value) {
                event.preventDefault();
                setActive(
                    event.key === 'Home'
                        ? seek(0, 1)
                        : seek(visible.value.length - 1, -1),
                );
            }

            return;
        case 'PageDown':
        case 'PageUp':
            if (open.value) {
                event.preventDefault();
                move(event.key === 'PageDown' ? 1 : -1, 8);
            }

            return;
        case 'Escape':
            if (open.value) {
                // The dialog the field may sit in must not close with it.
                event.preventDefault();
                close(true);
            }

            return;
        case 'Tab':
            close(false);

            return;
    }

    if (!isPrintable(event)) {
        return;
    }

    if (!open.value && showFilter.value) {
        event.preventDefault();
        openList(false);
        query.value = event.key;
        void nextTick(() => filterField.value?.focus({ preventScroll: true }));

        return;
    }

    event.preventDefault();

    if (!open.value) {
        openList(false);
    }

    runTypeahead(event.key);
}

/**
 * A mouse or a finger toggles the list. A click with no pointer behind it
 * (`detail` is 0: a screen reader's, or voice control's) is a keyboard-style
 * opening, so it gets no entrance animation.
 */
function onTriggerClick(event: MouseEvent): void {
    if (open.value) {
        close(true);

        return;
    }

    openList(event.detail !== 0);
}

function onFilterKeydown(event: KeyboardEvent): void {
    switch (event.key) {
        case 'ArrowDown':
        case 'ArrowUp':
            event.preventDefault();
            move(event.key === 'ArrowDown' ? 1 : -1);

            return;
        case 'PageDown':
        case 'PageUp':
            event.preventDefault();
            move(event.key === 'PageDown' ? 1 : -1, 8);

            return;
        case 'Enter':
            event.preventDefault();
            choose(activeIndex.value);

            return;
        case 'Escape':
            event.preventDefault();
            close(true);

            return;
        case 'Tab':
            // Back to the button first, so Tab carries on from there.
            close(true);
            trigger.value?.focus({ preventScroll: true });
    }
}

function onFilterInput(): void {
    // The first match is the one Enter takes.
    activeIndex.value = seek(0, 1);
}

function onPointerDownOutside(event: PointerEvent): void {
    const target = event.target;

    if (
        !(target instanceof Node) ||
        trigger.value?.contains(target) ||
        panel.value?.contains(target)
    ) {
        return;
    }

    close(false);
}

watch(open, (isOpen) => {
    if (isOpen) {
        document.addEventListener('pointerdown', onPointerDownOutside, true);

        return;
    }

    document.removeEventListener('pointerdown', onPointerDownOutside, true);
});

watch(
    () => props.disabled,
    (disabled) => {
        if (disabled) {
            close(false);
        }
    },
);

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onPointerDownOutside, true);
    clearTimeout(typeaheadTimer);
});

const triggerClasses = computed(() =>
    props.size === 'sm' ? 'form-input form-input-sm' : 'form-input',
);
</script>

<template>
    <div v-bind="wrapperAttrs()">
        <input v-if="name" type="hidden" :name="name" :value="modelValue" />

        <button
            :id="id"
            ref="trigger"
            type="button"
            role="combobox"
            aria-haspopup="listbox"
            :aria-label="ariaLabel"
            :aria-expanded="open"
            :aria-controls="open ? listId : undefined"
            :aria-activedescendant="filterFocused ? undefined : activeId"
            :aria-required="required || undefined"
            :aria-invalid="invalid ? 'true' : undefined"
            :aria-describedby="describedby"
            :disabled="disabled"
            v-bind="inputAttrs()"
            :class="[
                triggerClasses,
                'flex cursor-pointer items-center gap-2 text-start',
            ]"
            @click="onTriggerClick"
            @keydown="onTriggerKeydown"
            @keyup.space.prevent
        >
            <span
                :class="[
                    'min-w-0 flex-1 truncate',
                    selected ? '' : 'text-zinc-500 dark:text-zinc-400',
                ]"
                >{{ selected ? selected.label : placeholder }}</span
            >
            <ChevronsUpDown
                class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
                aria-hidden="true"
            />
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                :style="floatingStyle"
                :data-menu-motion="openedByPointer ? 'true' : undefined"
                class="z-[100] flex flex-col overflow-clip menu-panel text-sm"
            >
                <div v-if="showFilter" class="shrink-0 p-1.5 pb-0">
                    <input
                        ref="filterField"
                        v-model="query"
                        type="text"
                        role="combobox"
                        aria-label="Filtrar opções"
                        aria-autocomplete="list"
                        aria-expanded="true"
                        :aria-controls="listId"
                        :aria-activedescendant="activeId"
                        placeholder="Filtrar…"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        enterkeyhint="done"
                        class="form-input"
                        @focus="filterFocused = true"
                        @blur="filterFocused = false"
                        @input="onFilterInput"
                        @keydown="onFilterKeydown"
                    />
                </div>

                <ul
                    v-show="visible.length > 0"
                    :id="listId"
                    ref="list"
                    role="listbox"
                    :aria-label="ariaLabel ?? 'Opções'"
                    class="max-h-[min(20rem,50dvh)] min-h-0 flex-1 overflow-y-auto overscroll-contain p-1.5"
                    @mousedown.prevent
                >
                    <li
                        v-for="(option, index) in visible"
                        :id="optionId(index)"
                        :key="option.value"
                        role="option"
                        :aria-selected="option.value === modelValue"
                        :aria-disabled="option.disabled ? 'true' : undefined"
                        :class="[
                            'flex min-h-10 cursor-default items-start gap-2 menu-item py-2 text-zinc-900 select-none dark:text-white pointer-coarse:min-h-11',
                            index === activeIndex
                                ? 'bg-zinc-900/[0.06] dark:bg-white/10'
                                : '',
                            option.disabled ? 'opacity-50' : '',
                        ]"
                        @click="choose(index)"
                        @pointermove="
                            enabled(index) && activeIndex !== index
                                ? (activeIndex = index)
                                : undefined
                        "
                    >
                        <span class="min-w-0 flex-1 [overflow-wrap:anywhere]">
                            <span
                                :class="[
                                    'block',
                                    option.value === modelValue
                                        ? 'font-semibold'
                                        : '',
                                ]"
                                >{{ option.label }}</span
                            >
                            <span
                                v-if="option.hint"
                                class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400"
                                >{{ option.hint }}</span
                            >
                        </span>
                        <Check
                            v-if="option.value === modelValue"
                            class="mt-0.5 size-4 shrink-0 text-brand-700 dark:text-brand-300"
                            aria-hidden="true"
                        />
                    </li>
                </ul>

                <p
                    v-if="visible.length === 0"
                    role="status"
                    class="px-3 py-3 text-sm text-zinc-500 dark:text-zinc-400"
                >
                    Nenhum resultado
                </p>
            </div>
        </Teleport>
    </div>
</template>
