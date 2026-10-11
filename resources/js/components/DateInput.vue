<script setup lang="ts">
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    Clock,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useId,
    watch,
} from 'vue';
import {
    MONTH_NAMES,
    WEEKDAY_NAMES,
    addDaysToIsoDate,
    addMonthsToIsoDate,
    clampIsoDate,
    formatDisplayValue,
    formatModelValue,
    formatMonthYear,
    isoDateParts,
    localClockTime,
    localIsoDate,
    makeIsoDate,
    maskTypedValue,
    monthGrid,
    parseModelValue,
    parseTypedValue,
    spokenIsoDate,
    stepHours,
    stepMinutes,
    weekdayOfIsoDate,
} from '@/lib/dates';
import type { DateInputMode, PickerValue } from '@/lib/dates';
import { useFloatingPanel } from '@/lib/floating-panel';

/**
 * A date, a date with a time, or a time, typed or picked.
 *
 * `mode="date"` holds `yyyy-MM-dd`, `datetime` holds `yyyy-MM-ddTHH:mm` and
 * `time` holds `HH:mm` (24-hour). They are wall-clock text: nothing is ever
 * converted through UTC, so a day never moves. The older `withTime` flag keeps
 * its `yyyy-MM-dd HH:mm` value (a space, not a `T`) for the screen that sends it.
 *
 * Typing always works: `dd/MM/yyyy` (the slashes are added as the digits go in),
 * and a field that cannot be read is marked invalid and cleared, never left
 * holding yesterday's value. The popup is a calendar with a month and a year to
 * choose, «Hoje» and, when the field is optional, «Limpar». It is a panel under
 * the field, and a sheet at the foot of a phone's screen.
 */
const props = withDefaults(
    defineProps<{
        /** `yyyy-MM-dd`, `yyyy-MM-ddTHH:mm` or `HH:mm`, by `mode`; null when empty. */
        modelValue: string | null;
        id?: string;
        placeholder?: string;
        ariaLabel?: string;
        disabled?: boolean;
        clearable?: boolean;
        mode?: DateInputMode;
        /** Same as `mode="datetime"`, with the older space-separated value. */
        withTime?: boolean;
        minDate?: string;
        maxDate?: string;
        /** Marks the field as failing validation (`aria-invalid` and an outline). */
        invalid?: boolean;
        /** The id of the error or hint element that describes this field. */
        describedby?: string;
        required?: boolean;
    }>(),
    {
        id: undefined,
        placeholder: undefined,
        ariaLabel: undefined,
        disabled: false,
        clearable: true,
        mode: 'date',
        withTime: false,
        minDate: undefined,
        maxDate: undefined,
        invalid: false,
        describedby: undefined,
        required: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string | null];
}>();

const WEEKDAY_LONG = [
    'domingo',
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
];

const uid = useId();
const panelId = `date-${uid}-panel`;
const titleId = `date-${uid}-title`;

const effectiveMode = computed<DateInputMode>(() =>
    props.mode !== 'date' ? props.mode : props.withTime ? 'datetime' : 'date',
);
const separator = computed<'T' | ' '>(() =>
    props.withTime && props.mode === 'date' ? ' ' : 'T',
);
const hasDay = computed(() => effectiveMode.value !== 'time');
const hasTime = computed(() => effectiveMode.value !== 'date');

const placeholderText = computed(
    () =>
        props.placeholder ??
        (effectiveMode.value === 'time'
            ? 'hh:mm'
            : effectiveMode.value === 'datetime'
              ? 'dd/mm/aaaa hh:mm'
              : 'dd/mm/aaaa'),
);

const noun = computed(() => (effectiveMode.value === 'time' ? 'hora' : 'data'));

const minIso = computed(
    () => parseModelValue(props.minDate, 'date')?.date ?? null,
);
const maxIso = computed(
    () => parseModelValue(props.maxDate, 'date')?.date ?? null,
);

const current = computed(() =>
    parseModelValue(props.modelValue, effectiveMode.value),
);
const selectedDay = computed(() => current.value?.date ?? null);

