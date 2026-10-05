<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Cookie } from '@lucide/vue';
import { computed, ref } from 'vue';
import { store as storeConsent } from '@/routes/cookie-consent';

const page = usePage();

const visible = computed(() => page.props.legal.cookie_consent_required);

const showChoices = ref(false);
const preferences = ref(true);
const analytics = ref(false);
const saving = ref(false);

function save(allowPreferences: boolean, allowAnalytics: boolean): void {
    if (saving.value) {
        return;
    }

    saving.value = true;
    router.post(
        storeConsent.url(),
        { preferences: allowPreferences, analytics: allowAnalytics },
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

/**
 * Refusing is one click, the same as accepting. Burying it behind a settings
 * panel while "accept all" sits in the open is not a real choice.
 */
function rejectAll(): void {
    save(false, false);
}

function acceptAll(): void {
    save(true, true);
}

function saveSelection(): void {
    save(preferences.value, analytics.value);
}
</script>

<template>
    <Transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="translate-y-4 opacity-0"
        enter-to-class="translate-y-0 opacity-100"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="visible"
            class="fixed inset-x-0 bottom-0 z-[70] p-4 sm:p-6"
            role="region"
            aria-label="Consentimento de cookies"
        >
            <div
                class="mx-auto max-w-3xl rounded-2xl bg-white p-5 shadow-2xl ring-1 ring-zinc-900/10 dark:bg-zinc-900 dark:ring-white/10"
            >
                <div class="flex items-start gap-3">
                    <span
                        class="grid size-9 shrink-0 place-items-center rounded-xl bg-brand-50 text-brand-700 dark:bg-accent-400/10 dark:text-accent-300"
                    >
                        <Cookie class="size-5" aria-hidden="true" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p
                            class="text-sm font-semibold text-zinc-950 dark:text-white"
                        >
                            Cookies
                        </p>
                        <p
                            class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400"
                        >
                            Usamos cookies essenciais para manter a sua sessão
                            iniciada. Os restantes só com a sua autorização — e
                            não usamos cookies de publicidade.
                            <Link
                                href="/cookies"
                                class="font-medium text-brand-700 underline underline-offset-4 dark:text-accent-300"
                            >
                                Ler a política
                            </Link>
                        </p>

                        <div v-if="showChoices" class="mt-4 space-y-3">
                            <label
                                class="flex items-start gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-white/5"
                            >
                                <input
                                    type="checkbox"
                                    checked
                                    disabled
                                    class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 dark:border-white/20"
                                />
                                <span class="text-sm">
                                    <span
                                        class="font-medium text-zinc-950 dark:text-white"
                                        >Essenciais</span
                                    >
                                    <span
                                        class="block text-zinc-500 dark:text-zinc-400"
                                        >Sessão e segurança. Sempre
                                        activos.</span
                                    >
                                </span>
                            </label>

                            <label
                                class="flex items-start gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-white/5"
                            >
                                <input
                                    v-model="preferences"
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/20"
                                />
                                <span class="text-sm">
                                    <span
                                        class="font-medium text-zinc-950 dark:text-white"
                                        >Preferências</span
                                    >
                                    <span
                                        class="block text-zinc-500 dark:text-zinc-400"
                                        >Tema e barra lateral como os
                                        deixou.</span
                                    >
                                </span>
                            </label>

                            <label
                                class="flex items-start gap-3 rounded-xl bg-zinc-50 p-3 dark:bg-white/5"
                            >
                                <input
                                    v-model="analytics"
                                    type="checkbox"
                                    class="mt-0.5 size-4 rounded border-zinc-300 text-brand-700 focus-ring dark:border-white/20"
                                />
                                <span class="text-sm">
                                    <span
                                        class="font-medium text-zinc-950 dark:text-white"
                                        >Análise de utilização</span
                                    >
                                    <span
                                        class="block text-zinc-500 dark:text-zinc-400"
                                        >Ajuda-nos a perceber o que
                                        melhorar.</span
                                    >
                                </span>
                            </label>
                        </div>

                        <div class="mt-4 flex flex-wrap gap-2">
                            <button
                                type="button"
                                :disabled="saving"
                                class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                                @click="acceptAll"
                            >
                                Aceitar todos
                            </button>
                            <button
                                type="button"
                                :disabled="saving"
                                class="rounded-xl px-4 py-2 text-sm font-semibold text-zinc-700 ring-1 ring-zinc-300 focus-ring transition hover:bg-zinc-50 disabled:opacity-60 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5"
                                @click="rejectAll"
                            >
                                Apenas essenciais
                            </button>
                            <button
                                v-if="!showChoices"
                                type="button"
                                class="rounded-xl px-4 py-2 text-sm font-medium text-zinc-600 focus-ring transition hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                                @click="showChoices = true"
                            >
                                Escolher
                            </button>
                            <button
                                v-else
                                type="button"
                                :disabled="saving"
                                class="rounded-xl px-4 py-2 text-sm font-medium text-zinc-600 focus-ring transition hover:text-zinc-950 disabled:opacity-60 dark:text-zinc-400 dark:hover:text-white"
                                @click="saveSelection"
                            >
                                Guardar escolha
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>
