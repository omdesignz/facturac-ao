<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Check, Moon, ShieldCheck, Sun } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
        class="flex min-h-screen bg-white text-zinc-950 dark:bg-zinc-950 dark:text-white"
    >
        <CookieConsent />

        <main
            class="relative flex flex-1 flex-col justify-center px-5 py-10 sm:px-8 lg:flex-none lg:px-20 xl:px-24"
        >
            <button
                type="button"
                class="absolute top-5 right-5 rounded-xl p-2.5 text-zinc-500 ring-1 ring-zinc-200 focus-ring transition hover:bg-zinc-50 hover:text-zinc-950 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white"
                :aria-label="isDark ? 'Usar tema claro' : 'Usar tema escuro'"
                @click="toggleAppearance"
            >
                <Sun v-if="isDark" class="size-5" aria-hidden="true" />
                <Moon v-else class="size-5" aria-hidden="true" />
            </button>

            <div class="mx-auto w-full max-w-sm lg:w-96">
                <AppLogo />

                <div class="mt-10">
                    <p
                        v-if="eyebrow"
                        class="eyebrow text-brand-700 dark:text-brand-300"
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
            The panel shows a specimen of the thing the product makes rather
            than talking about it: an Angolan invoice's own footer, set in the
            mono face, ending in the acceptance everyone here is actually
            waiting for. That artifact is the one memorable element, so the
            copy around it stays plain and the surface carries no decoration
            beyond the fiscal grid the rest of the application already uses.
        -->
        <aside
            class="sticky top-0 hidden h-screen flex-1 overflow-hidden bg-brand-950 lg:block"
            aria-label="O que a aplicação faz a cada documento"
        >
            <div class="fiscal-grid absolute inset-0 opacity-40" />
            <div
                class="absolute inset-y-0 left-0 w-px bg-white/10"
                aria-hidden="true"
            />

            <div
                class="relative flex h-full flex-col justify-between gap-10 overflow-y-auto p-10 xl:p-16"
            >
                <p
                    class="inline-flex w-fit items-center gap-2 text-xs font-semibold tracking-[0.14em] text-brand-100/60 uppercase"
                >
                    <ShieldCheck
                        class="size-4 text-accent-400"
                        aria-hidden="true"
                    />
                    Facturação electrónica · Angola
                </p>

                <div class="max-w-xl">
                    <h2
                        class="text-4xl leading-[1.1] display text-white xl:text-[3.25rem]"
                    >
                        Numerado, assinado, comunicado.
                    </h2>
                    <p class="mt-6 max-w-md text-base/7 text-brand-100/65">
                        Um documento fiscal só vale se as três coisas
                        acontecerem, e por esta ordem. Atribuímos a série e o
                        número na emissão, assinamos o conteúdo tal como saiu, e
                        entregamos à AGT — se a rede falhar a meio, retomamos do
                        ponto onde parou.
                    </p>
                </div>

                <figure class="max-w-md">
                    <figcaption class="sr-only">
                        Exemplo do rodapé de uma factura emitida pela aplicação
                    </figcaption>

                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.04] p-5 font-mono text-xs/6 text-brand-100/70"
                    >
                        <div class="flex items-baseline justify-between gap-4">
                            <span class="text-sm text-white">FT 2026/1042</span>
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md bg-accent-400/15 px-2 py-1 text-[0.7rem] font-semibold text-accent-300 not-italic"
                            >
                                <Check class="size-3" aria-hidden="true" />
                                Aceite pela AGT
                            </span>
                        </div>

                        <dl
                            class="mt-4 space-y-1.5 border-t border-white/10 pt-4"
                        >
                            <div class="flex justify-between gap-4">
                                <dt>NIF do cliente</dt>
                                <dd class="text-brand-100 tabular-nums">
                                    5417 654 321
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt>IVA 14%</dt>
                                <dd class="text-brand-100 tabular-nums">
                                    14 000,00 Kz
                                </dd>
                            </div>
                            <div class="flex justify-between gap-4">
                                <dt class="text-white">Total</dt>
                                <dd
                                    class="text-sm font-semibold text-white tabular-nums"
                                >
                                    114 000,00 Kz
                                </dd>
                            </div>
                        </dl>

                        <p
                            class="mt-4 border-t border-white/10 pt-4 text-[0.7rem]/5 break-all text-brand-100/45"
                        >
                            Assinatura
                            <span class="text-brand-100/70"
                                >9f2c·a710·4be3·d885</span
                            >
                        </p>
                    </div>
                </figure>
            </div>
        </aside>
    </div>
</template>
