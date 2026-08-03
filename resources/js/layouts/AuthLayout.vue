<script setup lang="ts">
import { Moon, ShieldCheck, Sun } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
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
        <main
            class="relative flex flex-1 flex-col justify-center px-5 py-10 sm:px-8 lg:flex-none lg:px-20 xl:px-24"
        >
            <button
                type="button"
                class="absolute top-5 right-5 rounded-xl p-2.5 text-zinc-500 ring-1 ring-zinc-200 transition hover:bg-zinc-50 hover:text-zinc-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-600 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-white/5 dark:hover:text-white"
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
                        class="text-xs font-semibold tracking-[0.18em] text-brand-700 uppercase dark:text-brand-300"
                    >
                        {{ eyebrow }}
                    </p>
                    <h1
                        class="mt-2 font-display text-3xl font-semibold tracking-tight text-zinc-950 dark:text-white"
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
            </div>
        </main>

        <aside
            class="relative hidden min-h-screen flex-1 overflow-hidden bg-brand-950 lg:block"
            aria-label="Protecção da plataforma"
        >
            <div class="fiscal-grid absolute inset-0 opacity-45" />
            <div
                class="absolute -top-40 -right-40 size-[34rem] rounded-full bg-brand-500/25 blur-3xl"
            />
            <div
                class="absolute -bottom-48 -left-40 size-[36rem] rounded-full bg-amber-300/15 blur-3xl"
            />

            <div
                class="relative flex h-full min-h-screen flex-col justify-between p-12 xl:p-16"
            >
                <div
                    class="inline-flex w-fit items-center gap-2 rounded-full border border-white/10 bg-white/[0.06] px-3 py-1.5 text-xs font-semibold text-brand-100"
                >
                    <ShieldCheck
                        class="size-4 text-amber-300"
                        aria-hidden="true"
                    />
                    Fundação segura · Fase 1
                </div>

                <div class="max-w-xl">
                    <p
                        class="font-display text-4xl leading-tight font-semibold tracking-tight text-white xl:text-5xl"
                    >
                        A identidade fiscal começa com uma fronteira segura.
                    </p>
                    <p class="mt-6 max-w-lg text-base/7 text-brand-100/70">
                        Cada empresa, utilizador e estabelecimento permanece no
                        contexto certo, com verificação de email, MFA e um rasto
                        de auditoria desde o primeiro acesso.
                    </p>
                </div>

                <div class="grid max-w-xl grid-cols-3 gap-3 text-brand-100/75">
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.05] p-4"
                    >
                        <p class="text-2xl font-semibold text-white">MFA</p>
                        <p class="mt-1 text-xs/5">
                            TOTP e códigos de recuperação
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.05] p-4"
                    >
                        <p class="text-2xl font-semibold text-white">AOA</p>
                        <p class="mt-1 text-xs/5">
                            Contexto Angola por defeito
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-white/10 bg-white/[0.05] p-4"
                    >
                        <p class="text-2xl font-semibold text-white">24/7</p>
                        <p class="mt-1 text-xs/5">
                            Registo atribuível de acções
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    </div>
</template>
