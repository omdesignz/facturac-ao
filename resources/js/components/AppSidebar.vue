<script setup lang="ts">
import {
    Disclosure,
    DisclosureButton,
    DisclosurePanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
} from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    ChevronsUpDown,
    Ellipsis,
    FilePlus2,
    House,
    PanelLeftClose,
    PanelLeftOpen,
    ShieldCheck,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import type { Component } from 'vue';
import AccountMenu from '@/components/AccountMenu.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import { useCanPrepareDocuments, useNavigation } from '@/lib/navigation';
import type { NavigationItem } from '@/lib/navigation';
import {
    isSidebarShortcut,
    sidebarCompact,
    toggleSidebar,
} from '@/lib/sidebar';
import { destinationsFor } from '@/lib/top-navigation';
import { show as agtConnection } from '@/routes/agt/connection';
import { create as createInvoice } from '@/routes/invoices';
import { update as switchWorkspace } from '@/routes/workspace/current';

/**
 * The desktop's navigation: the full list, or a 56px rail of icons that keeps
 * every place one click away (⌘\ switches between them). The groups are the
 * top bar's five words, read from the same map as the phone's menu.
 */
interface SidebarEntry {
    key: string;
    label: string;
    href: string;
    icon: Component;
    prefetch: boolean;
    active: boolean;
}

const page = usePage();
const { groups, activeItem } = useNavigation();
const canPrepareDocuments = useCanPrepareDocuments();

/** The names the desktop uses where the map's own would read as a database. */
const LABELS: Record<string, string> = {
    '/dashboard': 'Hoje',
    '/dividas': 'Quem me deve',
    '/documentos': 'Documentos',
};

const path = computed(() => new URL(page.url, 'http://localhost').pathname);
const isComposing = computed(() =>
    path.value.startsWith('/documents/invoices'),
);

/**
 * The rail keeps the places used every day; the rest wait under Mais, so the
 * rail never runs off the bottom of a laptop screen.
 */
const RAIL_PLACES = new Set([
    '/dashboard',
    'new-invoice',
    '/ponto-de-venda',
    '/orcamentos',
    '/dividas',
    '/customers',
    '/documentos',
    '/catalogue',
    '/agt/submissions',
    '/saft',
]);

function entry(item: NavigationItem): SidebarEntry {
    return {
        key: item.match,
        label: LABELS[item.match] ?? item.name,
        href: item.href,
        icon: item.match === '/dashboard' ? House : item.icon,
        prefetch: item.prefetch !== false,
        active: !isComposing.value && activeItem.value === item,
    };
}

const sections = computed(() => {
    const destinations = destinationsFor(groups.value);

    return destinations.map((destination) => {
        const entries = destination.items.map(entry);

        if (destination.key === 'sell' && canPrepareDocuments.value) {
            entries.unshift({
                key: 'new-invoice',
                label: 'Nova factura',
                href: createInvoice.url(),
                icon: FilePlus2,
                prefetch: true,
                active: isComposing.value,
            });
        }

        return {
            key: destination.key,
            label: destination.key === 'today' ? '' : destination.name,
            entries,
        };
    });
});

const mainSections = computed(() =>
    sections.value
        .filter((section) => section.key !== 'more')
        .map((section) =>
            sidebarCompact.value
                ? {
                      ...section,
                      entries: section.entries.filter((item) =>
                          RAIL_PLACES.has(item.key),
                      ),
                  }
                : section,
        ),
);
const moreEntries = computed(() => {
    const rest =
        sections.value.find((section) => section.key === 'more')?.entries ?? [];

    if (!sidebarCompact.value) {
        return rest;
    }

    const folded = sections.value
        .filter((section) => section.key !== 'more')
        .flatMap((section) =>
            section.entries.filter((item) => !RAIL_PLACES.has(item.key)),
        );

    return [...folded, ...rest];
});
const moreIsActive = computed(() =>
    moreEntries.value.some((item) => item.active),
);

const currentWorkspace = computed(() => page.props.currentWorkspace);
const legalEntity = computed(() => currentWorkspace.value?.legal_entity);
const agt = computed(() => legalEntity.value?.agt);

const companyName = computed(
    () =>
        legalEntity.value?.trade_name ??
        legalEntity.value?.legal_name ??
        currentWorkspace.value?.name ??
        'Espaço de trabalho',
);

const companyDetail = computed(
    () =>
        legalEntity.value?.establishment_name ??
        currentWorkspace.value?.role_label ??
        'A preparar',
);

const companyInitials = computed(() =>
    companyName.value
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase(),
);

function handleKeydown(event: KeyboardEvent): void {
    if (isSidebarShortcut(event)) {
        event.preventDefault();
        toggleSidebar();
    }
}

