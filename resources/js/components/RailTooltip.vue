<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';

/**
 * Label for the collapsed sidebar rail.
 *
 * Native `title` was doing this job: it waits about a second, ignores keyboard
 * focus, and cannot be styled. This shows instantly on hover *and* on focus,
 * and is positioned `fixed` so the rail's own scroll container can't clip it.
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

const visible = ref(false);
const position = ref({ top: '0px', left: '0px' });

function show(event: FocusEvent | MouseEvent): void {
    const target = event.currentTarget;

    if (!(target instanceof HTMLElement)) {
        return;
    }

    if (props.disabled) {
        return;
    }

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

function hide(): void {
    visible.value = false;
}

onBeforeUnmount(hide);
</script>

<template>
    <div
        class="contents"
        @mouseenter="show"
        @mouseleave="hide"
        @focusin="show"
        @focusout="hide"
    >
        <slot />
    </div>

    <Teleport to="body">
        <transition
            enter-active-class="transition duration-100 ease-out"
            enter-from-class="-translate-x-1 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-75 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="visible"
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
