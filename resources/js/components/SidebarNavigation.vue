<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown, Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import { useNavigation } from '@/lib/navigation';
import { create as createInvoice } from '@/routes/invoices';
import { update as switchWorkspace } from '@/routes/workspace/current';

/**
 * The full navigation, used where there is room for words: the menu that
 * slides in on phones and tablets. Desktop uses AppRail; both read the same
 * groups from lib/navigation, so a page can never be in one and not the other.
 */
defineEmits<{
    navigate: [];
    close: [];
}>();

const page = usePage();
const { groups, activeItem } = useNavigation();

const currentWorkspace = computed(() => page.props.currentWorkspace);
const legalEntity = computed(() => currentWorkspace.value?.legal_entity);

/** Viewers can look but not prepare, so the drawer does not offer them to. */
const canPrepareDocuments = computed(() => {
    const current = page.props.auth.workspaces.find(
        (workspace) => workspace.current,
    );

    return current !== undefined && current.role !== 'viewer';
});

const displayName = computed(
    () =>
        legalEntity.value?.trade_name ??
        legalEntity.value?.legal_name ??
        currentWorkspace.value?.name ??
        'Espaço de trabalho',
);

const workspaceDetail = computed(() => {
    if (legalEntity.value?.masked_nif) {
        const establishment = legalEntity.value.establishment_name
            ? ` · ${legalEntity.value.establishment_name}`
            : '';

        return `NIF ${legalEntity.value.masked_nif}${establishment}`;
    }

    return currentWorkspace.value?.role_label ?? 'A preparar';
});

function initials(name: string): string {
    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();
}
</script>

<template>
    <div class="flex h-full min-h-0 w-full flex-col">
        <div
            class="flex h-[4.75rem] shrink-0 items-center justify-between gap-3 pt-1 pr-3 pl-5"
        >
            <AppLogo />
            <button
                type="button"
                class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                @click="$emit('close')"
            >
                <span class="sr-only">Fechar navegação</span>
                <X class="size-5" aria-hidden="true" />
            </button>
        </div>

        <div
            class="flex min-h-0 flex-1 flex-col gap-y-6 overflow-y-auto px-4 pt-1 pb-6"
        >
            <Menu as="div" class="relative w-full">
                <MenuButton
                    class="group flex w-full items-center gap-3 rounded-2xl bg-zinc-900/[0.04] p-3 text-left focus-ring transition hover:bg-zinc-900/[0.07] dark:bg-white/[0.05] dark:hover:bg-white/10"
                >
                    <span class="sr-only">Mudar de espaço de trabalho</span>
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-accent-400 text-sm font-bold text-brand-950"
                        >{{ initials(displayName) }}</span
                    >
                    <span class="min-w-0 flex-1">
                        <span
                            class="block truncate text-sm font-semibold text-zinc-950 dark:text-white"
                            >{{ displayName }}</span
                        >
                        <span
                            class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400"
                            >{{ workspaceDetail }}</span
                        >
                    </span>
                    <ChevronsUpDown
                        class="size-4 shrink-0 text-zinc-400"
                        aria-hidden="true"
                    />
                </MenuButton>
                <transition
                    enter-active-class="transition ease-out duration-100"
                    enter-from-class="scale-95 opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition ease-in duration-75"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-95 opacity-0"
                >
                    <MenuItems
                        class="absolute left-0 z-30 mt-2 w-full origin-top menu-panel p-1.5"
                    >
                        <p class="px-2.5 pt-1.5 pb-2 eyebrow text-zinc-400">
                            Espaços de trabalho
                        </p>
                        <MenuItem
                            v-for="workspace in page.props.auth.workspaces"
                            :key="workspace.public_id"
                            v-slot="{ active }"
                        >
                            <Link
                                as="button"
                                method="put"
                                preserve-scroll
                                :href="switchWorkspace.url(workspace.public_id)"
                                :disabled="workspace.current"
                                :class="[
                                    active ? 'bg-zinc-100 dark:bg-white/5' : '',
                                    'flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-left text-sm disabled:cursor-default',
                                ]"
                            >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block truncate font-semibold"
                                        >{{ workspace.name }}</span
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

            <nav aria-label="Navegação principal">
                <ul role="list" class="flex flex-col gap-y-5">
                    <li v-for="group in groups" :key="group.key">
                        <p
                            v-if="group.items.length > 1"
                            class="px-3 eyebrow text-zinc-400 dark:text-zinc-500"
                        >
                            {{ group.name }}
                        </p>
                        <ul
                            role="list"
                            :class="[
                                group.items.length > 1 ? 'mt-2' : '',
                                'space-y-0.5',
                            ]"
                        >
                            <li v-for="item in group.items" :key="item.name">
                                <Link
                                    :href="item.href"
                                    prefetch
                                    :aria-current="
                                        activeItem === item ? 'page' : undefined
                                    "
                                    :class="[
                                        activeItem === item
                                            ? 'bg-zinc-900/[0.06] font-semibold text-zinc-950 dark:bg-white/10 dark:text-white'
                                            : 'text-zinc-700 hover:bg-zinc-900/[0.04] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white',
                                        'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm focus-ring transition',
                                    ]"
                                    @click="$emit('navigate')"
                                >
                                    <component
                                        :is="item.icon"
                                        :class="[
                                            activeItem === item
                                                ? 'text-accent-600 dark:text-accent-400'
                                                : 'text-zinc-400',
                                            'size-5 shrink-0',
                                        ]"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    <span class="truncate">{{
                                        item.name
                                    }}</span>
                                </Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </nav>
        </div>

        <div
            v-if="canPrepareDocuments"
            class="shrink-0 border-t border-zinc-900/[0.06] p-4 pb-[max(1rem,env(safe-area-inset-bottom))] dark:border-white/10"
        >
            <Link
                :href="createInvoice.url()"
                class="flex h-11 w-full items-center justify-center gap-2 rounded-full bg-accent-400 text-sm font-semibold text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_1px_2px_rgb(150_95_0/0.25)] focus-ring transition hover:bg-accent-300"
                @click="$emit('navigate')"
            >
                <Plus class="size-4" aria-hidden="true" />
                Nova factura
            </Link>
        </div>
    </div>
</template>
