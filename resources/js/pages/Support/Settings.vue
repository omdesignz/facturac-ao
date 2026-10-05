<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, SlidersHorizontal } from '@lucide/vue';
import FormError from '@/components/FormError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { update } from '@/routes/support/settings';

interface SettingField {
    key: string;
    label: string;
    help: string;
    multiline: boolean;
}

const props = defineProps<{
    settings: Record<string, string>;
    fields: SettingField[];
}>();

const form = useForm<Record<string, string>>({ ...props.settings });

function submit(): void {
    form.put(update.url(), { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Definições da plataforma" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-3xl space-y-6">
                <header>
                    <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                        Interno
                    </p>
                    <h1
                        class="mt-2.5 text-[2.125rem] leading-[1.08] display text-zinc-950 dark:text-white"
                    >
                        Definições da plataforma
                    </h1>
                    <p
                        class="mt-2 max-w-2xl text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        O que os clientes vêem na página de Ajuda e nos
                        documentos legais. Muda aqui, sem nova versão da
                        aplicação.
                    </p>
                </header>

                <form
                    class="overflow-hidden rounded-2xl surface"
                    @submit.prevent="submit"
                >
                    <div
                        class="flex items-center gap-3 border-b border-zinc-100 p-5 dark:border-white/10"
                    >
                        <span
                            class="grid size-10 place-items-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300"
                        >
                            <SlidersHorizontal
                                class="size-5"
                                aria-hidden="true"
                            />
                        </span>
                        <div>
                            <h2
                                class="text-sm font-semibold text-zinc-950 dark:text-white"
                            >
                                Contactos e identidade
                            </h2>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                Deixar um campo em branco repõe o valor por
                                omissão.
                            </p>
                        </div>
                    </div>

                    <div class="space-y-5 p-5">
                        <div v-for="field in fields" :key="field.key">
                            <label
                                :for="`setting-${field.key}`"
                                class="block text-sm font-medium text-zinc-900 dark:text-zinc-100"
                            >
                                {{ field.label }}
                            </label>

                            <textarea
                                v-if="field.multiline"
                                :id="`setting-${field.key}`"
                                v-model="form[field.key]"
                                rows="2"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />
                            <input
                                v-else
                                :id="`setting-${field.key}`"
                                v-model="form[field.key]"
                                type="text"
                                class="mt-2 w-full rounded-xl border-0 bg-white px-3 py-2.5 text-sm text-zinc-950 ring-1 ring-zinc-200 focus-ring ring-inset dark:bg-white/5 dark:text-white dark:ring-white/10"
                            />

                            <p
                                class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ field.help }}
                            </p>
                            <FormError :message="form.errors[field.key]" />
                        </div>
                    </div>

                    <div
                        class="flex justify-end border-t border-zinc-100 p-5 dark:border-white/10"
                    >
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex h-10 items-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            Guardar definições
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
