<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link } from '@inertiajs/vue3';
import { ChevronDown, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { useNavigation } from '@/lib/navigation';
import type { NavigationItem } from '@/lib/navigation';
import { activeDestination, destinationsFor } from '@/lib/top-navigation';
import { dashboard } from '@/routes';
import { create as invoiceCreate } from '@/routes/invoices';

/**
 * The desktop navigation: five words along the top, the active one filled.
 * "Hoje" goes straight to the dashboard; every other word opens a menu of the
 * pages that live under it, so nothing is more than one step away. Anything
 * that belongs to none of them sits under a sixth, "Mais".
 */
const { groups, activeItem } = useNavigation();

const destinations = computed(() => destinationsFor(groups.value));
const active = computed(() =>
    activeDestination(destinations.value, activeItem.value),
);

const newInvoice: NavigationItem = {
    name: 'Nova factura',
    href: invoiceCreate.url(),
    match: '/documents/invoices',
    icon: Plus,
};

const pill =
    'flex h-10 items-center gap-1 rounded-full px-3.5 text-sm font-medium focus-ring transition hover:bg-zinc-900/[0.05] dark:hover:bg-white/10';

function pillClass(key: string): string {
    return [
        pill,
        active.value === key
            ? 'bg-zinc-900/[0.07] text-zinc-950 dark:bg-white/15 dark:text-white'
            : 'text-zinc-600 dark:text-zinc-300',
    ].join(' ');
}
</script>

<template>
    <nav class="hidden items-center gap-0.5 lg:flex" aria-label="Principal">
        <template v-for="destination in destinations" :key="destination.key">
            <Link
                v-if="destination.key === 'today'"
                :href="dashboard.url()"
                :aria-current="active === 'today' ? 'page' : undefined"
                :class="pillClass('today')"
            >
                {{ destination.name }}
            </Link>

            <Menu v-else as="div" class="relative">
                <MenuButton :class="pillClass(destination.key)">
                    {{ destination.name }}
                    <ChevronDown
                        class="size-3.5 opacity-60"
                        aria-hidden="true"
                    />
                </MenuButton>
                <transition
                    enter-active-class="transition ease-out duration-100"
                    enter-from-class="scale-95 opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition ease-out duration-75"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-95 opacity-0"
                >
                    <MenuItems
                        class="absolute top-[calc(100%+0.5rem)] left-0 z-50 max-h-[min(30rem,calc(100dvh-6rem))] w-64 origin-top-left overflow-y-auto menu-panel p-1.5"
                    >
                        <MenuItem
                            v-for="item in destination.key === 'sell'
                                ? [newInvoice, ...destination.items]
                                : destination.items"
                            :key="item.href"
                            v-slot="{ active: highlighted }"
                        >
                            <Link
                                :href="item.href"
                                :prefetch="item.prefetch !== false"
                                :aria-current="
                                    item === activeItem ? 'page' : undefined
                                "
                                :class="[
                                    highlighted || item === activeItem
                                        ? 'bg-zinc-100 dark:bg-white/10'
                                        : '',
                                    'flex items-center gap-3 menu-item py-2.5 text-sm',
                                ]"
                            >
                                <component
                                    :is="item.icon"
                                    class="size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
                                    :stroke-width="1.75"
                                    aria-hidden="true"
                                />
                                {{ item.name }}
                            </Link>
                        </MenuItem>
                    </MenuItems>
                </transition>
            </Menu>
        </template>
    </nav>
</template>
