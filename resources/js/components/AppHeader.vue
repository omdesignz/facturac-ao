<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import AgtStateChip from '@/components/AgtStateChip.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NotificationsMenu from '@/components/NotificationsMenu.vue';
import WorkSessionTimer from '@/components/WorkSessionTimer.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import { dashboard } from '@/routes';

/**
 * On a desktop the sidebar carries the company, search, the AGT and the
 * account, so this bar keeps only what is about now: the session and the bell.
 * The phone keeps the full bar, and only one search is ever mounted.
 */
const isDesktop = useMediaQuery('(min-width: 64rem)');

/**
 * At rest the bar is the page's own ground, so a screen opens on its header
 * rather than on chrome; the hairline only appears once content slides under.
 */
const scrolled = ref(typeof window !== 'undefined' && window.scrollY > 4);

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
        class="sticky inset-bs-[var(--impersonation-bar,0px)] z-40 flex h-16 shrink-0 items-center gap-3 border-b px-4 transition-[border-color,background-color] duration-200 sm:gap-4 sm:px-6 lg:h-12 lg:justify-end lg:px-6"
        :class="
            scrolled
                ? 'border-[var(--ui-hairline)] bg-[color-mix(in_srgb,var(--surface-page)_82%,transparent)] backdrop-blur-xl'
                : 'border-transparent bg-[var(--surface-page)] lg:bg-transparent'
        "
    >
        <Link
            v-if="!isDesktop"
            :href="dashboard.url()"
            class="-ml-1 flex min-h-11 shrink-0 items-center rounded-lg focus-ring"
        >
            <BrandWordmark
                class="h-[1.25rem] w-auto text-brand-950 dark:text-stone-50"
                aria-hidden="true"
            />
            <span class="sr-only">facturac.ao, ir para Hoje</span>
        </Link>

        <GlobalSearch v-if="!isDesktop" />

        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
            <template v-if="!isDesktop">
                <AgtStateChip />
                <WorkspaceSwitcher />
            </template>
            <WorkSessionTimer />
            <NotificationsMenu />
            <AccountMenu v-if="!isDesktop" />
        </div>
    </header>
</template>
