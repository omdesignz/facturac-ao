<script setup lang="ts">
import {
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { ref } from 'vue';
import AppHeader from '@/components/AppHeader.vue';
import AppRail from '@/components/AppRail.vue';
import ConfirmDialog from '@/components/ConfirmDialog.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import SidebarNavigation from '@/components/SidebarNavigation.vue';
import TermsReacceptanceNotice from '@/components/TermsReacceptanceNotice.vue';

const sidebarOpen = ref(false);
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
                    <div class="fixed inset-0 dialog-scrim" />
                </TransitionChild>

                <div
                    class="fixed inset-0 flex p-2 pt-[max(0.5rem,env(safe-area-inset-top))] pb-[max(0.5rem,env(safe-area-inset-bottom))]"
                >
                    <TransitionChild
                        as="template"
                        enter="transition ease-in-out duration-300 transform"
                        enter-from="-translate-x-[105%]"
                        enter-to="translate-x-0"
                        leave="transition ease-in-out duration-300 transform"
                        leave-from="translate-x-0"
                        leave-to="-translate-x-[105%]"
                    >
                        <DialogPanel
                            class="flex w-full max-w-[20rem] overflow-hidden dialog-panel"
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
            class="hidden lg:fixed lg:inset-y-0 lg:z-50 lg:flex lg:w-[4.5rem] lg:flex-col"
        >
            <AppRail />
        </div>

        <div class="lg:pl-[4.5rem]">
            <AppHeader @open-navigation="sidebarOpen = true" />

            <TermsReacceptanceNotice />

            <main class="min-h-[calc(100vh-4rem)]">
                <slot />
            </main>
        </div>
    </div>
</template>
