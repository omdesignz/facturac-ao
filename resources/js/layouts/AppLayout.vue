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
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Bell,
    Check,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    Info,
    Menu as MenuIcon,
    Monitor,
    Moon,
    TriangleAlert,
    Sun,
    X,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import type { Component } from 'vue';
import AppRail from '@/components/AppRail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import GlobalSearch from '@/components/GlobalSearch.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SidebarNavigation from '@/components/SidebarNavigation.vue';
import TermsReacceptanceNotice from '@/components/TermsReacceptanceNotice.vue';
import WorkSessionTimer from '@/components/WorkSessionTimer.vue';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
} from '@/lib/appearance';
import type { Appearance } from '@/lib/appearance';
import { logout, onboarding } from '@/routes';
import {
    index as notificationsIndex,
    read as markNotificationRead,
    readAll as markAllNotificationsRead,
} from '@/routes/notifications';
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

/**
 * Standing state that needs a decision — not events.
 *
 * Kept apart from notifications on purpose: an unfinished company profile is
 * true until someone fixes it, so it has no moment to have happened at and
 * nothing to mark as read.
 */
const attentionItems = computed(() => {
    const items: Array<{
        title: string;
        detail: string;
        tone: 'success' | 'warning';
        href: string;
    }> = [];

    if (
        currentWorkspace.value?.legal_entity === null ||
        currentWorkspace.value?.legal_entity.status === 'draft'
    ) {
        items.push({
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
        items.push({
            title: 'Active a autenticação multifactor',
            detail: 'O seu papel tem permissões elevadas neste espaço.',
            tone: 'warning',
            href: security.url(),
        });
    }

    return items;
});

const notifications = computed(() => page.props.notifications);

/**
 * What the dot on the bell counts.
 *
 * Unread notifications and outstanding setup both mean "there is something
 * here for you"; splitting them into two indicators would make neither worth
 * looking at.
 */
const attentionCount = computed(
    () =>
        (notifications.value?.unread ?? 0) +
        attentionItems.value.filter((item) => item.tone === 'warning').length,
);

const relativeFormatter = new Intl.RelativeTimeFormat('pt-PT', {
    numeric: 'auto',
});

/** "há 5 minutos" reads better than a timestamp in a list you scan. */
function relativeTime(value: string | null): string {
    if (value === null) {
        return '';
    }

    const seconds = Math.round((Date.parse(value) - Date.now()) / 1000);
    const steps: Array<[Intl.RelativeTimeFormatUnit, number]> = [
        ['second', 60],
        ['minute', 60],
        ['hour', 24],
        ['day', 7],
        ['week', 4.35],
        ['month', 12],
    ];

    let amount = seconds;

    for (const [unit, size] of steps) {
        if (Math.abs(amount) < size) {
            return relativeFormatter.format(Math.round(amount), unit);
        }

        amount /= size;
    }

    return relativeFormatter.format(Math.round(amount), 'year');
}

/** Tone drives the chip, so the list is scannable before it is read. */
const toneChip: Record<string, string> = {
    critical:
        'bg-rose-100 text-rose-700 dark:bg-rose-400/10 dark:text-rose-300',
    warning:
        'bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300',
    success:
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-400/10 dark:text-emerald-300',
    neutral: 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
};

const toneIcon: Record<string, Component> = {
    critical: CircleAlert,
    warning: TriangleAlert,
    success: CircleCheck,
    neutral: Info,
};

function openNotification(id: string): void {
    router.post(markNotificationRead.url({ notification: id }));
}

function markEverythingRead(): void {
    router.post(markAllNotificationsRead.url(), {}, { preserveScroll: true });
}

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
        <!-- Above the sidebar and header on purpose: whose account is on screen
             is the one thing that must never be scrolled or clicked away. -->
        <ImpersonationBanner />
        <CookieConsent />
        <!-- Mounted once so no page has to carry its own copy; every question
             the app asks is asked in the same voice. -->
        <ConfirmDialog />

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
            class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-[4.5rem] lg:flex-col"
        >
            <AppRail />
        </div>

        <div class="lg:pl-[4.5rem]">
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

                <div class="flex flex-1 items-center gap-4 lg:gap-6">
                    <GlobalSearch />

                    <div class="flex items-center gap-2 sm:gap-3">
                        <WorkSessionTimer />

                        <Menu as="div" class="relative">
                            <MenuButton
                                class="relative icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
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
                                    class="absolute right-0 z-20 mt-3 flex max-h-[min(32rem,calc(100vh-6rem))] w-[min(24rem,calc(100vw-2rem))] origin-top-right flex-col overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-zinc-900/10 focus:outline-none dark:bg-zinc-900 dark:ring-white/10"
                                >
                                    <div
                                        class="flex shrink-0 items-center justify-between gap-3 border-b border-zinc-100 px-4 py-3 dark:border-white/10"
                                    >
                                        <p
                                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                                        >
                                            Notificações
                                        </p>
                                        <button
                                            v-if="
                                                (notifications?.unread ?? 0) > 0
                                            "
                                            type="button"
                                            class="rounded-lg px-2 py-1 text-xs font-medium text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                                            @click="markEverythingRead"
                                        >
                                            Marcar todas como lidas
                                        </button>
                                        <span
                                            v-else
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                            >Nada por ler</span
                                        >
                                    </div>

                                    <div class="min-h-0 flex-1 overflow-y-auto">
                                        <MenuItem
                                            v-for="entry in notifications?.recent ??
                                            []"
                                            :key="entry.id"
                                            v-slot="{ active }"
                                        >
                                            <button
                                                type="button"
                                                :class="[
                                                    active
                                                        ? 'bg-zinc-50 dark:bg-white/5'
                                                        : '',
                                                    'flex w-full gap-3 px-4 py-3 text-left',
                                                ]"
                                                @click="
                                                    openNotification(entry.id)
                                                "
                                            >
                                                <span
                                                    :class="[
                                                        toneChip[entry.tone] ??
                                                            toneChip.neutral,
                                                        'mt-0.5 grid size-8 shrink-0 place-items-center rounded-full',
                                                    ]"
                                                >
                                                    <component
                                                        :is="
                                                            toneIcon[
                                                                entry.tone
                                                            ] ?? Bell
                                                        "
                                                        class="size-4"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <span class="min-w-0 flex-1">
                                                    <span
                                                        class="flex items-baseline gap-2"
                                                    >
                                                        <span
                                                            class="min-w-0 flex-1 truncate text-sm font-semibold text-zinc-900 dark:text-white"
                                                            >{{
                                                                entry.title
                                                            }}</span
                                                        >
                                                        <span
                                                            v-if="!entry.read"
                                                            class="mt-1 size-1.5 shrink-0 rounded-full bg-brand-600 dark:bg-accent-400"
                                                            aria-label="Por ler"
                                                        />
                                                    </span>
                                                    <span
                                                        class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                                        >{{ entry.body }}</span
                                                    >
                                                    <span
                                                        class="mt-1 block text-xs text-zinc-400 dark:text-zinc-500"
                                                        >{{
                                                            relativeTime(
                                                                entry.created_at,
                                                            )
                                                        }}</span
                                                    >
                                                </span>
                                            </button>
                                        </MenuItem>

                                        <p
                                            v-if="
                                                (notifications?.recent
                                                    ?.length ?? 0) === 0
                                            "
                                            class="px-4 py-8 text-center text-xs/5 text-zinc-500 dark:text-zinc-400"
                                        >
                                            Ainda não há nada aqui. Avisamos
                                            quando a AGT responder, quando uma
                                            factura vencer e quando uma avença
                                            gerar.
                                        </p>

                                        <div
                                            v-if="attentionItems.length > 0"
                                            class="border-t border-zinc-100 dark:border-white/10"
                                        >
                                            <p
                                                class="px-4 pt-3 pb-1 eyebrow text-zinc-400 dark:text-zinc-500"
                                            >
                                                A precisar de decisão
                                            </p>
                                            <MenuItem
                                                v-for="item in attentionItems"
                                                :key="item.title"
                                                v-slot="{ active }"
                                            >
                                                <Link
                                                    :href="item.href"
                                                    :class="[
                                                        active
                                                            ? 'bg-zinc-50 dark:bg-white/5'
                                                            : '',
                                                        'flex w-full gap-3 px-4 py-3 text-left',
                                                    ]"
                                                >
                                                    <span
                                                        class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-400/10 dark:text-amber-300"
                                                    >
                                                        <TriangleAlert
                                                            class="size-4"
                                                            aria-hidden="true"
                                                        />
                                                    </span>
                                                    <span>
                                                        <span
                                                            class="block text-sm font-semibold text-zinc-900 dark:text-white"
                                                            >{{
                                                                item.title
                                                            }}</span
                                                        >
                                                        <span
                                                            class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                                            >{{
                                                                item.detail
                                                            }}</span
                                                        >
                                                    </span>
                                                </Link>
                                            </MenuItem>
                                        </div>
                                    </div>

                                    <MenuItem v-slot="{ active }">
                                        <Link
                                            :href="notificationsIndex.url()"
                                            :class="[
                                                active
                                                    ? 'bg-zinc-50 dark:bg-white/5'
                                                    : '',
                                                'block shrink-0 border-t border-zinc-100 px-4 py-3 text-center text-sm font-semibold text-brand-700 dark:border-white/10 dark:text-brand-300',
                                            ]"
                                        >
                                            Ver todas
                                        </Link>
                                    </MenuItem>
                                </MenuItems>
                            </transition>
                        </Menu>

                        <Menu as="div" class="relative">
                            <MenuButton
                                class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
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
                                class="flex items-center gap-2 rounded-xl p-1.5 text-left focus-ring transition hover:bg-zinc-100 dark:hover:bg-white/5"
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

            <TermsReacceptanceNotice />

            <main class="min-h-[calc(100vh-4rem)]">
                <slot />
            </main>
        </div>
    </div>
</template>
