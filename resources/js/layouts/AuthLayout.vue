<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { BellRing, HandCoins, Moon, ShieldCheck, Sun } from '@lucide/vue';
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
});

/** What the product does, in the order someone meets it. */
const points = [
    {
        icon: ShieldCheck,
        text: 'Cada factura é assinada e entregue à AGT por si.',
    },
    { icon: HandCoins, text: 'Vê quem lhe deve, e há quanto tempo.' },
    {
        icon: BellRing,
        text: 'Os lembretes ficam prontos; só saem quando confirmar.',
    },
];
</script>

<template>
    <div
        class="flex min-h-svh gap-2.5 bg-[var(--surface-page)] p-2.5 text-[var(--ui-ink)]"
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
            class="relative flex min-h-[calc(100svh-1.25rem)] min-w-0 flex-[1_1_28.75rem] justify-center px-5 py-6 focus:outline-hidden sm:px-8 sm:py-7"
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

            <div class="flex w-full max-w-[23rem] flex-col">
                <Link
                    href="/"
                    class="w-fit rounded focus-ring"
                    aria-label="facturac.ao, página inicial"
                >
                    <BrandWordmark class="h-[1.0625rem] w-auto" />
                </Link>

                <div class="flex flex-1 flex-col justify-center py-14">
                    <span
                        class="grid size-10 place-items-center rounded-[10px] bg-[#161616] shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_0_0_1px_rgb(16_16_16/0.08),0_2px_6px_rgb(16_16_16/0.12)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.12),0_0_0_1px_rgb(255_255_255/0.1)]"
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
                        class="text-[1.625rem] leading-[1.15] font-[650] tracking-[-0.03em] text-[var(--ui-ink)]"
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

        <!--
            The product as it is: a real screen from a demonstration company,
            on a dark panel in either theme, under three plain lines on what it
            does. Never a drawing of the interface.
        -->
        <aside
            class="relative hidden min-h-[calc(100svh-1.25rem)] min-w-0 flex-[1.15_1_35rem] flex-col overflow-clip rounded-[14px] bg-[#0b0b0d] px-16 pt-16 text-[#ededef] shadow-[inset_0_0_0_1px_rgb(255_255_255/0.06)] lg:flex"
            aria-label="Sobre a facturac.ao"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 bg-[linear-gradient(rgb(255_255_255/0.022)_1px,transparent_1px),linear-gradient(90deg,rgb(255_255_255/0.022)_1px,transparent_1px)] [mask-image:radial-gradient(ellipse_80%_60%_at_30%_0%,#000_0%,transparent_75%)] bg-[length:56px_56px] bg-[position:-1px_-1px]"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute start-1/2 -inset-be-[16.25rem] h-[32.5rem] w-[56.25rem] -translate-x-1/2 rounded-full bg-accent-400 opacity-[0.07] blur-[120px]"
            />

            <div class="relative flex max-w-[30rem] flex-col">
                <p
                    class="inline-flex h-[1.625rem] items-center gap-[0.4375rem] self-start rounded-[7px] bg-white/[0.04] ps-2 pe-2.5 text-xs font-[550] text-[#c9c9ce] shadow-[inset_0_0_0_1px_rgb(255_255_255/0.08)]"
                >
                    <ShieldCheck
                        class="size-3.5 text-[#5fd39a]"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    Software certificado pela AGT · FE/419/AGT/2026
                </p>
                <p
                    class="mt-6 text-[2.125rem] leading-[1.12] font-[650] tracking-[-0.035em] text-balance"
                >
                    Facture, cobre e fique em dia com a AGT.
                    <span class="text-[#7a7a82]">Sem papelada.</span>
                </p>
                <ul role="list" class="mt-8 flex flex-col gap-3.5">
                    <li
                        v-for="point in points"
                        :key="point.text"
                        class="flex items-center gap-3 text-sm text-[#b4b4ba]"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-[7px] bg-white/[0.04] text-accent-400 shadow-[inset_0_0_0_1px_rgb(255_255_255/0.08)]"
                            aria-hidden="true"
                        >
                            <component
                                :is="point.icon"
                                class="size-[0.9375rem]"
                                :stroke-width="1.8"
                            />
                        </span>
                        {{ point.text }}
                    </li>
                </ul>
            </div>

            <figure class="relative mt-14 min-h-[23.75rem] flex-1">
                <div
                    class="absolute inset-s-0 inset-bs-0 w-[48.75rem] overflow-clip rounded-t-xl bg-[#09090a] [mask-image:linear-gradient(180deg,#000_55%,transparent_100%)] shadow-[0_0_0_1px_rgb(255_255_255/0.09),0_-24px_80px_rgb(0_0_0/0.55)]"
                >
                    <img
                        src="/images/auth/today-dark-1440.webp"
                        srcset="
                            /images/auth/today-dark-1440.webp 1440w,
                            /images/auth/today-dark-2880.webp 2880w
                        "
                        sizes="780px"
                        width="1440"
                        height="900"
                        alt="O ecrã Hoje da facturac.ao: facturado nos últimos 30 dias, por receber, em atraso, facturação diária e o que precisa de decisão."
                        class="block w-full"
                        loading="lazy"
                        decoding="async"
                    />
                </div>
                <figcaption
                    class="absolute inset-s-0 inset-be-4 text-[0.6875rem] text-[#6a6a72]"
                >
                    Empresa de demonstração. Os valores não são reais.
                </figcaption>
            </figure>
        </aside>
    </div>
</template>
