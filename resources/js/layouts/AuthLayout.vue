<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Moon, Sun } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import ConnectionNotice from '@/components/ConnectionNotice.vue';
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

const year = new Date().getFullYear();

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

    if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
        document
            .getElementById('conteudo')
            ?.querySelector<HTMLInputElement>('[data-auth-autofocus]')
            ?.focus();
    }
});
</script>

<template>
    <div
        class="flex min-h-svh gap-4 bg-[var(--surface-page)] p-4 text-[var(--ui-ink)]"
    >
        <ConnectionNotice />
        <a
            href="#conteudo"
            class="sr-only focus-ring focus:not-sr-only focus:fixed focus:inset-s-3 focus:inset-bs-3 focus:z-[100] focus:rounded-lg focus:bg-[var(--surface-raised)] focus:px-4 focus:py-2 focus:text-sm focus:font-semibold"
        >
            Saltar para o conteúdo
        </a>

        <CookieConsent />

        <main
            id="conteudo"
            tabindex="-1"
            class="relative flex min-h-[calc(100svh-2rem)] min-w-0 flex-[0.75_1_24rem] justify-center px-5 py-6 focus:outline-hidden sm:px-8 sm:py-7"
        >
            <button
                type="button"
                class="absolute inset-e-2 inset-bs-2 grid size-9 place-items-center rounded-lg text-[var(--ui-ink-3)] focus-ring transition-colors hover:bg-[var(--ui-wash)] hover:text-[var(--ui-ink)] pointer-coarse:size-11"
                :aria-label="isDark ? 'Usar tema claro' : 'Usar tema escuro'"
                @click="toggleAppearance"
            >
                <Sun class="hidden size-4 dark:block" aria-hidden="true" />
                <Moon class="size-4 dark:hidden" aria-hidden="true" />
            </button>

            <div class="flex w-full max-w-[25rem] flex-col">
                <Link
                    href="/"
                    class="w-fit rounded focus-ring"
                    aria-label="facturac.ao, página inicial"
                >
                    <BrandWordmark class="h-[1.0625rem] w-auto" />
                </Link>

                <div class="flex flex-1 flex-col justify-center py-12">
                    <span
                        class="grid size-10 place-items-center rounded-xl bg-[#161616] shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_0_0_1px_rgb(16_16_16/0.08),0_2px_6px_rgb(16_16_16/0.12)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_0_0_1px_rgb(255_255_255/0.1)]"
                        aria-hidden="true"
                    >
                        <span
                            class="size-2.5 rounded-full bg-accent-400 shadow-[0_0_0_3px_rgb(249_178_51/0.18)]"
                        />
                    </span>
                    <p
                        v-if="eyebrow"
                        class="mt-[1.375rem] text-[0.8125rem] font-medium text-[var(--ui-ink-3)]"
                    >
                        {{ eyebrow }}
                    </p>
                    <h1
                        class="text-[1.625rem] leading-[1.2] font-[650] tracking-[-0.03em] text-[var(--ui-ink)]"
                        :class="eyebrow ? 'mt-1' : 'mt-[1.375rem]'"
                    >
                        {{ title }}
                    </h1>
                    <p class="mt-2 text-sm/[1.5] text-[var(--ui-ink-2)]">
                        {{ description }}
                    </p>

                    <div class="mt-[1.875rem]">
                        <slot />
                    </div>

                    <div
                        class="mt-8 border-t border-[var(--ui-line)] pt-5 text-[0.8125rem] text-[var(--ui-ink-2)] empty:hidden"
                    >
                        <slot name="footer" />
                    </div>
                </div>

                <nav
                    class="flex flex-wrap gap-x-[1.125rem] gap-y-2 text-xs text-[var(--ui-ink-3)]"
                    aria-label="Documentos legais"
                >
                    <span>© {{ year }} VAP Soluções</span>
                    <Link
                        v-for="link in legalLinks"
                        :key="link.slug"
                        :href="`/${link.slug}`"
                        class="rounded focus-ring transition-colors hover:text-[var(--ui-ink)]"
                    >
                        {{ link.label }}
                    </Link>
                </nav>
            </div>
        </main>

        <aside
            class="hidden min-w-0 flex-[1.5_1_40rem] flex-col justify-center gap-12 rounded-xl bg-[var(--surface-side)] px-8 py-12 lg:flex xl:px-12"
            aria-label="Sobre a facturac.ao"
        >
            <div class="flex flex-col gap-3">
                <h2
                    class="text-[1.625rem] leading-[1.2] font-[650] tracking-[-0.03em] text-balance"
                >
                    O seu negócio, em dia.
                </h2>
                <p class="max-w-lg text-base/6 text-[var(--ui-ink-2)]">
                    Facture, acompanhe pagamentos e prepare cada decisão.
                </p>
            </div>
            <figure class="flex flex-col gap-6">
                <div
                    class="relative aspect-[8/5] overflow-clip rounded-xl bg-[var(--surface-raised)] shadow-[var(--ui-card-shadow)]"
                >
                    <!-- Captured at 2x in the frame's own 8:5 shape, at a width
                         close to the one it is shown at, so text is drawn
                         from real pixels rather than shrunk to a smudge.
                         Lazy, so the theme that is hidden never downloads. -->
                    <img
                        src="/images/auth/today-light-1200.webp"
                        srcset="
                            /images/auth/today-light-1200.webp 1200w,
                            /images/auth/today-light-2400.webp 2400w
                        "
                        sizes="(min-width: 1024px) 55vw, 1px"
                        width="2400"
                        height="1500"
                        alt="O ecrã Hoje da empresa de demonstração: facturação, pagamentos por receber e decisões pendentes."
                        class="absolute inset-0 block size-full object-cover object-top dark:hidden"
                        loading="lazy"
                        decoding="async"
                    />
                    <img
                        src="/images/auth/today-dark-1200.webp"
                        srcset="
                            /images/auth/today-dark-1200.webp 1200w,
                            /images/auth/today-dark-2400.webp 2400w
                        "
                        sizes="(min-width: 1024px) 55vw, 1px"
                        width="2400"
                        height="1500"
                        alt="O ecrã Hoje da empresa de demonstração em tema escuro."
                        class="absolute inset-0 hidden size-full object-cover object-top dark:block"
                        loading="lazy"
                        decoding="async"
                    />
                </div>
                <figcaption class="text-center text-xs text-[var(--ui-ink-3)]">
                    Empresa de demonstração. Os valores não são reais.
                </figcaption>
            </figure>
        </aside>
    </div>
</template>