onMounted(() => window.addEventListener('keydown', handleKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', handleKeydown));

const itemBase =
    'group/item relative flex items-center rounded-[7px] text-[0.8125rem] focus-ring transition-colors';
const itemActive =
    'bg-white font-semibold text-zinc-950 shadow-[0_0_0_1px_var(--ui-hairline),0_1px_2px_rgb(16_16_16/0.05)] dark:bg-[#18181b] dark:text-white dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_0_0_1px_#27272c]';
const itemIdle =
    'font-medium text-zinc-600 hover:bg-zinc-900/[0.04] hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/[0.04] dark:hover:text-white';
const tooltip =
    'pointer-events-none absolute start-full top-1/2 z-50 ms-3 -translate-y-1/2 rounded-md bg-white px-2.5 py-1.5 text-xs font-medium whitespace-nowrap text-zinc-900 opacity-0 shadow-[var(--ui-pop-shadow)] transition-opacity duration-100 group-hover/item:opacity-100 group-hover/item:delay-150 group-focus-visible/item:opacity-100 dark:bg-[#1a1a1e] dark:text-zinc-100';
</script>

<template>
    <aside
        :class="[
            'flex h-full flex-col bg-[var(--surface-side)] shadow-[inset_-1px_0_0_var(--ui-hairline)]',
            sidebarCompact
                ? 'w-14 items-center px-0 py-3'
                : 'w-60 px-2.5 pt-3 pb-2.5',
        ]"
        aria-label="Barra lateral"
    >
        <!-- The company: what the books open on, and a way to open another. -->
        <div
            :class="[
                'flex shrink-0 items-center',
                sidebarCompact ? 'flex-col gap-2' : 'gap-0.5',
            ]"
        >
            <Menu
                as="div"
                :class="sidebarCompact ? 'relative' : 'relative min-w-0 flex-1'"
            >
                <MenuButton
                    :class="[
                        'flex items-center rounded-lg text-start focus-ring transition-colors hover:bg-zinc-900/[0.04] dark:hover:bg-white/[0.04]',
                        sidebarCompact
                            ? 'size-10 justify-center'
                            : 'h-10 w-full gap-2.5 px-2',
                    ]"
                    :aria-label="`Mudar de empresa. Actual: ${companyName}`"
                >
                    <span
                        :class="[
                            'grid shrink-0 place-items-center rounded-md bg-accent-400 font-bold text-[#1a1407] shadow-[inset_0_1px_0_rgb(255_255_255/0.4),0_0_0_1px_#db9520]',
                            sidebarCompact
                                ? 'size-7 text-[0.6875rem]'
                                : 'size-6 text-[0.625rem]',
                        ]"
                        aria-hidden="true"
                        >{{ companyInitials }}</span
                    >
                    <template v-if="!sidebarCompact">
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[0.8125rem] font-semibold tracking-[-0.01em] text-zinc-950 dark:text-white"
                                >{{ companyName }}</span
                            >
                            <span
                                class="block truncate text-[0.71875rem] text-zinc-500 dark:text-zinc-400"
                                >{{ companyDetail }}</span
                            >
                        </span>
                        <ChevronsUpDown
                            class="size-3.5 shrink-0 text-zinc-400"
                            aria-hidden="true"
                        />
                    </template>
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
                        :class="[
                            'absolute z-50 max-h-[min(24rem,calc(100dvh-2rem))] w-64 overflow-y-auto overscroll-contain menu-panel p-1.5',
                            sidebarCompact
                                ? 'start-full top-0 ms-2 origin-top-left'
                                : 'start-0 top-full mt-1.5 origin-top',
                        ]"
                    >
                        <p
                            class="px-2.5 pt-1.5 pb-2 eyebrow text-zinc-500 dark:text-zinc-400"
                        >
                            Empresas
                        </p>
                        <MenuItem
                            v-for="workspace in page.props.auth.workspaces"
                            :key="workspace.public_id"
                            v-slot="{ active }"
                            :disabled="workspace.current"
                        >
                            <Link
                                as="button"
                                method="put"
                                preserve-scroll
                                :href="switchWorkspace.url(workspace.public_id)"
                                :disabled="workspace.current"
                                :class="[
                                    active ? 'bg-zinc-100 dark:bg-white/5' : '',
                                    'flex w-full items-center gap-2 menu-item py-2 text-start text-sm disabled:cursor-default',
                                ]"
                            >
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-semibold"
                                        >{{ workspace.name
                                        }}<span
                                            v-if="workspace.current"
                                            class="sr-only"
                                            >, actual</span
                                        ></span
                                    >
                                    <span
                                        class="block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                        >{{ workspace.role_label }}</span
                                    >
                                </span>
                                <Check
                                    v-if="workspace.current"
                                    class="size-4 text-accent-600 dark:text-accent-400"
                                    aria-hidden="true"
                                />
                            </Link>
                        </MenuItem>
                    </MenuItems>
                </transition>
            </Menu>

            <button
                v-if="!sidebarCompact"
                type="button"
                class="group/item relative grid size-7 shrink-0 place-items-center rounded-md text-zinc-400 focus-ring transition-colors hover:bg-zinc-900/[0.05] hover:text-zinc-700 dark:hover:bg-white/[0.06] dark:hover:text-zinc-200"
                aria-keyshortcuts="Meta+Backslash Control+Backslash"
                @click="toggleSidebar"
            >
                <PanelLeftClose
                    class="size-4"
                    :stroke-width="1.8"
                    aria-hidden="true"
                />
                <span class="sr-only">Recolher a barra lateral</span>
            </button>
        </div>

        <GlobalSearch
            :trigger="sidebarCompact ? 'rail' : 'sidebar'"
            :class="sidebarCompact ? 'mt-2' : 'mt-2'"
        />

        <nav
            aria-label="Navegação principal"
            :class="[
                'mt-1 flex min-h-0 flex-1 flex-col',
                sidebarCompact
                    ? 'items-center'
                    : 'overflow-y-auto overscroll-contain',
            ]"
        >
            <div
                v-for="section in mainSections"
                :key="section.key"
                :class="[
                    'flex flex-col',
                    sidebarCompact
                        ? 'items-center gap-0.5'
                        : 'gap-px pt-3.5 first:pt-1.5',
                ]"
            >
                <span
                    v-if="sidebarCompact"
                    class="my-[0.5625rem] h-px w-5 bg-[var(--ui-line-strong)]"
                    aria-hidden="true"
                />
                <p
                    v-else-if="section.label"
                    class="flex h-6 items-center px-2 text-[0.71875rem] font-semibold text-zinc-500 dark:text-zinc-500"
                >
                    {{ section.label }}
                </p>

                <Link
                    v-for="item in section.entries"
                    :key="item.key"
                    :href="item.href"
                    :prefetch="item.prefetch"
                    :aria-current="item.active ? 'page' : undefined"
                    :aria-label="sidebarCompact ? item.label : undefined"
                    :class="[
                        itemBase,
                        item.active ? itemActive : itemIdle,
                        sidebarCompact
                            ? 'size-9 justify-center'
                            : 'h-8 gap-2.5 px-2',
                    ]"
                >
                    <component
                        :is="item.icon"
                        :class="[
                            sidebarCompact ? 'size-[1.125rem]' : 'size-4',
                            'shrink-0',
                            item.active
                                ? 'text-[#8a5a00] dark:text-accent-400'
                                : 'text-zinc-400 group-hover/item:text-zinc-600 dark:text-zinc-500 dark:group-hover/item:text-zinc-300',
                        ]"
                        :stroke-width="1.8"
                        aria-hidden="true"
                    />
                    <span v-if="!sidebarCompact" class="truncate">{{
                        item.label
                    }}</span>
                    <span v-else :class="tooltip" aria-hidden="true">{{
                        item.label
                    }}</span>
                </Link>
            </div>

            <!-- Setup, account and help: rare places, folded away. -->
            <template v-if="moreEntries.length > 0">
                <Disclosure
                    v-if="!sidebarCompact"
                    v-slot="{ open }"
                    :default-open="moreIsActive"
                    as="div"
                    class="flex flex-col gap-px pt-3.5"
                >
                    <DisclosureButton
                        class="flex h-6 items-center gap-1 rounded-md px-2 text-[0.71875rem] font-semibold text-zinc-500 focus-ring transition-colors hover:text-zinc-800 dark:hover:text-zinc-200"
                    >
                        Mais
                        <ChevronDown
                            :class="[
                                'size-3 transition-transform',
                                open ? '' : '-rotate-90',
                            ]"
                            aria-hidden="true"
                        />
                    </DisclosureButton>
                    <DisclosurePanel class="flex flex-col gap-px">
                        <Link
                            v-for="item in moreEntries"
                            :key="item.key"
                            :href="item.href"
                            :prefetch="item.prefetch"
                            :aria-current="item.active ? 'page' : undefined"
                            :class="[
                                itemBase,
                                item.active ? itemActive : itemIdle,
                                'h-8 gap-2.5 px-2',
                            ]"
                        >
                            <component
                                :is="item.icon"
                                :class="[
                                    'size-4 shrink-0',
                                    item.active
                                        ? 'text-[#8a5a00] dark:text-accent-400'
                                        : 'text-zinc-400 dark:text-zinc-500',
                                ]"
                                :stroke-width="1.8"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ item.label }}</span>
                        </Link>
                    </DisclosurePanel>
                </Disclosure>

                <Menu
                    v-else
                    as="div"
                    class="relative mt-0.5 flex flex-col items-center"
                >
                    <span
                        class="my-[0.5625rem] h-px w-5 bg-[var(--ui-line-strong)]"
                        aria-hidden="true"
                    />
                    <MenuButton
                        :class="[
                            itemBase,
                            moreIsActive ? itemActive : itemIdle,
                            'size-9 justify-center',
                        ]"
                        aria-label="Mais"
                    >
                        <Ellipsis
                            class="size-[1.125rem] text-zinc-400 dark:text-zinc-500"
                            :stroke-width="1.8"
                            aria-hidden="true"
                        />
                        <span :class="tooltip" aria-hidden="true">Mais</span>
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
                            class="absolute start-full top-0 z-50 ms-2 w-60 origin-top-left menu-panel p-1.5"
                        >
                            <MenuItem
                                v-for="item in moreEntries"
                                :key="item.key"
                                v-slot="{ active }"
                            >
                                <Link
                                    :href="item.href"
                                    :prefetch="item.prefetch"
                                    :class="[
                                        active
                                            ? 'bg-zinc-100 dark:bg-white/5'
                                            : '',
                                        'flex w-full items-center gap-2.5 menu-item py-2 text-sm',
                                    ]"
                                >
                                    <component
                                        :is="item.icon"
                                        class="size-4 text-zinc-400"
                                        :stroke-width="1.8"
                                        aria-hidden="true"
                                    />
                                    {{ item.label }}
                                </Link>
                            </MenuItem>
                        </MenuItems>
                    </transition>
                </Menu>
            </template>
        </nav>

        <!-- Whether documents are reaching the AGT, always in view. -->
        <template v-if="agt">
            <Link
                v-if="!sidebarCompact"
                :href="agtConnection.url()"
                class="mt-3 flex shrink-0 items-center gap-2 rounded-[10px] bg-[var(--surface-raised)] px-2.5 py-2 text-[0.78125rem] font-semibold text-zinc-900 shadow-[var(--ui-card-shadow)] focus-ring transition-colors hover:bg-[var(--ui-wash)] dark:text-zinc-100"
            >
                <span
                    :class="[
                        'size-2 rounded-full',
                        agt.verified
                            ? 'bg-emerald-600 shadow-[0_0_0_3px_rgb(16_185_129/0.16)] dark:bg-emerald-400'
                            : 'bg-accent-400 shadow-[0_0_0_3px_rgb(249_178_51/0.2)]',
                    ]"
                    aria-hidden="true"
                />
                {{ agt.verified ? 'AGT ligada' : 'AGT por verificar' }}
            </Link>
            <Link
                v-else
                :href="agtConnection.url()"
                :aria-label="agt.verified ? 'AGT ligada' : 'AGT por verificar'"
                :class="[itemBase, itemIdle, 'mt-2 size-9 justify-center']"
            >
                <ShieldCheck
                    class="size-[1.125rem] text-zinc-400 dark:text-zinc-500"
                    :stroke-width="1.8"
                    aria-hidden="true"
                />
                <span
                    :class="[
                        'absolute end-1.5 bottom-1.5 size-2 rounded-full shadow-[0_0_0_2px_var(--surface-side)]',
                        agt.verified
                            ? 'bg-emerald-600 dark:bg-emerald-400'
                            : 'bg-accent-400',
                    ]"
                    aria-hidden="true"
                />
                <span :class="tooltip" aria-hidden="true">{{
                    agt.verified ? 'AGT ligada' : 'AGT por verificar'
                }}</span>
            </Link>
        </template>

        <button
            v-if="sidebarCompact"
            type="button"
            :class="[itemBase, itemIdle, 'mt-0.5 size-9 justify-center']"
            aria-keyshortcuts="Meta+Backslash Control+Backslash"
            @click="toggleSidebar"
        >
            <PanelLeftOpen
                class="size-[1.125rem] text-zinc-400 dark:text-zinc-500"
                :stroke-width="1.8"
                aria-hidden="true"
            />
            <span class="sr-only">Expandir a barra lateral</span>
            <span :class="tooltip" aria-hidden="true">Expandir</span>
        </button>

        <AccountMenu
            :placement="sidebarCompact ? 'rail' : 'sidebar'"
            :class="sidebarCompact ? 'mt-1.5' : 'mt-2'"
        />
    </aside>
</template>