const text = ref('');
const editing = ref(false);
const typedInvalid = ref(false);
const open = ref(false);
const useSheet = ref(false);
const typingOnTouch = ref(false);
/** A phone: the popup is a sheet, so the field keeps its keyboard away. */
const touchSheet = ref(false);
const view = ref<'days' | 'months' | 'years'>('days');
const viewYear = ref(2026);
const viewMonth = ref(1);
const cursor = ref<string>('');
const yearPageStart = ref(0);
const hours = ref(0);
const minutes = ref(0);

const wrapper = ref<HTMLElement | null>(null);
const field = ref<HTMLInputElement | null>(null);
const panel = ref<HTMLElement | null>(null);

const floatingOpen = computed(() => open.value && !useSheet.value);
const { style: floatingStyle } = useFloatingPanel({
    anchor: wrapper,
    panel,
    open: floatingOpen,
    minWidth: 304,
    estimatedHeight: 420,
    width: 'content',
});

const showInvalid = computed(() => props.invalid || typedInvalid.value);
const grid = computed(() => monthGrid(viewYear.value, viewMonth.value));
const today = computed(() => localIsoDate());
const monthYear = computed(() =>
    formatMonthYear(viewYear.value, viewMonth.value),
);

const yearChoices = computed(() =>
    Array.from({ length: 12 }, (_, index) => yearPageStart.value + index),
);

const canClear = computed(
    () =>
        props.clearable &&
        !props.disabled &&
        (text.value !== '' || current.value !== null),
);

const hasValue = computed(() => current.value !== null);

function isDayDisabled(iso: string): boolean {
    return (
        (minIso.value !== null && iso < minIso.value) ||
        (maxIso.value !== null && iso > maxIso.value)
    );
}

function inRange(value: PickerValue): boolean {
    return value.date === null || !isDayDisabled(value.date);
}

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function currentTime(): string {
    return `${pad(hours.value)}:${pad(minutes.value)}`;
}

function commit(value: PickerValue | null): void {
    const next = formatModelValue(value, effectiveMode.value, separator.value);

    if (next !== props.modelValue) {
        emit('update:modelValue', next);
    }
}

function syncTimeFrom(value: PickerValue | null): void {
    const parts = value?.time?.split(':');

    if (parts?.length === 2) {
        hours.value = Number(parts[0]);
        minutes.value = Number(parts[1]);
    }
}

function showMonthOf(iso: string): void {
    const parts = isoDateParts(iso);

    if (parts === null) {
        return;
    }

    viewYear.value = parts.year;
    viewMonth.value = parts.month;
}

function resetView(): void {
    const start = clampIsoDate(
        selectedDay.value ?? today.value,
        minIso.value,
        maxIso.value,
    );

    view.value = 'days';
    cursor.value = start;
    showMonthOf(start);
}

// The text mirrors the value, except while someone is typing in the field.
watch(
    () => [props.modelValue, effectiveMode.value] as const,
    () => {
        syncTimeFrom(current.value);

        // A value cleared because the typed text could not be read must not wipe
        // that text; any other change is the page's and wins.
        if (
            !editing.value &&
            !(typedInvalid.value && props.modelValue === null)
        ) {
            text.value = formatDisplayValue(current.value, effectiveMode.value);
            typedInvalid.value = false;
        }
    },
    { immediate: true },
);

function isCoarsePhone(): boolean {
    return window.matchMedia('(pointer: coarse) and (max-width: 40rem)')
        .matches;
}

function openPanel(): void {
    if (props.disabled || open.value) {
        return;
    }

    useSheet.value = isCoarsePhone();
    syncTimeFrom(current.value);
    resetView();
    open.value = true;
}

function closePanel(returnFocus: boolean): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (returnFocus) {
        void nextTick(() => field.value?.focus({ preventScroll: true }));
    }
}

function focusCursor(): void {
    void nextTick(() => {
        const target =
            panel.value?.querySelector<HTMLElement>(
                `[data-iso="${cursor.value}"]`,
            ) ??
            panel.value?.querySelector<HTMLElement>('[data-time-field]') ??
            panel.value?.querySelector<HTMLElement>('button');

        target?.focus({ preventScroll: true });
    });
}

