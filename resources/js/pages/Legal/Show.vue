<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Moon, Sun } from '@lucide/vue';
import { onMounted, ref } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import {
    applyAppearance,
    getStoredAppearance,
    storeAppearance,
} from '@/lib/appearance';

interface LegalIndexEntry {
    type: string;
    label: string;
    slug: string;
    version: number;
}

const props = defineProps<{
    document: {
        type: string;
        title: string;
        summary: string | null;
        body_html: string;
        version: number;
        effective_at: string | null;
    };
    documents: LegalIndexEntry[];
    contact: {
        privacy_email: string;
        support_email: string;
        company: string;
    };
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

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'long' });

function effectiveDate(): string {
    return props.document.effective_at
        ? dateFormatter.format(new Date(props.document.effective_at))
        : '—';
}
</script>

<template>
    <div
        class="min-h-screen bg-white text-zinc-950 dark:bg-zinc-950 dark:text-white"
    >
        <Head :title="document.title" />

        <CookieConsent />

        <header
            class="border-b border-zinc-200/80 bg-white/90 backdrop-blur-xl dark:border-white/10 dark:bg-zinc-950/85"
        >
            <div
                class="mx-auto flex max-w-4xl items-center justify-between gap-4 px-4 py-4 sm:px-6"
            >
                <Link href="/" class="rounded-lg focus-ring">
                    <AppLogo />
                    <span class="sr-only">Voltar ao início</span>
                </Link>

                <button
                    type="button"
                    class="icon-button text-zinc-500 focus-ring transition hover:bg-zinc-100 hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-white"
                    @click="toggleAppearance"
                >
                    <span class="sr-only">Alternar tema</span>
                    <Sun v-if="isDark" class="size-5" aria-hidden="true" />
                    <Moon v-else class="size-5" aria-hidden="true" />
                </button>
            </div>
        </header>

        <main class="mx-auto max-w-4xl px-4 py-10 sm:px-6 lg:py-14">
            <nav class="flex flex-wrap gap-2" aria-label="Documentos legais">
                <Link
                    v-for="entry in documents"
                    :key="entry.type"
                    :href="`/${entry.slug}`"
                    :class="[
                        entry.type === document.type
                            ? 'bg-zinc-950 text-white dark:bg-white dark:text-zinc-950'
                            : 'text-zinc-600 ring-1 ring-zinc-200 hover:bg-zinc-50 dark:text-zinc-400 dark:ring-white/10 dark:hover:bg-white/5',
                        'rounded-lg px-3 py-1.5 text-sm font-medium focus-ring transition',
                    ]"
                >
                    {{ entry.label }}
                </Link>
            </nav>

            <h1 class="mt-8 text-4xl display text-zinc-950 dark:text-white">
                {{ document.title }}
            </h1>

            <p
                v-if="document.summary"
                class="mt-3 max-w-2xl text-base/7 text-zinc-600 dark:text-zinc-400"
            >
                {{ document.summary }}
            </p>

            <dl
                class="mt-6 flex flex-wrap gap-x-8 gap-y-2 border-y border-zinc-100 py-4 text-sm dark:border-white/10"
            >
                <div class="flex gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">Versão</dt>
                    <dd
                        class="font-medium text-zinc-950 tabular-nums dark:text-white"
                    >
                        {{ document.version }}
                    </dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-zinc-500 dark:text-zinc-400">
                        Em vigor desde
                    </dt>
                    <dd class="font-medium text-zinc-950 dark:text-white">
                        {{ effectiveDate() }}
                    </dd>
                </div>
            </dl>

            <!-- eslint-disable-next-line vue/no-v-html -- rendered from Markdown
                 server-side with raw HTML escaped, so this cannot inject markup. -->
            <article class="legal-prose mt-8" v-html="document.body_html" />

            <footer
                class="mt-12 border-t border-zinc-100 pt-6 text-sm/6 text-zinc-500 dark:border-white/10 dark:text-zinc-400"
            >
                <p>
                    {{ contact.company }} — dúvidas sobre dados pessoais para
                    <a
                        :href="`mailto:${contact.privacy_email}`"
                        class="font-medium text-brand-700 underline-offset-4 hover:underline dark:text-accent-300"
                        >{{ contact.privacy_email }}</a
                    >, apoio para
                    <a
                        :href="`mailto:${contact.support_email}`"
                        class="font-medium text-brand-700 underline-offset-4 hover:underline dark:text-accent-300"
                        >{{ contact.support_email }}</a
                    >.
                </p>

                <Link
                    href="/"
                    class="mt-4 inline-flex items-center gap-2 rounded-lg font-medium text-zinc-700 focus-ring dark:text-zinc-300"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    Voltar à aplicação
                </Link>
            </footer>
        </main>
    </div>
</template>
