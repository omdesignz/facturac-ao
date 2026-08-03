<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    Menu,
    MenuButton,
    MenuItem,
    MenuItems,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    ChevronDown,
    CircleCheck,
    Menu as MenuIcon,
    Monitor,
    Moon,
    Search,
    TriangleAlert,
    Sun,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import SidebarNavigation from '@/components/SidebarNavigation.vue';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
} from '@/lib/appearance';
import type { Appearance } from '@/lib/appearance';
import { logout, onboarding } from '@/routes';
import { security } from '@/routes/settings';

const sidebarOpen = ref(false);
const appearance = ref<Appearance>('system');
const page = usePage();

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

const currentAppearance = computed(
    () =>
        appearanceOptions.find((option) => option.value === appearance.value) ??
        appearanceOptions[2],
);

const notifications = computed(() => {
    const attentionItems: Array<{
        title: string;
        detail: string;
        tone: 'success' | 'warning';
        href: string;
    }> = [];

    if (
        currentWorkspace.value?.legal_entity === null ||
        currentWorkspace.value?.legal_entity.status === 'draft'
    ) {
        attentionItems.push({
            title: 'Complete o perfil da empresa',
            detail: 'Faltam dados legais e do estabelecimento principal.',
            tone: 'warning',
            href: onboarding.url(),
        });
    }

    if (
        currentWorkspace.value?.requires_mfa &&
        !currentUser.value?.two_factor_enabled
    ) {
        attentionItems.push({
            title: 'Active a autenticação multifactor',
            detail: 'O seu papel tem permissões elevadas neste espaço.',
            tone: 'warning',
            href: security.url(),
        });
    }

    if (attentionItems.length === 0) {
        attentionItems.push({
            title: 'Controlos essenciais activos',
            detail: 'Perfil e segurança da conta não exigem atenção imediata.',
            tone: 'success',
            href: security.url(),
        });
    }

    return attentionItems;
});