function moveCursor(iso: string | null): void {
    if (iso === null) {
        return;
    }

    cursor.value = clampIsoDate(iso, minIso.value, maxIso.value);

    const parts = isoDateParts(cursor.value);

    if (
        parts !== null &&
        (parts.year !== viewYear.value || parts.month !== viewMonth.value)
    ) {
        showMonthOf(cursor.value);
    }

    focusCursor();
}

function shiftMonth(step: 1 | -1): void {
    const moved = addMonthsToIsoDate(
        makeIsoDate(viewYear.value, viewMonth.value, 1) ?? today.value,
        step,
    );

    if (moved === null) {
        return;
    }

    showMonthOf(moved);

    const day = isoDateParts(cursor.value)?.day ?? 1;
    const target = addMonthsToIsoDate(
        makeIsoDate(viewYear.value, viewMonth.value, 1) ?? moved,
        0,
    );

    cursor.value = clampIsoDate(
        makeIsoDate(viewYear.value, viewMonth.value, Math.min(day, 28)) ??
            target ??
            moved,
        minIso.value,
        maxIso.value,
    );
}

function shiftHeader(step: 1 | -1): void {
    if (view.value === 'days') {
        shiftMonth(step);
    } else if (view.value === 'months') {
        viewYear.value += step;
    } else {
        yearPageStart.value += step * 12;
    }
}

const headerLabels = computed(() =>
    view.value === 'days'
        ? { previous: 'Mês anterior', next: 'Mês seguinte' }
        : view.value === 'months'
          ? { previous: 'Ano anterior', next: 'Ano seguinte' }
          : { previous: 'Anos anteriores', next: 'Anos seguintes' },
);

function showChoice(next: 'months' | 'years'): void {
    if (view.value === next) {
        view.value = 'days';

        return;
    }

    if (next === 'years') {
        yearPageStart.value = viewYear.value - (viewYear.value % 12);
    }

    view.value = next;
    void nextTick(() =>
        panel.value
            ?.querySelector<HTMLElement>(
                '[data-choice][data-selected="true"], [data-choice]',
            )
            ?.focus({ preventScroll: true }),
    );
}

function chooseMonth(month: number): void {
    viewMonth.value = month;
    view.value = 'days';
    cursor.value = clampIsoDate(
        makeIsoDate(
            viewYear.value,
            month,
            Math.min(isoDateParts(cursor.value)?.day ?? 1, 28),
        ) ?? cursor.value,
        minIso.value,
        maxIso.value,
    );
    focusCursor();
}

function chooseYear(year: number): void {
    viewYear.value = year;
    view.value = 'months';
    void nextTick(() =>
        panel.value
            ?.querySelector<HTMLElement>(
                '[data-choice][data-selected="true"], [data-choice]',
            )
            ?.focus({ preventScroll: true }),
    );
}

function pickDay(iso: string): void {
    if (isDayDisabled(iso)) {
        return;
    }

    const value: PickerValue = {
        date: iso,
        time: hasTime.value ? currentTime() : null,
    };

    commit(value);
    editing.value = false;
    typedInvalid.value = false;
    text.value = formatDisplayValue(value, effectiveMode.value);
    cursor.value = iso;
    showMonthOf(iso);

    if (!hasTime.value) {
        closePanel(true);
    }
}

function pickToday(): void {
    if (effectiveMode.value === 'time') {
        const now = localClockTime();

        hours.value = Number(now.slice(0, 2));
        minutes.value = Number(now.slice(3, 5));
        commitTime();

        return;
    }

    const iso = today.value;

    if (isDayDisabled(iso)) {
        return;
    }

    pickDay(iso);
    moveCursor(iso);
}

function clearValue(): void {
    commit(null);
    editing.value = false;
    typedInvalid.value = false;
    text.value = '';
    closePanel(true);
    void nextTick(() => field.value?.focus({ preventScroll: true }));
}

