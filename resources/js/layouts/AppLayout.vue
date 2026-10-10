<script lang="ts">
/** The component and path of the page last shown, shared by every layout instance. */
let lastVisit: string | null = null;
</script>

<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { router, usePage } from '@inertiajs/vue3';
import { useMediaQuery } from '@vueuse/core';
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppRail from '@/components/AppRail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SidebarNavigation from '@/components/SidebarNavigation.vue';
import TermsReacceptanceNotice from '@/components/TermsReacceptanceNotice.vue';

const sidebarOpen = ref(false);

/** The drawer is `lg:hidden`, so a widened window must also release its scroll lock. */
const isDesktop = useMediaQuery('(min-width: 64rem)');

watch(isDesktop, (matches) => {
    if (matches) {
        sidebarOpen.value = false;
    }
});

/*
 * After a real page change, focus moves to <main> so a keyboard or screen
 * reader user starts at the new content instead of on a link that is gone.
 * Pages wrap themselves in this layout, so it is remounted on most visits:
 * the last visit is kept outside the component to survive that.
 */
const page = usePage();
const main = ref<HTMLElement | null>(null);

function visitKey(component: string, url: string): string {
    return `${component}\u0000${new URL(url, window.location.origin).pathname}`;
}

function noteVisit(component: string, url: string): void {
    const key = visitKey(component, url);
    const changed = lastVisit !== null && lastVisit !== key;

    lastVisit = key;

    if (!changed) {
        return;
    }

    void nextTick(() => {
        const active = document.activeElement;

        // An autofocused field, or any control the page already placed focus
        // on, is better than <main>; a clicked link that has just left the
        // document leaves focus on <body>.
        if (
            active !== null &&
            active !== document.body &&
            document.body.contains(active)
        ) {
            return;
        }

        main.value?.focus({ preventScroll: true });
    });
}

let stopListening: (() => void) | undefined;

onMounted(() => {
    noteVisit(page.component, page.url);

    stopListening = router.on('navigate', (event) => {
        noteVisit(event.detail.page.component, event.detail.page.url);
    });
});

onBeforeUnmount(() => stopListening?.());
</script>

<template>
    <div
        class="min-h-dvh bg-stone-50 text-zinc-950 dark:bg-zinc-950 dark:text-white"
    >
        <a
            href="#conteudo"
            class="sr-only focus-ring focus:not-sr-only focus:fixed focus:inset-s-3 focus:inset-bs-3 focus:z-[100] focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold dark:focus:bg-zinc-900"
        >
            Saltar para o conteúdo
        </a>

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
                aria-label="Navegação"
                @close="sidebarOpen = false"
            >
                <TransitionChild
                    as="template"
                    enter="ease-out duration-200"
                    enter-from="opacity-0"
                    enter-to="opacity-100"
                    leave="ease-out duration-150"
                    leave-from="opacity-100"
                    leave-to="opacity-0"
                >
                    <div class="fixed inset-0 dialog-scrim" />
                </TransitionChild>

                <div
                    class="fixed inset-0 flex p-2 pt-[max(0.5rem,env(safe-area-inset-top))] pb-[max(0.5rem,env(safe-area-inset-bottom))]"
                >
                    <TransitionChild
                        as="template"
                        enter="transition-transform ease-out duration-200"
                        enter-from="-translate-x-[105%]"
                        enter-to="translate-x-0"
                        leave="transition-transform ease-out duration-150"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-[105%]"
                    >
                        <DialogPanel
                            class="flex w-full max-w-[20rem] overflow-clip dialog-panel"
                        >
                            <SidebarNavigation
                                @navigate="sidebarOpen = false"
                                @close="sidebarOpen = false"
                            />
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>

        <div
            class="hidden lg:fixed lg:inset-bs-[var(--impersonation-bar,0px)] lg:inset-be-0 lg:z-50 lg:flex lg:w-[4.5rem] lg:flex-col"
        >
            <AppRail />
        </div>

        <div class="lg:pl-[4.5rem]">
            <AppHeader @open-navigation="sidebarOpen = true" />

            <TermsReacceptanceNotice />

            <main
                id="conteudo"
                ref="main"
                tabindex="-1"
                class="min-h-[calc(100dvh-4rem)] focus:outline-hidden"
            >
                <slot />
            </main>
        </div>
    </div>
</template>
