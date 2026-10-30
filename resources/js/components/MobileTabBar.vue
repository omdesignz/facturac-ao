<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Ellipsis, Files, HandCoins, House, Plus } from '@lucide/vue';
import { computed } from 'vue';
import { isFocusedPath } from '@/lib/mobile-tab-bar';
import { dashboard } from '@/routes';
import { index as debtsIndex } from '@/routes/debts';
import { index as documentsIndex } from '@/routes/documents';
import { create as invoiceCreate } from '@/routes/invoices';

defineEmits<{ more: [] }>();

/**
 * The phone's navigation: four words along the thumb, with the one thing you
 * do most, a new document, in the middle. It steps aside on the focused
 * screens (the till, a document being made) so their own total and action stay
 * where the thumb already is.
 */
const page = usePage();
const path = computed(() => new URL(page.url, 'http://localhost').pathname);

const visible = computed(() => !isFocusedPath(path.value));

const before = [
    { label: 'Hoje', href: dashboard.url(), icon: House },
    { label: 'Cobrar', href: debtsIndex.url(), icon: HandCoins },
];

const after = [{ label: 'Registos', href: documentsIndex.url(), icon: Files }];

function isActive(href: string): boolean {
    return path.value === href || path.value.startsWith(`${href}/`);
}

function tabClass(href: string): string {
    return [
        'flex flex-1 flex-col items-center justify-center gap-0.5 rounded-2xl text-[0.6875rem] font-semibold focus-ring',
        isActive(href)
            ? 'text-zinc-950 dark:text-white'
            : 'text-zinc-500 dark:text-zinc-400',
    ].join(' ');
}
</script>

<template>
    <nav
        v-if="visible"
        class="fixed inset-x-0 bottom-0 z-40 border-t border-zinc-900/[0.07] bg-white/85 pb-[env(safe-area-inset-bottom)] backdrop-blur-xl lg:hidden dark:border-white/10 dark:bg-black/80"
        aria-label="Navegação"
    >
        <ul role="list" class="mx-auto flex h-16 max-w-md items-stretch px-2">
            <li v-for="tab in before" :key="tab.href" class="flex flex-1">
                <Link
                    :href="tab.href"
                    :aria-current="isActive(tab.href) ? 'page' : undefined"
                    :class="tabClass(tab.href)"
                >
                    <component
                        :is="tab.icon"
                        class="size-6"
                        :stroke-width="isActive(tab.href) ? 2.25 : 1.75"
                        aria-hidden="true"
                    />
                    {{ tab.label }}
                </Link>
            </li>

            <li class="flex flex-1 items-center justify-center">
                <Link
                    :href="invoiceCreate.url()"
                    class="grid size-12 place-items-center rounded-full bg-accent-400 text-brand-950 shadow-[inset_0_-1px_0_rgb(0_0_0/0.1),0_4px_14px_-4px_rgb(150_95_0/0.5)] focus-ring transition hover:bg-accent-300"
                >
                    <Plus
                        class="size-6"
                        :stroke-width="2.25"
                        aria-hidden="true"
                    />
                    <span class="sr-only">Nova factura</span>
                </Link>
            </li>

            <li v-for="tab in after" :key="tab.href" class="flex flex-1">
                <Link
                    :href="tab.href"
                    :aria-current="isActive(tab.href) ? 'page' : undefined"
                    :class="tabClass(tab.href)"
                >
                    <component
                        :is="tab.icon"
                        class="size-6"
                        :stroke-width="isActive(tab.href) ? 2.25 : 1.75"
                        aria-hidden="true"
                    />
                    {{ tab.label }}
                </Link>
            </li>

            <li class="flex flex-1">
                <button
                    type="button"
                    aria-haspopup="dialog"
                    class="flex flex-1 flex-col items-center justify-center gap-0.5 rounded-2xl text-[0.6875rem] font-semibold text-zinc-500 focus-ring dark:text-zinc-400"
                    @click="$emit('more')"
                >
                    <Ellipsis
                        class="size-6"
                        :stroke-width="1.75"
                        aria-hidden="true"
                    />
                    Mais
                </button>
            </li>
        </ul>
    </nav>
</template>