function commitTime(): void {
    const time = currentTime();

    if (effectiveMode.value === 'time') {
        commit({ date: null, time });
        editing.value = false;
        text.value = time;

        return;
    }

    // A time with no day waits for the day; it is kept in the steppers.
    if (selectedDay.value !== null) {
        const value = { date: selectedDay.value, time };

        commit(value);
        editing.value = false;
        text.value = formatDisplayValue(value, effectiveMode.value);
    }
}

function stepTime(
    unit: 'hours' | 'minutes',
    direction: 1 | -1,
    fine = false,
): void {
    if (unit === 'hours') {
        hours.value = stepHours(hours.value, direction);
    } else {
        minutes.value = fine
            ? (minutes.value + direction + 60) % 60
            : stepMinutes(minutes.value, direction);
    }

    commitTime();
}

function onTimeFieldInput(unit: 'hours' | 'minutes', event: Event): void {
    const target = event.target as HTMLInputElement;
    const digits = target.value.replace(/\D/g, '').slice(0, 2);

    target.value = digits;

    if (digits === '') {
        return;
    }

    const limit = unit === 'hours' ? 23 : 59;
    const parsed = Math.min(Number(digits), limit);

    if (unit === 'hours') {
        hours.value = parsed;
    } else {
        minutes.value = parsed;
    }

    commitTime();
}

function onTimeFieldBlur(event: Event): void {
    // Show the padded value the model holds, whatever was typed.
    (event.target as HTMLInputElement).value = pad(
        (event.target as HTMLInputElement).dataset.timeField === 'hours'
            ? hours.value
            : minutes.value,
    );
}

function onTimeFieldKeydown(
    unit: 'hours' | 'minutes',
    event: KeyboardEvent,
): void {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
        event.preventDefault();
        stepTime(unit, event.key === 'ArrowUp' ? 1 : -1, event.shiftKey);
        (event.target as HTMLInputElement).value = pad(
            unit === 'hours' ? hours.value : minutes.value,
        );
    }
}

function onCellKeydown(event: KeyboardEvent, iso: string): void {
    let target: string | null = null;

    switch (event.key) {
        case 'ArrowLeft':
            target = addDaysToIsoDate(iso, -1);
            break;
        case 'ArrowRight':
            target = addDaysToIsoDate(iso, 1);
            break;
        case 'ArrowUp':
            target = addDaysToIsoDate(iso, -7);
            break;
        case 'ArrowDown':
            target = addDaysToIsoDate(iso, 7);
            break;
        case 'Home':
            target = addDaysToIsoDate(iso, -weekdayOfIsoDate(iso));
            break;
        case 'End':
            target = addDaysToIsoDate(iso, 6 - weekdayOfIsoDate(iso));
            break;
        case 'PageUp':
            target = addMonthsToIsoDate(iso, event.shiftKey ? -12 : -1);
            break;
        case 'PageDown':
            target = addMonthsToIsoDate(iso, event.shiftKey ? 12 : 1);
            break;
        default:
            return;
    }

    event.preventDefault();
    moveCursor(target);
}

function focusableInPanel(): HTMLElement[] {
    return Array.from(
        panel.value?.querySelectorAll<HTMLElement>(
            'button:not([disabled]):not([tabindex="-1"]), input:not([disabled])',
        ) ?? [],
    );
}

function onPanelKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        // The dialog the field may sit in must not close with it.
        event.preventDefault();

        if (view.value !== 'days') {
            view.value = 'days';
            focusCursor();

            return;
        }

        closePanel(true);

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = focusableInPanel();
    const first = items[0];
    const last = items[items.length - 1];

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        closePanel(true);
    } else if (!event.shiftKey && document.activeElement === last) {
        // Back to the field first, so Tab carries on from there.
        closePanel(true);
        field.value?.focus({ preventScroll: true });
    }
}

function finalizeText(): void {
    editing.value = false;

    const raw = text.value.trim();

    if (raw === '') {
        typedInvalid.value = false;

        return;
    }

    const parsed = parseTypedValue(raw, effectiveMode.value);

    if (parsed !== null && inRange(parsed)) {
        typedInvalid.value = false;
        commit(parsed);
        text.value = formatDisplayValue(parsed, effectiveMode.value);

        return;
    }

    typedInvalid.value = true;
    commit(null);
}

