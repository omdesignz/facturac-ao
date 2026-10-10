<script setup lang="ts">
import { Menu, MenuButton, MenuItem, MenuItems } from '@headlessui/vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Check, LifeBuoy } from '@lucide/vue';
import { onClickOutside, useMediaQuery } from '@vueuse/core';
import { computed, onBeforeUnmount, ref } from 'vue';
import BrandMark from '@/components/BrandMark.vue';
import RailTooltip from '@/components/RailTooltip.vue';
import { useNavigation } from '@/lib/navigation';
import type { NavigationGroup } from '@/lib/navigation';
import { dashboard } from '@/routes';
import { index as helpIndex } from '@/routes/help';
import { update as switchWorkspace } from '@/routes/workspace/current';

/**
 * The desktop navigation: a 72px rail with one icon per place.
 *
 * A click goes to the place's main page; hovering or focusing an icon opens a
 * flyout with every page that lives there, so nothing is more than one step
 * away and the content keeps the width it needs. Places with a single page
 * are plain links with a tooltip.
 *
 * Touch has no hover, so where the pointer is coarse a place with several
 * pages is a button that opens its flyout instead of going to the first page.
 */
const page = usePage();
const { groups, activeGroup, activeItem } = useNavigation();

const currentWorkspace = computed(() => page.props.currentWorkspace);
const legalEntity = computed(() => currentWorkspace.value?.legal_entity);

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

/* ---------------------------------------------------------------- flyouts */

/** How long the pointer must rest on an icon before its flyout opens. */
const HOVER_INTENT_MS = 60;

const openGroup = ref<string | null>(null);
const isTouchFirst = useMediaQuery('(pointer: coarse)');
const navigation = ref<HTMLElement | null>(null);
let closeTimer: number | undefined;
let restoringFocus = false;
let intentTimer: number | undefined;

function cancelClose(): void {
    if (closeTimer !== undefined) {
        window.clearTimeout(closeTimer);
        closeTimer = undefined;
    }
}

function cancelIntent(): void {
    if (intentTimer !== undefined) {
        window.clearTimeout(intentTimer);
        intentTimer = undefined;
    }
}

function open(group: NavigationGroup): void {
    cancelClose();
    cancelIntent();
    openGroup.value = group.items.length > 1 ? group.key : null;
}

function toggle(group: NavigationGroup): void {
    cancelClose();
    cancelIntent();
    openGroup.value = openGroup.value === group.key ? null : group.key;
}

/** A short grace period, so the pointer can travel from the icon to the panel. */
function scheduleClose(): void {
    cancelClose();
    closeTimer = window.setTimeout(() => {
        openGroup.value = null;
    }, 140);
}

/**
 * Only a mouse hovers. Touch and pen send pointerenter too, but with nothing
 * to leave they would leave the flyout stuck open.
 *
 * Once one flyout is open the next follows at once; the first waits a moment
 * so crossing the rail on the way somewhere else does not flash menus.
 */
function onPointerEnter(event: PointerEvent, group: NavigationGroup): void {
    if (event.pointerType !== 'mouse') {
        return;
    }

    if (openGroup.value !== null || group.items.length < 2) {
        open(group);

        return;
    }

    cancelClose();
    cancelIntent();
    intentTimer = window.setTimeout(() => open(group), HOVER_INTENT_MS);
}

function onPointerLeave(event: PointerEvent): void {
    if (event.pointerType !== 'mouse') {
        return;
    }

    cancelIntent();
    scheduleClose();
}

/** Keyboard focus opens at once; focus left behind by a tap or click does not. */
function onFocusIn(event: FocusEvent, group: NavigationGroup): void {
    const origin = event.target;

    if (
        !restoringFocus &&
        origin instanceof HTMLElement &&
        origin.matches(':focus-visible')
    ) {
        open(group);
    }
}

/**
 * A touch on a link that would otherwise skip the flyout, as on a laptop with
 * a touch screen whose primary pointer is still the mouse. Keyboard and mouse
 * clicks go through to the page.
 */
function interceptTouch(event: MouseEvent, group: NavigationGroup): void {
    if (
        group.items.length < 2 ||
        !(event instanceof PointerEvent) ||
        event.pointerType === 'mouse' ||
        event.pointerType === ''
    ) {
        return;
    }

    event.preventDefault();
    toggle(group);
}

