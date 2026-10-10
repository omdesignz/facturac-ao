<script setup lang="ts">
import { Popover, PopoverButton, PopoverPanel } from '@headlessui/vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Bell,
    CircleAlert,
    CircleCheck,
    Info,
    TriangleAlert,
} from '@lucide/vue';
import { computed } from 'vue';
import type { Component } from 'vue';
import { onboarding } from '@/routes';
import {
    index as notificationsIndex,
    read as markNotificationRead,
    readAll as markAllNotificationsRead,
} from '@/routes/notifications';
import { security } from '@/routes/settings';

const page = usePage();

const currentUser = computed(() => page.props.auth.user);
const currentWorkspace = computed(() => page.props.currentWorkspace);
const notifications = computed(() => page.props.notifications);

/**
 * Standing state that needs a decision — not events.
 *
 * Kept apart from notifications on purpose: an unfinished company profile is
 * true until someone fixes it, so it has no moment to have happened at and
 * nothing to mark as read.
 */
const attentionItems = computed(() => {
    const items: Array<{ title: string; detail: string; href: string }> = [];

    if (
        currentWorkspace.value?.legal_entity === null ||
        currentWorkspace.value?.legal_entity.status === 'draft'
    ) {
        items.push({
            title: 'Complete o perfil da empresa',
            detail: 'Faltam dados legais e do estabelecimento principal.',
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
            href: security.url(),
        });
    }

    return items;
});

/**
 * What the dot on the bell counts.
 *
 * Unread notifications and outstanding setup both mean "there is something
 * here for you"; splitting them into two indicators would make neither worth
 * looking at.
 */
