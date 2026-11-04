<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import { update as switchWorkspace } from '@/routes/workspace/current';

/**
 * Which company's books are open, and a way to open another. It sits in the top
 * bar as the company's initials; the menu lists every workspace the person
 * belongs to, the current one ticked.
 */
const page = usePage();

const currentWorkspace = computed(() => page.props.currentWorkspace);
const legalEntity = computed(() => currentWorkspace.value?.legal_entity);

const displayName = computed(
    () =>
        legalEntity.value?.trade_name ??
        legalEntity.value?.legal_name ??
        currentWorkspace.value?.name ??
        'Espaço de trabalho',
);

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
    <Menu as="div" class="relative hidden lg:block">
        <MenuButton
            class="grid size-9 place-items-center rounded-xl bg-zinc-900/[0.05] text-xs font-semibold text-zinc-800 focus-ring transition hover:bg-zinc-900/[0.09] dark:bg-white/10 dark:text-zinc-100 dark:hover:bg-white/15"
            :aria-label="`Mudar de espaço de trabalho. Actual: ${displayName}`"
        >
            <span aria-hidden="true">{{ initials(displayName) }}</span>
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
                class="absolute top-[calc(100%+0.5rem)] right-0 z-50 max-h-[min(24rem,calc(100dvh-6rem))] w-64 origin-top-right overflow-y-auto overscroll-contain menu-panel p-1.5"
            >
                <p
                    class="px-2.5 pt-1.5 pb-2 text-xs font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Espaços de trabalho
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
                            'flex w-full items-center gap-2 menu-item py-2 text-left text-sm disabled:cursor-default',
                        ]"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold"
                                >{{ workspace.name
                                }}<span v-if="workspace.current" class="sr-only"
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
</template>
