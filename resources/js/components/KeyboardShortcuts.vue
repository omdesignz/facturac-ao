<script setup lang="ts">
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/vue';
import { router, usePage } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    detectApplePlatform,
    isDialogOpen,
    isPopupOpen,
    isTypingInFormField,
    keyCap,
    navigableRows,
    nextRow,
    shortcutsSheetOpen,
} from '@/lib/keyboard';
import { useCanPrepareDocuments, useNavigation } from '@/lib/navigation';
import { create as createInvoice } from '@/routes/invoices';

/** How long j/k must rest on a row before it opens, so a held key skims the list. */
const OPEN_DELAY_MS = 140;
/** How long "g" waits for the second key. */
const SEQUENCE_MS = 1200;

const page = usePage();
const { groups } = useNavigation();
const canPrepareDocuments = useCanPrepareDocuments();
const modifierKey = ref('Ctrl');

/** The second key of "g …". Only pages this user's menu offers are reachable. */
const destinations = computed(() => {
    const items = groups.value.flatMap((group) => group.items);

    return [
        { key: 'h', match: '/dashboard' },
        { key: 'd', match: '/documentos' },
        { key: 'c', match: '/customers' },
        { key: 'v', match: '/dividas' },
        { key: 'o', match: '/orcamentos' },
        { key: 'a', match: '/catalogue' },
    ].flatMap(({ key, match }) => {
        const item = items.find((candidate) => candidate.match === match);

        return item ? [{ key, name: item.name, href: item.href }] : [];
    });
});

const sections = computed(() => [
    {
        name: 'Em qualquer ecrã',
        shortcuts: [
            { keys: [modifierKey.value, 'K'], label: 'Pesquisar e agir' },
            ...(canPrepareDocuments.value
                ? [{ keys: ['c'], label: 'Nova factura' }]
                : []),
            { keys: ['?'], label: 'Ver estes atalhos' },
        ],
    },
    {
        name: 'Nas listas',
        shortcuts: [
            { keys: ['j'], label: 'Linha seguinte' },
            { keys: ['k'], label: 'Linha anterior' },
            { keys: ['↵'], label: 'Abrir a linha' },
        ],
    },
    {
        name: 'Na factura',
        shortcuts: [
            { keys: [modifierKey.value, 'S'], label: 'Guardar rascunho' },
        ],
    },
    {
        name: 'Ir para',
        shortcuts: destinations.value.map((destination) => ({
            keys: ['g', destination.key],
            label: destination.name,
        })),
    },
]);

let awaitingDestination = false;
let sequenceTimer: ReturnType<typeof setTimeout> | undefined;
let openTimer: ReturnType<typeof setTimeout> | undefined;

/** The till takes barcode scanners, which type letters as if from a keyboard. */
function isKeyboardReserved(): boolean {
    return page.url.startsWith('/ponto-de-venda');
}

function moveRow(direction: 1 | -1): boolean {
    const row = nextRow(navigableRows(), document.activeElement, direction);

    if (row === null) {
        return false;
    }

    row.focus();
    row.scrollIntoView({ block: 'nearest' });

    clearTimeout(openTimer);

    if (row.dataset.navRow === 'open') {
        openTimer = setTimeout(() => row.click(), OPEN_DELAY_MS);
    }

    return true;
}

function handleKeydown(event: KeyboardEvent): void {
    if (
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        isTypingInFormField(event.target) ||
        isDialogOpen() ||
        isPopupOpen() ||
        isKeyboardReserved()
    ) {
        return;
    }

    if (awaitingDestination) {
        awaitingDestination = false;
        clearTimeout(sequenceTimer);

        const destination = destinations.value.find(
            ({ key }) => key === event.key,
        );

        if (destination) {
            event.preventDefault();
            router.visit(destination.href);
        }

        return;
    }

    const onRow = navigableRows().includes(
        document.activeElement as HTMLElement,
    );

    if (event.key === 'j' || (event.key === 'ArrowDown' && onRow)) {
        if (moveRow(1)) {
            event.preventDefault();
        }
    } else if (event.key === 'k' || (event.key === 'ArrowUp' && onRow)) {
        if (moveRow(-1)) {
            event.preventDefault();
        }
    } else if (event.key === 'g') {
        awaitingDestination = true;
        sequenceTimer = setTimeout(() => {
            awaitingDestination = false;
        }, SEQUENCE_MS);
    } else if (event.key === 'c' && canPrepareDocuments.value) {
        event.preventDefault();
        router.visit(createInvoice.url());
    } else if (event.key === '?') {
        event.preventDefault();
        shortcutsSheetOpen.value = true;
    }
}

onMounted(() => {
    modifierKey.value = detectApplePlatform() ? '⌘' : 'Ctrl';
    window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
    clearTimeout(sequenceTimer);
    clearTimeout(openTimer);
    window.removeEventListener('keydown', handleKeydown);
});
</script>

<template>
    <Dialog
        :open="shortcutsSheetOpen"
        class="relative z-50"
        @close="shortcutsSheetOpen = false"
    >
        <div class="fixed inset-0 dialog-scrim" />
        <div
            class="fixed inset-0 z-50 overflow-y-auto p-3 sm:p-6 sm:pt-[12dvh]"
        >
            <DialogPanel class="mx-auto w-full max-w-md dialog-panel p-6">
                <div class="flex items-center justify-between gap-3">
                    <DialogTitle
                        class="text-lg font-normal tracking-[-0.01em] text-zinc-950 dark:text-white"
                    >
                        Atalhos de teclado
                    </DialogTitle>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.06] hover:text-zinc-950 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white"
                        @click="shortcutsSheetOpen = false"
                    >
                        <X class="size-4" aria-hidden="true" />
                        <span class="sr-only">Fechar</span>
                    </button>
                </div>

                <section
                    v-for="section in sections"
                    :key="section.name"
                    class="mt-5"
                >
                    <h3 class="eyebrow text-zinc-500 dark:text-zinc-400">
                        {{ section.name }}
                    </h3>
                    <dl class="mt-2 grid gap-2">
                        <div
                            v-for="shortcut in section.shortcuts"
                            :key="shortcut.label"
                            class="flex items-center justify-between gap-4 text-sm text-zinc-800 dark:text-zinc-200"
                        >
                            <dt>{{ shortcut.label }}</dt>
                            <dd class="flex items-center gap-1">
                                <kbd
                                    v-for="key in shortcut.keys"
                                    :key="key"
                                    :class="keyCap"
                                    >{{ key }}</kbd
                                >
                            </dd>
                        </div>
                    </dl>
                </section>
            </DialogPanel>
        </div>
    </Dialog>
</template>
