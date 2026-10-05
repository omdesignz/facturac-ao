<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    LifeBuoy,
    LogOut,
    Monitor,
    Moon,
    ShieldCheck,
    Sun,
    UserCog,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
} from '@/lib/appearance';
import type { Appearance } from '@/lib/appearance';
import { logout } from '@/routes';
import { index as helpIndex } from '@/routes/help';
import { account as accountSettings, security } from '@/routes/settings';

const page = usePage();
const appearance = ref<Appearance>('system');

const currentUser = computed(() => page.props.auth.user);
const currentWorkspace = computed(() => page.props.currentWorkspace);

const userInitials = computed(() => {
    const name = currentUser.value?.name ?? 'Utilizador';

    return name
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();
});

const appearanceOptions = [
    { label: 'Claro', value: 'light' as const, icon: Sun },
    { label: 'Escuro', value: 'dark' as const, icon: Moon },
    { label: 'Sistema', value: 'system' as const, icon: Monitor },
];

const links = [
    { label: 'Conta e segurança', href: security.url(), icon: ShieldCheck },
    { label: 'A sua conta', href: accountSettings.url(), icon: UserCog },
    { label: 'Ajuda e reclamações', href: helpIndex.url(), icon: LifeBuoy },
];

function changeAppearance(value: Appearance): void {
    appearance.value = value;
    storeAppearance(value);
}

onMounted(() => {
    appearance.value = getStoredAppearance();
    applyAppearance(appearance.value);
});
</script>

<template>
    <Menu as="div" class="relative">
        <MenuButton
            class="flex items-center gap-2.5 rounded-full p-1 text-left focus-ring transition hover:bg-zinc-900/[0.05] lg:pr-3 dark:hover:bg-white/10"
        >
            <span
                class="grid size-8 place-items-center rounded-full bg-brand-950 text-[0.6875rem] font-semibold tracking-[0.02em] text-white dark:bg-zinc-100 dark:text-brand-950"
                aria-hidden="true"
                >{{ userInitials }}</span
            >
            <span class="hidden min-w-0 lg:block">
                <span
                    class="block max-w-40 truncate text-[0.8125rem] leading-tight font-semibold text-zinc-950 dark:text-white"
                    >{{ currentUser?.name }}</span
                >
                <span
                    class="block max-w-40 truncate text-xs leading-tight text-zinc-500 dark:text-zinc-400"
                    >{{ currentWorkspace?.role_label ?? 'Utilizador' }}</span
                >
            </span>
            <span class="sr-only lg:hidden">Abrir menu da conta</span>
            <ChevronDown
                class="hidden size-4 text-zinc-400 lg:block"
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
                class="absolute right-0 z-20 mt-2.5 w-64 origin-top-right menu-panel p-1.5"
            >
                <div class="px-3 pt-2 pb-3">
                    <p class="truncate text-sm font-semibold">
                        {{ currentUser?.name }}
                    </p>
                    <p
                        class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        {{ currentUser?.email }}
                    </p>
                    <p
                        v-if="currentWorkspace"
                        class="mt-2 truncate text-xs text-zinc-500 dark:text-zinc-400"
                    >
                        {{ currentWorkspace.role_label }} em
                        <span
                            class="font-medium text-zinc-800 dark:text-zinc-200"
                            >{{ currentWorkspace.name }}</span
                        >
                    </p>
                </div>

                <MenuItem
                    v-for="link in links"
                    :key="link.href"
                    v-slot="{ active }"
                >
                    <Link
                        :href="link.href"
                        :class="[
                            active
                                ? 'bg-zinc-900/[0.04] dark:bg-white/[0.06]'
                                : '',
                            'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm text-zinc-700 dark:text-zinc-200',
                        ]"
                    >
                        <component
                            :is="link.icon"
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        {{ link.label }}
                    </Link>
                </MenuItem>

                <div
                    class="mx-1.5 my-1.5 border-t border-zinc-900/[0.06] dark:border-white/10"
                />

                <p
                    class="px-3 pt-1 pb-2 eyebrow text-zinc-400 dark:text-zinc-500"
                >
                    Aparência
                </p>
                <div
                    class="mx-1.5 mb-1.5 grid grid-cols-3 gap-1 rounded-full bg-zinc-900/[0.04] p-1 dark:bg-white/[0.06]"
                    role="group"
                    aria-label="Aparência"
                >
                    <MenuItem
                        v-for="option in appearanceOptions"
                        :key="option.value"
                        v-slot="{ active }"
                    >
                        <button
                            type="button"
                            :aria-pressed="appearance === option.value"
                            :class="[
                                appearance === option.value
                                    ? 'bg-white text-zinc-950 shadow-[0_0_0_1px_rgb(23_23_22/0.06),0_1px_2px_rgb(23_23_22/0.08)] dark:bg-white/15 dark:text-white dark:shadow-none'
                                    : 'text-zinc-500 dark:text-zinc-400',
                                active && appearance !== option.value
                                    ? 'text-zinc-900 dark:text-white'
                                    : '',
                                'flex h-8 items-center justify-center gap-1.5 rounded-full text-xs font-medium transition',
                            ]"
                            @click="changeAppearance(option.value)"
                        >
                            <component
                                :is="option.icon"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            {{ option.label }}
                        </button>
                    </MenuItem>
                </div>

                <div
                    class="mx-1.5 my-1.5 border-t border-zinc-900/[0.06] dark:border-white/10"
                />

                <MenuItem v-slot="{ active }">
                    <Link
                        as="button"
                        method="post"
                        :href="logout.url()"
                        :class="[
                            active
                                ? 'bg-zinc-900/[0.04] dark:bg-white/[0.06]'
                                : '',
                            'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm text-zinc-700 dark:text-zinc-200',
                        ]"
                    >
                        <LogOut
                            class="size-4 text-zinc-400"
                            aria-hidden="true"
                        />
                        Terminar sessão
                    </Link>
                </MenuItem>
            </MenuItems>
        </transition>
    </Menu>
</template>