const attentionCount = computed(
    () => (notifications.value?.unread ?? 0) + attentionItems.value.length,
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

/** The same tones as the status pills, so a red here means what red means there. */
const toneChip: Record<string, string> = {
    critical: 'bg-rose-500/10 text-rose-700 dark:text-rose-300',
    warning: 'bg-orange-500/10 text-orange-700 dark:text-orange-300',
    success: 'bg-lime-500/15 text-lime-800 dark:text-lime-300',
    neutral:
        'bg-zinc-900/[0.05] text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
};

const toneIcon: Record<string, Component> = {
    critical: CircleAlert,
    warning: TriangleAlert,
    success: CircleCheck,
    neutral: Info,
};

/**
 * A row in the panel: a tab stop of its own, lit on hover and on keyboard
 * focus. The focus outline is drawn inside the row because the scrolling list
 * around it would clip one drawn outside.
 */
const itemClass =
    'flex w-full gap-3 menu-item text-left hover:bg-zinc-900/[0.04] focus-visible:bg-zinc-900/[0.04] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 dark:hover:bg-white/[0.06] dark:focus-visible:bg-white/[0.06] dark:focus-visible:outline-brand-300';

function openNotification(id: string): void {
    router.post(markNotificationRead.url({ notification: id }));
}

function markEverythingRead(): void {
    router.post(markAllNotificationsRead.url(), {}, { preserveScroll: true });
}
</script>

<template>
    <!--
        Below `sm` the panel spans the screen with a gutter on each side, so
        the button's wrapper must not be the positioned ancestor there: the
        sticky header is, and it is as wide as the screen. From `sm` up the
        panel hangs under the bell.

        A popover rather than a menu: it holds headings, copy and a button as
        well as links, and every control in it should be an ordinary tab stop.
    -->
    <Popover class="relative max-sm:static">
        <PopoverButton
            class="relative icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
        >
            <span class="sr-only">
                Ver notificações{{
                    attentionCount > 0 ? ` (${attentionCount} por ver)` : ''
                }}
            </span>
            <Bell class="size-5" aria-hidden="true" />
            <span
                v-if="attentionCount > 0"
                class="absolute top-2 right-2 size-2 rounded-full bg-accent-400 ring-2 ring-stone-50 dark:ring-zinc-950"
                aria-hidden="true"
            />
        </PopoverButton>
        <transition
            enter-active-class="transition ease-out duration-100"
            enter-from-class="scale-95 opacity-0"
            enter-to-class="scale-100 opacity-100"
            leave-active-class="transition ease-out duration-75"
            leave-from-class="scale-100 opacity-100"
            leave-to-class="scale-95 opacity-0"
        >
            <PopoverPanel
                v-slot="{ close }"
                class="absolute z-20 flex max-h-[min(34rem,calc(100dvh-6rem))] origin-top flex-col overflow-clip menu-panel max-sm:inset-x-4 max-sm:inset-bs-[calc(100%+0.5rem)] sm:inset-e-0 sm:inset-bs-full sm:mbs-2.5 sm:w-96 sm:origin-top-right"
            >
                <div class="flex shrink-0 items-baseline gap-3 px-5 pt-4 pb-3">
                    <p class="flex-1 text-[0.9375rem] font-semibold">
                        Notificações
                        <span
                            v-if="(notifications?.unread ?? 0) > 0"
                            class="ml-1 numeric text-sm font-normal text-zinc-500 dark:text-zinc-400"
                            >{{ notifications?.unread }}</span
                        >
                    </p>
                    <button
                        v-if="(notifications?.unread ?? 0) > 0"
                        type="button"
                        class="tap-target rounded-full text-xs font-medium text-zinc-500 underline-offset-4 focus-ring hover:text-zinc-900 hover:underline dark:text-zinc-400 dark:hover:text-white"
                        @click="markEverythingRead"
                    >
                        Marcar todas como lidas
                    </button>
                </div>

                <div
                    class="min-h-0 flex-1 [scrollbar-gutter:stable] overflow-y-auto overscroll-contain px-2 pb-2 [--menu-pad:0.5rem]"
                >
                    <template v-if="attentionItems.length > 0">
                        <p
                            class="px-3 pt-1 pb-1.5 eyebrow text-zinc-500 dark:text-zinc-400"
                        >
                            A precisar de decisão
                        </p>
                        <Link
                            v-for="item in attentionItems"
                            :key="item.title"
                            :href="item.href"
                            :class="[itemClass, 'py-2.5']"
                            @click="close()"
                        >
                            <span
                                :class="[
                                    toneChip.warning,
                                    'mt-0.5 grid size-8 shrink-0 place-items-center rounded-full',
                                ]"
                            >
                                <TriangleAlert
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold">{{
                                    item.title
                                }}</span>
                                <span
                                    class="mt-0.5 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                    >{{ item.detail }}</span
                                >
                            </span>
                        </Link>
                        <p
                            v-if="(notifications?.recent?.length ?? 0) > 0"
                            class="px-3 pt-3 pb-1.5 eyebrow text-zinc-500 dark:text-zinc-400"
                        >
                            Recentes
                        </p>
                    </template>

                    <button
                        v-for="entry in notifications?.recent ?? []"
                        :key="entry.id"
                        type="button"
                        :class="[itemClass, 'py-2.5']"
                        @click="
                            openNotification(entry.id);
                            close();
                        "
                    >
                        <span
                            :class="[
                                toneChip[entry.tone] ?? toneChip.neutral,
                                'mt-0.5 grid size-8 shrink-0 place-items-center rounded-full',
                            ]"
                        >
                            <component
                                :is="toneIcon[entry.tone] ?? Bell"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline gap-2">
                                <span
                                    class="min-w-0 flex-1 truncate text-sm"
                                    :class="
                                        entry.read
                                            ? 'font-medium text-zinc-700 dark:text-zinc-300'
                                            : 'font-semibold'
                                    "
                                    ><span v-if="!entry.read" class="sr-only"
                                        >Por ler. </span
                                    >{{ entry.title }}</span
                                >
                                <span
                                    class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400"
                                    >{{ relativeTime(entry.created_at) }}</span
                                >
                            </span>
                            <span
                                class="mt-0.5 line-clamp-2 block text-xs/5 text-zinc-500 dark:text-zinc-400"
                                >{{ entry.body }}</span
                            >
                        </span>
                        <span
                            v-if="!entry.read"
                            class="mt-2 size-1.5 shrink-0 rounded-full bg-accent-400"
                            aria-hidden="true"
                        />
                    </button>

                    <p
                        v-if="
                            (notifications?.recent?.length ?? 0) === 0 &&
                            attentionItems.length === 0
                        "
                        class="px-6 py-8 text-center text-xs/5 text-zinc-500 dark:text-zinc-400"
                    >
                        Ainda não há nada aqui. Avisamos quando a AGT responder,
                        quando uma factura vencer e quando uma avença gerar.
                    </p>
                </div>

                <Link
                    :href="notificationsIndex.url()"
                    class="flex shrink-0 items-center justify-center gap-1.5 border-t border-zinc-900/[0.06] px-5 py-3 text-sm font-semibold hover:bg-zinc-900/[0.04] focus-visible:bg-zinc-900/[0.04] focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-brand-600 dark:border-white/10 dark:hover:bg-white/[0.06] dark:focus-visible:bg-white/[0.06] dark:focus-visible:outline-brand-300"
                    @click="close()"
                >
                    Ver todas
                    <ArrowRight class="size-4" aria-hidden="true" />
                </Link>
            </PopoverPanel>
        </transition>
    </Popover>
</template>
