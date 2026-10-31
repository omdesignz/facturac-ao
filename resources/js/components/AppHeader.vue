<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import AppTopNav from '@/components/AppTopNav.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NotificationsMenu from '@/components/NotificationsMenu.vue';
import WorkSessionTimer from '@/components/WorkSessionTimer.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import { dashboard } from '@/routes';

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
        class="sticky inset-bs-[var(--impersonation-bar,0px)] z-40 flex h-16 shrink-0 items-center gap-3 border-b px-4 transition-[border-color,background-color] duration-200 sm:gap-4 sm:px-6 lg:px-8"
        :class="
            scrolled
                ? 'border-zinc-900/[0.07] bg-white/80 backdrop-blur-xl dark:border-white/10 dark:bg-black/80'
                : 'border-transparent bg-white dark:bg-black'
        "
    >
        <Link
            :href="dashboard.url()"
            class="-ml-1 flex min-h-11 shrink-0 items-center rounded-lg focus-ring lg:mr-4"
        >
            <BrandWordmark
                class="h-[1.25rem] w-auto text-brand-950 dark:text-stone-50"
                aria-hidden="true"
            />
            <span class="sr-only">facturac.ao, ir para Hoje</span>
        </Link>

        <AppTopNav />

        <GlobalSearch class="lg:ml-3 lg:justify-end" />

        <div class="flex shrink-0 items-center gap-1 sm:gap-2">
            <WorkspaceSwitcher />
            <WorkSessionTimer />
            <NotificationsMenu />
            <AccountMenu />
        </div>
    </header>
</template>
