<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';

/**
 * A six-digit code typed one box per digit, as the phone app asks for it.
 *
 * The boxes are a typing aid only: one hidden field carries the whole code
 * under `name`, so the form around it submits exactly what a single field
 * would. Pasting, or a phone filling the code from a message, spreads the
 * digits across the boxes from wherever they land. Backspace on an empty box
 * steps back, the arrow keys move between boxes, and `complete` fires once
 * the last digit is in, so a page can submit without a further click.
 */
const props = withDefaults(
    defineProps<{
        /** The id of the first box, which the field's label points at. */
        id: string;
        name: string;
        label: string;
        length?: number;
        error?: string;
        errorId?: string;
        disabled?: boolean;
        autofocus?: boolean;
    }>(),
    { length: 6, error: undefined, errorId: undefined },
);

const emit = defineEmits<{ complete: [code: string] }>();

const digits = ref<string[]>(Array.from({ length: props.length }, () => ''));
const boxes = ref<HTMLInputElement[]>([]);
const code = computed(() => digits.value.join(''));
const invalid = computed(() => Boolean(props.error));

/** The boxes split in two halves, so a code reads as "123 456". */
const split = computed(() => Math.ceil(props.length / 2));

function focusBox(index: number): void {
    const box = boxes.value[Math.max(0, Math.min(index, props.length - 1))];
    box?.focus();
    box?.select();
}

/**
 * Waits for the render before announcing: a page that submits on `complete`
 * reads the hidden field from the DOM, which only holds the last digit once
 * Vue has patched it. Announcing straight away sent five digits.
 */
function announceIfComplete(): void {
    const value = code.value;

    if (value.length !== props.length || !/^\d+$/.test(value)) {
        return;
    }

    nextTick(() => {
        if (code.value === value) {
            emit('complete', value);
        }
    });
}

/** Writes digits from `start` onwards and moves to the box after the last. */
function spread(value: string, start: number): void {
    const incoming = value.replace(/\D/g, '').slice(0, props.length);

    if (incoming === '') {
        return;
    }

    // A whole code always fills from the first box, wherever it was dropped.
    const from = incoming.length >= props.length ? 0 : start;
    const next = [...digits.value];

    for (let offset = 0; offset < incoming.length; offset++) {
        if (from + offset < props.length) {
            next[from + offset] = incoming[offset]!;
        }
    }

    digits.value = next;
    focusBox(Math.min(from + incoming.length, props.length - 1));
    announceIfComplete();
}

function onInput(index: number, event: Event): void {
    const box = event.target as HTMLInputElement;
    const typed = box.value.replace(/\D/g, '');

    if (typed.length > 1) {
        spread(typed, index);
        box.value = digits.value[index] ?? '';

        return;
    }

    const next = [...digits.value];
    next[index] = typed;
    digits.value = next;
    // Letters never reach the code, so the box must not keep showing them.
    box.value = typed;

    if (typed !== '') {
        if (index < props.length - 1) {
            focusBox(index + 1);
        }

        announceIfComplete();
    }
}

function onKeydown(index: number, event: KeyboardEvent): void {
    if (event.key === 'Backspace' && digits.value[index] === '' && index > 0) {
        event.preventDefault();
        const next = [...digits.value];
        next[index - 1] = '';
        digits.value = next;
        focusBox(index - 1);
    } else if (event.key === 'ArrowLeft' && index > 0) {
        event.preventDefault();
        focusBox(index - 1);
    } else if (event.key === 'ArrowRight' && index < props.length - 1) {
        event.preventDefault();
        focusBox(index + 1);
    } else if (event.key === 'Home') {
        event.preventDefault();
        focusBox(0);
    } else if (event.key === 'End') {
        event.preventDefault();
        focusBox(props.length - 1);
    }
}

function onPaste(index: number, event: ClipboardEvent): void {
    event.preventDefault();
    spread(event.clipboardData?.getData('text') ?? '', index);
}

function clear(): void {
    digits.value = Array.from({ length: props.length }, () => '');
    nextTick(() => focusBox(0));
}

/*
 * A refused code is no use to edit digit by digit: the next one is a fresh
 * six from the authenticator, so the boxes empty and the first takes focus.
 */
watch(
    () => props.error,
    (error) => {
        if (error) {
            clear();
        }
    },
);

defineExpose({ clear, focus: () => focusBox(0) });
</script>

<template>
    <div
        role="group"
        :aria-label="label"
        :aria-describedby="error && errorId ? errorId : undefined"
        class="flex items-center gap-2 sm:gap-2.5"
    >
        <input type="hidden" :name="name" :value="code" />
        <template v-for="(digit, index) in digits" :key="index">
            <span
                v-if="index === split"
                class="w-1 shrink-0 sm:w-1.5"
                aria-hidden="true"
            />
            <input
                :id="index === 0 ? id : `${id}-${index + 1}`"
                ref="boxes"
                :value="digit"
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                :autocomplete="index === 0 ? 'one-time-code' : 'off'"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
                enterkeyhint="done"
                :disabled="disabled"
                :data-auth-autofocus="
                    autofocus && index === 0 ? true : undefined
                "
                :aria-label="`Dígito ${index + 1} de ${length}`"
                :aria-invalid="invalid ? 'true' : undefined"
                :class="[
                    'block form-input h-12 min-w-0 flex-1 p-0 text-center numeric text-xl font-semibold caret-[var(--ui-gold-ink)] sm:h-14 sm:max-w-14 sm:text-2xl',
                    digit !== '' && !invalid
                        ? 'outline-[var(--ui-ink-3)] dark:outline-zinc-500'
                        : '',
                ]"
                @input="onInput(index, $event)"
                @keydown="onKeydown(index, $event)"
                @paste="onPaste(index, $event)"
                @focus="($event.target as HTMLInputElement).select()"
            />
        </template>
    </div>
</template>
