<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
} from '@/lib/appearance';

defineProps<{
    eyebrow?: string;
    title: string;
    description: string;
}>();

const page = usePage();

/** Reachable before sign-up, so the terms can be read before agreeing to them. */
const legalLinks = computed(() => page.props.legal.links);

const isDark = ref(false);

/** The deadline line only makes sense until the day it passes. */
const beforeMandate = new Date() < new Date(2027, 0, 1);

function refreshThemeState(): void {
    isDark.value = document.documentElement.classList.contains('dark');
}

function toggleAppearance(): void {
    const appearance = isDark.value ? 'light' : 'dark';

    storeAppearance(appearance);
    applyAppearance(appearance);
    refreshThemeState();
}

onMounted(() => {
    applyAppearance(getStoredAppearance());
    refreshThemeState();
});
</script>

<template>
    <div
        class="flex min-h-svh bg-white text-zinc-950 dark:bg-zinc-950 dark:text-white"
    >
        <a
            href="#conteudo"
            class="sr-only focus-ring focus:not-sr-only focus:fixed focus:inset-s-3 focus:inset-bs-3 focus:z-[100] focus:rounded-full focus:bg-white focus:px-4 focus:py-2 focus:text-sm focus:font-semibold dark:focus:bg-zinc-900"
        >
            Saltar para o conteúdo
        </a>

        <CookieConsent />

        <main
            id="conteudo"
            tabindex="-1"
            class="relative flex flex-1 flex-col justify-center px-5 py-10 focus:outline-hidden sm:px-8 lg:flex-none lg:px-20 xl:px-24"
        >
            <button
                type="button"
                class="absolute inset-e-5 inset-bs-5 grid size-10 place-items-center rounded-full text-zinc-500 ring-1 ring-zinc-900/10 focus-ring transition hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white pointer-coarse:size-11"
                :aria-label="isDark ? 'Usar tema claro' : 'Usar tema escuro'"
                @click="toggleAppearance"
            >
                <Sun class="hidden size-5 dark:block" aria-hidden="true" />
                <Moon class="size-5 dark:hidden" aria-hidden="true" />
            </button>

            <div class="mx-auto w-full max-w-sm lg:w-96">
                <AppLogo />

                <div class="mt-10">
                    <p
                        v-if="eyebrow"
                        class="eyebrow text-zinc-500 dark:text-zinc-400"
                    >
                        {{ eyebrow }}
                    </p>
                    <h1
                        class="mt-2 text-3xl display text-zinc-950 dark:text-white"
                    >
                        {{ title }}
                    </h1>
                    <p class="mt-3 text-sm/6 text-zinc-500 dark:text-zinc-400">
                        {{ description }}
                    </p>
                </div>

                <div class="mt-8">
                    <slot />
                </div>

                <div class="mt-8 text-sm/6 text-zinc-500 dark:text-zinc-400">
                    <slot name="footer" />
                </div>

                <nav
                    class="mt-10 flex flex-wrap gap-x-4 gap-y-2 border-t border-zinc-100 pt-6 text-xs text-zinc-500 dark:border-white/10 dark:text-zinc-500"
                    aria-label="Documentos legais"
                >
                    <Link
                        v-for="link in legalLinks"
                        :key="link.slug"
                        :href="`/${link.slug}`"
                        class="rounded focus-ring transition hover:text-zinc-950 dark:hover:text-white"
                    >
                        {{ link.label }}
                    </Link>
                </nav>
            </div>
        </main>

        <!--
            The brand moment: the name explains itself. "facturação" loses its
            tilde and cedilla and becomes the domain, which is also how to type
            it. Underneath, a specimen of what the product makes, as paper.
        -->
        <aside
            class="sticky top-0 hidden h-dvh flex-1 overflow-clip bg-zinc-900/[0.035] lg:block dark:bg-white/[0.03]"
            aria-label="O que a aplicação faz a cada documento"
        >
            <div
                class="relative flex h-full flex-col justify-between gap-10 overflow-y-auto p-10 xl:p-16"
            >
                <p
                    class="text-[0.6875rem] font-medium tracking-[0.07em] text-zinc-500 uppercase dark:text-zinc-400"
                >
                    Facturação electrónica · Angola
                </p>

                <div class="max-w-xl">
                    <BrandWordmark
                        animated
                        title="facturação transforma-se em facturac.ao"
                        class="h-auto w-full max-w-md text-brand-950 dark:text-white"
                    />
                    <p
                        class="mt-8 text-xl tracking-[-0.02em] text-zinc-950 dark:text-white"
                    >
                        Tire o til. Tire a cedilha. Ponha um ponto.
                    </p>
                    <p
                        class="mt-3 max-w-md text-[0.9375rem]/7 text-zinc-600 dark:text-zinc-400"
                    >
                        Cada documento é numerado, assinado e comunicado à AGT,
                        por esta ordem. Se a rede falhar a meio, retomamos do
                        ponto onde parou.
                        <template v-if="beforeMandate">
                            A partir de 1 de janeiro de 2027, todas as empresas
                            facturam assim.
                        </template>
                    </p>
                </div>

                <figure class="max-w-sm">
                    <figcaption class="sr-only">
                        Exemplo do rodapé de uma factura emitida pela aplicação
                    </figcaption>
                    <div
                        class="rounded-md bg-white p-5 text-xs/6 text-zinc-600 shadow-[0_2px_6px_rgb(23_23_22/0.06),0_40px_70px_-36px_rgb(23_23_22/0.38)] ring-1 ring-zinc-900/5"
                    >
                        <div class="flex items-center justify-between gap-4">
                            <span
                                class="font-mono text-sm font-medium text-zinc-950"
                                >FT 2026/1042</span
                            >
                            <span
                                class="inline-flex h-[1.375rem] items-center rounded-full bg-lime-300 px-2 text-xs font-semibold text-lime-950"
                                >Validada</span
                            >
                        </div>
                        <dl
                            class="mt-4 space-y-1.5 border-t border-zinc-100 pt-4"
                        >
                            <div class="flex justify-between gap-4">
                                <dt>NIF do cliente</dt>
                                <dd class="font-mono text-zinc-800">
                                    5417 654 321
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>IVA 14%</dt>
                                <dd class="numeric text-zinc-800">
                                    14 000,00 Kz
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-zinc-950">Total</dt>
                                <dd
                                    class="numeric text-sm font-semibold text-zinc-950"
                                >
                                    114 000,00 Kz
                                </dd>
                            </div>
                        </dl>
                        <p
                            class="mt-4 border-t border-zinc-100 pt-4 font-mono text-[0.7rem]/5 break-all text-zinc-400"
                        >
                            Assinatura
                            <span class="text-zinc-600"
                                >9f2c·a710·4be3·d885</span
                            >
                        </p>
                    </div>
                </figure>
            </div>
        </aside>
    </div>
</template>
