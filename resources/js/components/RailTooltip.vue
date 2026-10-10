<script lang="ts">
/** When any rail tooltip last went away, shared so a sweep along the rail feels continuous. */
let lastClosedAt = 0;
</script>

<script setup lang="ts">
import { onBeforeUnmount, ref, useId } from 'vue';

/**
 * Label for the collapsed sidebar rail.
 *
 * Native `title` was doing this job: it waits about a second, ignores keyboard
 * focus, and cannot be styled. This shows on hover and on keyboard focus, and
 * is positioned `fixed` so the rail's own scroll container can't clip it.
 *
 * It waits a beat before the first one so a pointer passing over the rail does
 * not light up every label, but once one has been seen the next is immediate.
 * It leaves at once, never on touch, and Escape dismisses it.
 */
const props = withDefaults(
    defineProps<{
        label: string;
        detail?: string;
        /** The expanded sidebar shows its own labels, so no tooltip is needed. */
        disabled?: boolean;
    }>(),
    { detail: undefined, disabled: false },
);

defineSlots<{
    /** The id to put on the control as `aria-describedby`. */
    default(props: { describedby: string }): unknown;
}>();

const HOVER_DELAY_MS = 350;
const WARM_WINDOW_MS = 300;

const tooltipId = useId();
const visible = ref(false);
const position = ref({ top: '0px', left: '0px' });
let showTimer: number | undefined;

function cancelPending(): void {
    if (showTimer !== undefined) {
        window.clearTimeout(showTimer);
        showTimer = undefined;
    }
}

function place(target: HTMLElement): void {
    // The wrapper is `display: contents`, which has no box of its own — its
    // rect is all zeros. Measure the real element it wraps.
    const anchor = target.firstElementChild ?? target;
    const rect = anchor.getBoundingClientRect();

    // Anchor to the rail's outer edge, not the icon's. Measuring from the icon
    // left the tooltip sitting on top of the sidebar instead of beside it.
    const rail = anchor.closest('[data-rail]');
    const edge = rail ? rail.getBoundingClientRect().right : rect.right;

    position.value = {
        top: `${rect.top + rect.height / 2}px`,
        left: `${edge + 12}px`,
    };
    visible.value = true;
}

function dismissOnEscape(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        hide();
    }
}

function reveal(target: HTMLElement): void {
    place(target);
    window.addEventListener('keydown', dismissOnEscape);
}

function show(event: Event, immediate: boolean): void {
    const target = event.currentTarget;

    if (!(target instanceof HTMLElement) || props.disabled) {
        return;
    }

    cancelPending();

    const warm = Date.now() - lastClosedAt < WARM_WINDOW_MS;

    if (immediate || warm) {
        reveal(target);

        return;
    }

    showTimer = window.setTimeout(() => {
        showTimer = undefined;
        reveal(target);
    }, HOVER_DELAY_MS);
}

function hide(): void {
    cancelPending();
    window.removeEventListener('keydown', dismissOnEscape);

    if (visible.value) {
        visible.value = false;
        lastClosedAt = Date.now();
    }
}

/** Touch and pen have no hover; the label would only get stuck open. */
function onPointerEnter(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        show(event, false);
    }
}

function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType === 'mouse') {
        hide();
    }
}

/** Keyboard focus shows it at once; focus that came from a tap does not. */
function onFocusIn(event: FocusEvent): void {
    const origin = event.target;

    if (origin instanceof HTMLElement && origin.matches(':focus-visible')) {
        show(event, true);
    }
}

onBeforeUnmount(hide);
</script>

<template>
    <div
        class="contents"
        @pointerenter="onPointerEnter"
        @pointerleave="onPointerLeave"
        @focusin="onFocusIn"
        @focusout="hide"
    >
        <slot :describedby="tooltipId" />
    </div>

    <Teleport to="body">
        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="-translate-x-1 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
        >
            <div
                v-if="visible"
                :id="tooltipId"
                :style="position"
                class="pointer-events-none fixed z-[90] -translate-y-1/2 rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs font-semibold whitespace-nowrap text-white shadow-lg ring-1 ring-white/10 dark:bg-zinc-800"
                role="tooltip"
            >
                {{ label }}
                <span
                    v-if="detail"
                    class="mt-0.5 block text-[0.6875rem] font-normal text-zinc-400"
                    >{{ detail }}</span
                >
            </div>
        </transition>
    </Teleport>
</template>
