<script setup lang="ts">
import { Menu as MenuIcon } from '@lucide/vue';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NotificationsMenu from '@/components/NotificationsMenu.vue';
import WorkSessionTimer from '@/components/WorkSessionTimer.vue';

defineEmits<{ openNavigation: [] }>();

/**
 * At rest the bar is the page's own ground, so a screen opens on its header
 * rather than on chrome; the hairline only appears once content slides under.
 */
const scrolled = ref(false);

function trackScroll(): void {
    scrolled.value = window.scrollY > 4;
}

onMounted(() => {
    trackScroll();
    window.addEventListener('scroll', trackScroll, { passive: true });
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', trackScroll);
});
</script>

<template>
    <header
        class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-3 border-b px-4 backdrop-blur-xl transition-[border-color,background-color] duration-200 sm:gap-4 sm:px-6 lg:px-8"
        :class="
            scrolled
                ? 'border-zinc-900/[0.07] bg-stone-50/85 dark:border-white/10 dark:bg-zinc-950/85'
                : 'border-transparent bg-stone-50 dark:bg-zinc-950'
        "
    >
        <button
            type="button"
            class="-ml-2 icon-button rounded-full text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.05] hover:text-zinc-950 lg:hidden dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white"
            @click="$emit('openNavigation')"
        >
            <span class="sr-only">Abrir navegação</span>
            <MenuIcon class="size-5" aria-hidden="true" />
        </button>

        <GlobalSearch />

        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
            <WorkSessionTimer />
            <NotificationsMenu />
            <AccountMenu />
        </div>
    </header>
</template>