function closeOnFocusLeave(event: FocusEvent): void {
    const container = event.currentTarget;
    const next = event.relatedTarget;

    if (
        container instanceof HTMLElement &&
        next instanceof Node &&
        container.contains(next)
    ) {
        return;
    }

    openGroup.value = null;
}

function closeOnEscape(
    event: KeyboardEvent,
    trigger: HTMLElement | null,
): void {
    if (event.key !== 'Escape' || openGroup.value === null) {
        return;
    }

    openGroup.value = null;

    // Handing focus back to the icon must not read as focus arriving there.
    restoringFocus = true;
    trigger?.focus();
    restoringFocus = false;
}

/** A tap anywhere else puts the flyout away, which a mouse does by leaving. */
onClickOutside(navigation, () => {
    openGroup.value = null;
});

const triggers = ref<Record<string, HTMLElement | null>>({});

function registerTrigger(key: string, element: unknown): void {
    const candidate =
        element && typeof element === 'object' && '$el' in element
            ? (element as { $el: unknown }).$el
            : element;

    triggers.value[key] = candidate instanceof HTMLElement ? candidate : null;
}

function flyoutId(group: NavigationGroup): string {
    return `rail-flyout-${group.key}`;
}

function triggerClass(group: NavigationGroup): Array<string> {
    return [
        activeGroup.value?.key === group.key
            ? 'bg-zinc-900/[0.06] text-zinc-950 dark:bg-white/10 dark:text-white'
            : 'text-zinc-400 hover:bg-zinc-900/[0.04] hover:text-zinc-900 dark:text-zinc-500 dark:hover:bg-white/5 dark:hover:text-white',
        'grid size-10 place-items-center rounded-xl focus-ring transition pointer-coarse:size-11',
    ];
}

onBeforeUnmount(() => {
    cancelClose();
    cancelIntent();
});
</script>