function onInput(event: Event): void {
    const target = event.target as HTMLInputElement;
    const typed =
        (event as InputEvent).inputType?.startsWith('insert') === true
            ? maskTypedValue(target.value, text.value, effectiveMode.value)
            : target.value;

    if (typed !== target.value) {
        target.value = typed;
    }

    text.value = typed;
    editing.value = true;
    typedInvalid.value = false;

    if (typed.trim() === '') {
        commit(null);

        return;
    }

    const parsed = parseTypedValue(typed, effectiveMode.value);

    if (parsed !== null && inRange(parsed)) {
        commit(parsed);
        syncTimeFrom(parsed);

        if (parsed.date !== null) {
            cursor.value = parsed.date;
            showMonthOf(parsed.date);
        }
    }
}

function onBlur(event: FocusEvent): void {
    typingOnTouch.value = false;

    if (
        event.relatedTarget instanceof Node &&
        panel.value?.contains(event.relatedTarget)
    ) {
        return;
    }

    if (editing.value) {
        finalizeText();
    }
}

function onFieldKeydown(event: KeyboardEvent): void {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        openPanel();
        focusCursor();

        return;
    }

    if (event.key === 'Escape') {
        if (open.value) {
            event.preventDefault();
            closePanel(false);
        } else if (editing.value) {
            // Put back what the field held before it was typed over.
            event.preventDefault();
            editing.value = false;
            typedInvalid.value = false;
            text.value = formatDisplayValue(current.value, effectiveMode.value);
        }

        return;
    }

    if (event.key === 'Enter') {
        finalizeText();

        if (open.value) {
            // The first Enter confirms the popup; it does not send the form.
            event.preventDefault();
            closePanel(false);
        }
    }
}

function onFieldClick(): void {
    openPanel();
}

function startTyping(): void {
    typingOnTouch.value = true;
    closePanel(false);
    void nextTick(() => {
        field.value?.blur();
        field.value?.focus();
    });
}

function onPointerDownOutside(event: PointerEvent): void {
    const target = event.target;

    if (
        !(target instanceof Node) ||
        wrapper.value?.contains(target) ||
        panel.value?.contains(target)
    ) {
        return;
    }

    if (editing.value) {
        finalizeText();
    }

    closePanel(false);
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
            closePanel(false);
        }
    },
);

onMounted(() => {
    touchSheet.value = isCoarsePhone();
});

onBeforeUnmount(() =>
    document.removeEventListener('pointerdown', onPointerDownOutside, true),
);

const todayLabel = computed(() =>
    effectiveMode.value === 'time' ? 'Agora' : 'Hoje',
);
const todayDisabled = computed(
    () => effectiveMode.value !== 'time' && isDayDisabled(today.value),
);
const clearLabel = computed(() => `Limpar ${noun.value}`);
const sheetMode = computed(() => open.value && useSheet.value);
</script>

