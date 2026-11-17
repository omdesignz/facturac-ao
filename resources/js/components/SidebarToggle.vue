<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { detectApplePlatform, keyCap } from '@/lib/keyboard';
import { sidebarCompact, toggleSidebar } from '@/lib/sidebar';

/**
 * Folds the desktop sidebar to its rail of icons and back. It sits in the bar
 * above the page rather than inside the sidebar, so it stays in the same spot
 * whichever width the sidebar has. The two strokes say which state it is in:
 * the lower one is short while the full list is open, long once it is folded.
 */
const isApplePlatform = ref(false);

onMounted(() => {
    isApplePlatform.value = detectApplePlatform();
});

const label = computed(() =>
    sidebarCompact.value
        ? 'Expandir a barra lateral'
        : 'Recolher a barra lateral',
);
const shortcut = computed(() =>
    isApplePlatform.value ? ['⌘', '\\'] : ['Ctrl', '\\'],
);
</script>

<template>
    <button
        type="button"
        class="group/toggle relative -ms-1.5 grid size-8 shrink-0 place-items-center rounded-lg text-[var(--ui-ink-3)] focus-ring transition-colors hover:bg-zinc-900/[0.05] hover:text-[var(--ui-ink)] active:scale-[0.96] dark:hover:bg-white/[0.06]"
        :aria-label="label"
        :aria-expanded="!sidebarCompact"
        aria-keyshortcuts="Meta+Backslash Control+Backslash"
        data-sidebar-toggle
        @click="toggleSidebar"
    >
        <svg
            viewBox="0 0 20 20"
            class="size-[1.125rem]"
            fill="none"
            stroke="currentColor"
            stroke-width="1.7"
            stroke-linecap="round"
            aria-hidden="true"
        >
            <path d="M3.5 7h13" />
            <path
                d="M3.5 13h13"
                :class="[
                    'origin-[3.5px_13px] transition-transform duration-200 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none',
                    sidebarCompact ? 'scale-x-100' : 'scale-x-[0.54]',
                ]"
            />
        </svg>

        <span
            class="pointer-events-none absolute start-0 top-full z-50 mt-2 flex items-center gap-2 rounded-md bg-white py-1.5 ps-2.5 pe-1.5 text-xs font-medium whitespace-nowrap text-zinc-900 opacity-0 shadow-[var(--ui-pop-shadow)] transition-opacity duration-100 group-hover/toggle:opacity-100 group-hover/toggle:delay-300 group-focus-visible/toggle:opacity-100 dark:bg-[#1a1a1e] dark:text-zinc-100"
            aria-hidden="true"
        >
            {{ sidebarCompact ? 'Expandir' : 'Recolher' }}
            <span class="flex gap-0.5">
                <kbd v-for="key in shortcut" :key="key" :class="keyCap">{{
                    key
                }}</kbd>
            </span>
        </span>
    </button>
</template>
