<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import AgtStateChip from '@/components/AgtStateChip.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import NotificationsMenu from '@/components/NotificationsMenu.vue';
import WorkSessionTimer from '@/components/WorkSessionTimer.vue';
import WorkspaceSwitcher from '@/components/WorkspaceSwitcher.vue';
import { useNavigation } from '@/lib/navigation';
import { dashboard } from '@/routes';

/**
 * Desktop breadcrumbs share the bar with one global search and notifications.
 * The phone keeps company and account controls in the same reachable place.
 */
const isDesktop = useMediaQuery('(min-width: 64rem)');
const page = usePage();
const { activeItem } = useNavigation();
const company = computed(() => page.props.currentWorkspace?.legal_entity);
const companyName = computed(
    () =>
        company.value?.trade_name ??
        company.value?.legal_name ??
        page.props.currentWorkspace?.name ??
        'Empresa',
);
const pageName = computed(() =>
    page.component === 'Dashboard'
        ? 'Hoje'
        : page.component === 'Invoices/Create'
          ? 'Nova factura'
          : page.component === 'Notifications/Index'
            ? 'Notificações'
            : (activeItem.value?.name ?? 'Aplicação'),
);

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
        class="sticky inset-bs-[var(--impersonation-bar,0px)] z-40 flex h-16 shrink-0 items-center gap-3 border-b px-4 transition-[border-color,background-color] duration-200 sm:gap-4 sm:px-6 lg:px-6"
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

        <nav
            v-if="isDesktop"
            aria-label="Localização"
            class="flex min-w-0 flex-1 items-center gap-2 text-sm text-[var(--ui-ink-2)]"
        >
            <span class="max-w-52 truncate" :title="companyName">{{
                companyName
            }}</span>
            <ChevronRight
                class="size-3 shrink-0 text-[var(--ui-ink-3)]"
                aria-hidden="true"
            />
            <span
                aria-current="location"
                class="truncate font-medium text-[var(--ui-ink)]"
                >{{ pageName }}</span
            >
        </nav>

        <GlobalSearch class="lg:max-w-64" />

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