<template>
    <div ref="wrapper" class="field-adorned">
        <span class="field-adornment-start" aria-hidden="true">
            <Clock v-if="effectiveMode === 'time'" class="size-4" />
            <CalendarDays v-else class="size-4" />
        </span>

        <input
            :id="id"
            ref="field"
            :value="text"
            type="text"
            role="combobox"
            aria-haspopup="dialog"
            :aria-expanded="open"
            :aria-controls="open ? panelId : undefined"
            :aria-label="ariaLabel"
            :aria-invalid="showInvalid ? 'true' : undefined"
            :aria-describedby="describedby"
            :placeholder="placeholderText"
            :disabled="disabled"
            :required="required || undefined"
            :inputmode="touchSheet && !typingOnTouch ? 'none' : 'numeric'"
            :maxlength="effectiveMode === 'datetime' ? 16 : 10"
            autocomplete="off"
            autocapitalize="off"
            spellcheck="false"
            :class="['form-input', clearable ? 'pe-10' : '']"
            @input="onInput"
            @blur="onBlur"
            @click="onFieldClick"
            @keydown="onFieldKeydown"
        />

        <button
            v-if="canClear"
            type="button"
            :aria-label="clearLabel"
            class="absolute inset-y-0 inset-e-0 icon-button rounded-full text-zinc-500 focus-ring transition hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
            @click="clearValue"
        >
            <X class="size-4" aria-hidden="true" />
        </button>

        <Teleport to="body">
            <template v-if="open">
                <div
                    v-if="sheetMode"
                    class="fixed inset-0 z-[99] dialog-scrim"
                    aria-hidden="true"
                />

                <div
                    :id="panelId"
                    ref="panel"
                    role="dialog"
                    :aria-modal="sheetMode ? 'true' : undefined"
                    :aria-label="
                        effectiveMode === 'time'
                            ? 'Escolher hora'
                            : 'Escolher data'
                    "
                    :style="sheetMode ? undefined : floatingStyle"
                    :class="[
                        'date-panel z-[100] menu-panel',
                        sheetMode
                            ? 'date-sheet fixed inset-s-0 inset-e-0 inset-be-0'
                            : '',
                    ]"
                    @keydown="onPanelKeydown"
                >
                    <template v-if="hasDay">
                        <div class="date-head">
                            <button
                                type="button"
                                :aria-label="headerLabels.previous"
                                class="icon-button shrink-0 rounded-full text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                                @click="shiftHeader(-1)"
                            >
                                <ChevronLeft
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </button>

                            <div
                                :id="titleId"
                                class="date-title"
                                aria-live="polite"
                                aria-atomic="true"
                            >
                                <button
                                    type="button"
                                    class="date-title-button focus-ring"
                                    :aria-label="`Escolher mês, ${MONTH_NAMES[viewMonth - 1]}`"
                                    :aria-expanded="view === 'months'"
                                    @click="showChoice('months')"
                                >
                                    {{ MONTH_NAMES[viewMonth - 1] }}
                                </button>
                                <button
                                    type="button"
                                    class="date-title-button focus-ring"
                                    :aria-label="`Escolher ano, ${viewYear}`"
                                    :aria-expanded="view === 'years'"
                                    @click="showChoice('years')"
                                >
                                    {{ viewYear }}
                                </button>
                                <span class="sr-only">{{ monthYear }}</span>
                            </div>

                            <button
                                type="button"
                                :aria-label="headerLabels.next"
                                class="icon-button shrink-0 rounded-full text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                                @click="shiftHeader(1)"
                            >
                                <ChevronRight
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </button>
                        </div>

                        <div
                            v-if="view === 'days'"
                            role="grid"
                            :aria-labelledby="titleId"
                        >
                            <div role="row" class="date-weekdays eyebrow">
                                <span
                                    v-for="(name, index) in WEEKDAY_NAMES"
                                    :key="name"
                                    role="columnheader"
                                    :aria-label="WEEKDAY_LONG[index]"
                                    >{{ name }}</span
                                >
                            </div>
                            <div
                                v-for="(week, weekIndex) in grid"
                                :key="weekIndex"
                                role="row"
                                class="date-grid"
                            >
                                <div
                                    v-for="day in week"
                                    :key="day.iso"
                                    role="gridcell"
                                    :aria-selected="day.iso === selectedDay"
                                >
                                    <button
                                        type="button"
                                        class="date-cell"
                                        :data-iso="day.iso"
                                        :data-today="
                                            day.iso === today
                                                ? 'true'
                                                : undefined
                                        "
                                        :data-outside="
                                            day.inMonth ? undefined : 'true'
                                        "
                                        :data-selected="
                                            day.iso === selectedDay
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-disabled="
                                            isDayDisabled(day.iso)
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-current="
                                            day.iso === today
                                                ? 'date'
                                                : undefined
                                        "
                                        :aria-label="spokenIsoDate(day.iso)"
                                        :tabindex="day.iso === cursor ? 0 : -1"
                                        @click="pickDay(day.iso)"
                                        @keydown="
                                            onCellKeydown($event, day.iso)
                                        "
                                    >
                                        {{ day.day }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div
                            v-else-if="view === 'months'"
                            class="date-choice-grid"
                        >
                            <button
                                v-for="(name, index) in MONTH_NAMES"
                                :key="name"
                                type="button"
                                class="date-choice"
                                data-choice
                                :data-selected="
                                    index + 1 === viewMonth ? 'true' : undefined
                                "
                                :aria-pressed="index + 1 === viewMonth"
                                @click="chooseMonth(index + 1)"
                            >
                                {{ name }}
                            </button>
                        </div>

                        <div v-else class="date-choice-grid">
                            <button
                                v-for="year in yearChoices"
                                :key="year"
                                type="button"
                                class="date-choice"
                                data-choice
                                :data-selected="
                                    year === viewYear ? 'true' : undefined
                                "
                                :data-today="
                                    year === Number(today.slice(0, 4))
                                        ? 'true'
                                        : undefined
                                "
                                :aria-pressed="year === viewYear"
                                @click="chooseYear(year)"
                            >
                                {{ year }}
                            </button>
                        </div>
                    </template>

                    <div
                        v-if="hasTime && (view === 'days' || !hasDay)"
                        class="date-time"
                    >
                        <div
                            v-for="unit in ['hours', 'minutes'] as const"
                            :key="unit"
                            class="contents"
                        >
                            <span
                                v-if="unit === 'minutes'"
                                class="pb-0.5 text-lg font-semibold"
                                aria-hidden="true"
                                >:</span
                            >
                            <div class="date-time-segment">
                                <button
                                    type="button"
                                    :aria-label="
                                        unit === 'hours'
                                            ? 'Mais uma hora'
                                            : 'Mais cinco minutos'
                                    "
                                    tabindex="-1"
                                    class="icon-button rounded-full text-zinc-600 focus-ring hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                                    @click="stepTime(unit, 1)"
                                >
                                    <ChevronUp
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                                <input
                                    :value="
                                        pad(unit === 'hours' ? hours : minutes)
                                    "
                                    type="text"
                                    role="spinbutton"
                                    inputmode="numeric"
                                    maxlength="2"
                                    :data-time-field="unit"
                                    :aria-label="
                                        unit === 'hours' ? 'Horas' : 'Minutos'
                                    "
                                    :aria-valuemin="0"
                                    :aria-valuemax="unit === 'hours' ? 23 : 59"
                                    :aria-valuenow="
                                        unit === 'hours' ? hours : minutes
                                    "
                                    autocomplete="off"
                                    class="date-time-field form-input"
                                    @input="onTimeFieldInput(unit, $event)"
                                    @blur="onTimeFieldBlur"
                                    @focus="
                                        (
                                            $event.target as HTMLInputElement
                                        ).select()
                                    "
                                    @keydown="onTimeFieldKeydown(unit, $event)"
                                />
                                <button
                                    type="button"
                                    :aria-label="
                                        unit === 'hours'
                                            ? 'Menos uma hora'
                                            : 'Menos cinco minutos'
                                    "
                                    tabindex="-1"
                                    class="icon-button rounded-full text-zinc-600 focus-ring hover:bg-zinc-900/[0.06] dark:text-zinc-300 dark:hover:bg-white/10"
                                    @click="stepTime(unit, -1)"
                                >
                                    <ChevronDown
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="date-foot">
                        <button
                            type="button"
                            class="date-foot-button focus-ring"
                            :aria-disabled="todayDisabled ? 'true' : undefined"
                            @click="todayDisabled ? undefined : pickToday()"
                        >
                            {{ todayLabel }}
                        </button>
                        <button
                            v-if="clearable && hasValue"
                            type="button"
                            class="date-foot-button focus-ring"
                            @click="clearValue"
                        >
                            Limpar
                        </button>
                        <button
                            v-if="sheetMode"
                            type="button"
                            class="date-foot-button focus-ring"
                            @click="startTyping"
                        >
                            Escrever
                        </button>
                        <button
                            v-if="hasTime"
                            type="button"
                            class="date-foot-button ms-auto focus-ring"
                            @click="closePanel(true)"
                        >
                            Concluir
                        </button>
                    </div>
                </div>
            </template>
        </Teleport>
    </div>
</template>