const attentionCount = computed(
    () =>
        notifications.value.filter(
            (notification) => notification.tone === 'warning',
        ).length,
);

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
    <div
        class="min-h-screen bg-stone-50 text-zinc-950 dark:bg-zinc-950 dark:text-white"
    >
        <TransitionRoot as="template" :show="sidebarOpen">
            <Dialog
                class="relative z-50 lg:hidden"
                @close="sidebarOpen = false"
            >
                <TransitionChild
                    as="template"
                    enter="transition-opacity ease-linear duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="transition-opacity ease-linear duration-200"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div
                        class="fixed inset-0 bg-zinc-950/80 backdrop-blur-sm"
                    />
                </TransitionChild>

                <div class="fixed inset-0 flex">
                    <TransitionChild
                        as="template"
                        enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-full"
                        enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-full"
                    >
                        <DialogPanel
                            class="relative mr-16 flex w-full max-w-xs flex-1"
                        >
                            <TransitionChild
                                as="template"
                                enter="ease-in-out duration-200"
                                enter-from="opacity-0"
                                enter-to="opacity-100"
                                leave="ease-in-out duration-200"
                                leave-from="opacity-100"
                                leave-to="opacity-0"
                            >
                                <div
                                    class="absolute top-0 left-full flex w-16 justify-center pt-5"
                                >
                                    <button
                                        type="button"
                                        class="-m-2.5 p-2.5 text-white"
                                        @click="sidebarOpen = false"
                                    >
                                        <span class="sr-only"
                                            >Fechar navegação</span
                                        >
                                        <X class="size-6" aria-hidden="true" />
                                    </button>
                                </div>
                            </TransitionChild>
                            <SidebarNavigation
                                @navigate="sidebarOpen = false"
                            />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <div
            class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-72 lg:flex-col"
        >
            <SidebarNavigation />
        </div>

        <div class="lg:pl-72">
            <header
                class="sticky top-0 z-40 flex h-16 shrink-0 items-center gap-4 border-b border-zinc-200/80 bg-white/90 px-4 backdrop-blur-xl sm:px-6 lg:px-8 dark:border-white/10 dark:bg-zinc-950/85"
            >
                <button
                    type="button"
                    class="-m-2.5 p-2.5 text-zinc-700 hover:text-zinc-950 lg:hidden dark:text-zinc-400 dark:hover:text-white"
                    @click="sidebarOpen = true"
                >
                    <span class="sr-only">Abrir navegação</span>
                    <MenuIcon class="size-6" aria-hidden="true" />
                </button>

                <div
                    class="h-6 w-px bg-zinc-900/10 lg:hidden dark:bg-white/10"
                    aria-hidden="true"
                />

                <div class="flex flex-1 gap-4 self-stretch lg:gap-6">
                    <label class="relative flex min-w-0 flex-1 items-center">
                        <span class="sr-only">Pesquisar</span>
                        <Search
                            class="pointer-events-none absolute left-0 size-5 text-zinc-400"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            class="h-full w-full bg-transparent pr-4 pl-8 text-sm text-zinc-900 outline-none placeholder:text-zinc-400 dark:text-white dark:placeholder:text-zinc-500"
                            placeholder="Pesquisar factura, cliente ou NIF…"
                        />
                        <span
                            class="hidden rounded-md border border-zinc-200 bg-zinc-50 px-1.5 py-0.5 text-[0.65rem] font-semibold text-zinc-400 sm:block dark:border-white/10 dark:bg-white/5 dark:text-zinc-500"
                            >⌘ K</span
                        >
                    </label>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <Menu as="div" class="relative">
                            <MenuButton
                                class="relative rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                            >
                                <span class="sr-only">Ver notificações</span>
                                <Bell class="size-5" aria-hidden="true" />
                                <span
                                    v-if="attentionCount > 0"
                                    class="absolute top-1.5 right-1.5 size-2 rounded-full bg-amber-400 ring-2 ring-white dark:ring-zinc-950"
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
                                    class="absolute right-0 z-20 mt-3 w-[min(24rem,calc(100vw-2rem))] origin-top-right overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:ring-white/10"
                                >
                                    <div
                                        class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-white/10"
                                    >
                                        <p
                                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                                        >
                                            Centro de atenção
                                        </p>
                                        <span
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                            >{{
                                                attentionCount > 0
                                                    ? `${attentionCount} ${attentionCount === 1 ? 'acção' : 'acções'}`
                                                    : 'Em dia'
                                            }}</span
                                        >
                                    </div>
                                    <MenuItem
                                        v-for="notification in notifications"
                                        :key="notification.title"
                                        v-slot="{ active }"
                                    >
                                        <Link
                                            :href="notification.href"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-50 dark:bg-white/5'
                                                    : '',
                                                'flex w-full gap-3 px-4 py-3 text-left',
                                            ]"
                                        >
                                            <span
                                                :class="[
                                                    notification.tone ===
                                                    'success'
                                                        ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300'
                                                        : 'bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
                                                    'mt-0.5 grid size-8 shrink-0 place-items-center rounded-full',
                                                ]"
                                            >
                                                <component
                                                    :is="
                                                        notification.tone ===
                                                        'success'
                                                            ? CircleCheck
                                                            : TriangleAlert
                                                    "
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                            </span>
                                            <span>
                                                <span
                                                    class="block text-sm font-semibold text-zinc-900 dark:text-white"
                                                    >{{
                                                        notification.title
                                                    }}</span
                                                >
                                                <span
                                                    class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                                    >{{
                                                        notification.detail
                                                    }}</span
                                                >
                                            </span>
                                        </Link>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>

                        <Menu as="div" class="relative">
                            <MenuButton
                                class="rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                            >
                                <span class="sr-only">Alterar aparência</span>
                                <component
                                    :is="currentAppearance.icon"
                                    class="size-5"
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
                                    class="absolute right-0 z-20 mt-3 w-36 origin-top-right rounded-xl bg-white p-1.5 shadow-xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:ring-white/10"
                                >
                                    <MenuItem
                                        v-for="option in appearanceOptions"
                                        :key="option.value"
                                        v-slot="{ active }"
                                    >
                                        <button
                                            type="button"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-white/5'
                                                    : '',
                                                'flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-sm text-zinc-700 dark:text-zinc-200',
                                            ]"
                                            @click="
                                                changeAppearance(option.value)
                                            "
                                        >
                                            <component
                                                :is="option.icon"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            <span class="flex-1 text-left">{{
                                                option.label
                                            }}</span>
                                            <Check
                                                v-if="
                                                    appearance === option.value
                                                "
                                                class="size-4 text-brand-600 dark:text-brand-300"
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>

                        <div
                            class="hidden h-6 w-px bg-zinc-900/10 sm:block dark:bg-white/10"
                            aria-hidden="true"
                        />

                        <Menu as="div" class="relative">
                            <MenuButton
                                class="flex items-center gap-2 rounded-xl p-1.5 text-left transition hover:bg-zinc-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:hover:bg-white/5"
                            >
                                <span
                                    class="grid size-8 place-items-center rounded-lg bg-brand-100 text-xs font-bold text-brand-800 dark:bg-brand-400/15 dark:text-brand-200"
                                    >{{ userInitials }}</span
                                >
                                <span class="hidden lg:block">
                                    <span
                                        class="block text-xs font-semibold text-zinc-900 dark:text-white"
                                        >{{ currentUser?.name }}</span
                                    >
                                    <span
                                        class="block text-[0.65rem] text-zinc-500 dark:text-zinc-400"
                                        >{{
                                            currentWorkspace?.role_label ??
                                            'Utilizador'
                                        }}</span
                                    >
                                </span>
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
                                    class="absolute right-0 z-20 mt-3 w-48 origin-top-right rounded-xl bg-white p-1.5 shadow-xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:ring-white/10"
                                >
                                    <MenuItem v-slot="{ active }">
                                        <Link
                                            :href="security.url()"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-white/5'
                                                    : '',
                                                'block w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-700 dark:text-zinc-200',
                                            ]"
                                        >
                                            Perfil e segurança
                                        </Link>
                                    </MenuItem>
                                    <MenuItem v-slot="{ active }">
                                        <Link
                                            as="button"
                                            method="post"
                                            :href="logout.url()"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-100 dark:bg-white/5'
                                                    : '',
                                                'block w-full rounded-lg px-3 py-2 text-left text-sm text-zinc-700 dark:text-zinc-200',
                                            ]"
                                        >
                                            Terminar sessão
                                        </Link>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>
                    </div>
                </div>
            </header>

            <main class="min-h-[calc(100vh-4rem)]">
                <slot />
            </main>
        </div>
    </div>
</template>