<template>
    <div
        data-rail
        class="flex h-full flex-col items-center gap-1 border-r border-zinc-900/[0.07] bg-white py-5 dark:border-white/10 dark:bg-zinc-950 [@media(max-height:46rem)]:gap-0.5 [@media(max-height:46rem)]:py-3"
    >
        <Link
            :href="dashboard.url()"
            class="mb-5 grid size-10 place-items-center rounded-xl bg-brand-950 text-white focus-ring transition hover:bg-brand-800 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white pointer-coarse:size-11 [@media(max-height:46rem)]:mb-3"
        >
            <BrandMark class="h-[1.05rem] w-auto" title="facturac.ao, painel" />
        </Link>

        <Menu as="div" class="relative mb-4 [@media(max-height:46rem)]:mb-2">
            <RailTooltip
                v-slot="{ describedby }"
                :label="displayName"
                :detail="workspaceDetail"
            >
                <MenuButton
                    class="grid size-10 place-items-center rounded-xl bg-accent-400 text-[0.8125rem] font-bold text-brand-950 focus-ring transition hover:bg-accent-300 pointer-coarse:size-11"
                    :aria-label="`Mudar de espaço de trabalho. Actual: ${displayName}`"
                    :aria-describedby="describedby"
                >
                    <span aria-hidden="true">{{ initials(displayName) }}</span>
                </MenuButton>
            </RailTooltip>
            <transition
                enter-active-class="transition ease-out duration-100"
                enter-from-class="scale-95 opacity-0"
                enter-to-class="scale-100 opacity-100"
                leave-active-class="transition ease-out duration-75"
                leave-from-class="scale-100 opacity-100"
                leave-to-class="scale-95 opacity-0"
            >
                <MenuItems
                    class="absolute top-0 left-[calc(100%+0.75rem)] z-50 max-h-[min(24rem,calc(100dvh-2rem))] w-64 origin-top-left overflow-y-auto overscroll-contain menu-panel p-1.5"
                >
                    <p
                        class="px-2.5 pt-1.5 pb-2 text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
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

        <nav
            ref="navigation"
            class="flex w-full flex-1 flex-col items-center"
            aria-label="Navegação principal"
        >
            <ul
                role="list"
                class="flex flex-col items-center gap-1 [@media(max-height:46rem)]:gap-0.5"
            >
                <li
                    v-for="group in groups"
                    :key="group.key"
                    class="relative"
                    @pointerenter="onPointerEnter($event, group)"
                    @pointerleave="onPointerLeave"
                    @focusin="onFocusIn($event, group)"
                    @focusout="closeOnFocusLeave"
                    @keydown="
                        closeOnEscape($event, triggers[group.key] ?? null)
                    "
                >
                    <span
                        v-if="activeGroup?.key === group.key"
                        class="absolute top-3 -left-4 h-4 w-[3px] rounded-r-full bg-accent-400"
                        aria-hidden="true"
                    />
                    <RailTooltip
                        v-slot="{ describedby }"
                        :label="group.name"
                        :disabled="group.items.length > 1"
                    >
                        <button
                            v-if="isTouchFirst && group.items.length > 1"
                            :ref="
                                (element) => registerTrigger(group.key, element)
                            "
                            type="button"
                            aria-haspopup="true"
                            :aria-label="group.name"
                            :aria-current="
                                activeGroup?.key === group.key
                                    ? 'page'
                                    : undefined
                            "
                            :aria-expanded="openGroup === group.key"
                            :aria-controls="
                                openGroup === group.key
                                    ? flyoutId(group)
                                    : undefined
                            "
                            :class="triggerClass(group)"
                            @click="toggle(group)"
                        >
                            <component
                                :is="group.icon"
                                class="size-5"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                        </button>
                        <Link
                            v-else
                            :ref="
                                (element) => registerTrigger(group.key, element)
                            "
                            :href="group.href"
                            :prefetch="group.prefetch !== false"
                            :aria-label="group.name"
                            :aria-current="
                                activeGroup?.key === group.key
                                    ? 'page'
                                    : undefined
                            "
                            :aria-describedby="describedby"
                            :class="triggerClass(group)"
                            @click.capture="interceptTouch($event, group)"
                        >
                            <component
                                :is="group.icon"
                                class="size-5"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                        </Link>
                    </RailTooltip>

                    <!--
                        The padding on the left bridges the gap to the icon, so
                        moving the pointer across it never closes the panel.
                    -->
                    <div
                        v-if="openGroup === group.key"
                        :id="flyoutId(group)"
                        class="absolute top-0 left-full z-50 pl-3"
                    >
                        <div
                            class="max-h-[calc(100dvh-1.5rem)] w-60 overflow-y-auto overscroll-contain menu-panel p-1.5"
                        >
                            <p
                                class="px-2.5 pt-1.5 pb-1.5 text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                            >
                                {{ group.name }}
                            </p>
                            <ul role="list" :aria-label="group.name">
                                <li
                                    v-for="item in group.items"
                                    :key="item.name"
                                >
                                    <Link
                                        :href="item.href"
                                        :prefetch="item.prefetch !== false"
                                        :aria-current="
                                            activeItem === item
                                                ? 'page'
                                                : undefined
                                        "
                                        :class="[
                                            activeItem === item
                                                ? 'bg-zinc-900/[0.05] font-semibold text-zinc-950 dark:bg-white/10 dark:text-white'
                                                : 'text-zinc-700 hover:bg-zinc-900/[0.04] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white',
                                            'flex h-9 items-center gap-2.5 menu-item text-sm focus-ring transition pointer-coarse:h-11',
                                        ]"
                                        @click="openGroup = null"
                                    >
                                        <component
                                            :is="item.icon"
                                            :class="[
                                                activeItem === item
                                                    ? 'text-accent-600 dark:text-accent-400'
                                                    : 'text-zinc-400',
                                                'size-4 shrink-0',
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
                        </div>
                    </div>
                </li>
            </ul>

            <div class="mt-auto">
                <RailTooltip
                    v-slot="{ describedby }"
                    label="Ajuda e reclamações"
                >
                    <Link
                        :href="helpIndex.url()"
                        aria-label="Ajuda e reclamações"
                        :aria-describedby="describedby"
                        class="grid size-10 place-items-center rounded-xl text-zinc-400 focus-ring transition hover:bg-zinc-900/[0.04] hover:text-zinc-900 dark:text-zinc-500 dark:hover:bg-white/5 dark:hover:text-white pointer-coarse:size-11"
                    >
                        <LifeBuoy
                            class="size-5"
                            :stroke-width="1.75"
                            aria-hidden="true"
                        />
                    </Link>
                </RailTooltip>
            </div>
        </nav>
    </div>
</template>
