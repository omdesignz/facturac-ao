<script setup lang="ts">
import {
    Combobox,
    ComboboxInput,
    ComboboxOption,
    ComboboxOptions,
    Dialog,
    DialogPanel,
    TransitionChild,
    TransitionRoot,
} from '@headlessui/vue';
import { router, useHttp, usePage } from '@inertiajs/vue3';
import {
    CornerDownLeft,
    FilePlus2,
    FileSignature,
    FileText,
    LoaderCircle,
    Search,
    Truck,
    UserRound,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { Component } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { statusTone } from '@/lib/document-status';
import type { StatusTone } from '@/lib/document-status';
import { useNavigation } from '@/lib/navigation';
import { search } from '@/routes';
import { show as showCustomer } from '@/routes/customers';
import { index as documentsIndex } from '@/routes/documents';
import { create as createInvoice } from '@/routes/invoices';
import { create as createQuote } from '@/routes/quotes';
import { create as createTransportDocument } from '@/routes/transport-documents';

interface DocumentHit {
    public_id: string;
    document_no: string | null;
    document_type_label: string;
    document_date: string;
    customer_name: string | null;
    gross_total_minor: number;
    currency_code: string;
    workflow_status: string;
    workflow_label: string;
}

interface CustomerHit {
    public_id: string;
    name: string;
    tax_identification_number: string | null;
    is_active: boolean;
}

interface SearchResponse {
    documents: DocumentHit[];
    customers: CustomerHit[];
}

/** One row in the palette, whatever it points at. */
interface Result {
    key: string;
    label: string;
    hint: string;
    href: string;
    icon: Component;
    /** Documents and customers get two lines; everything else gets one. */
    detail?: boolean;
    /** The app's one primary create action, set apart with a gold chip. */
    primary?: boolean;
    /** Extra words the query may match that are not shown. */
    keywords?: string;
    mono?: boolean;
    amount?: { figure: string; currency: string };
    status?: { label: string; tone: StatusTone };
}

interface ResultGroup {
    name: string;
    results: Result[];
}

const MIN_QUERY_LENGTH = 2;
const DEBOUNCE_MS = 160;

const page = usePage();
const { groups: navigationGroups } = useNavigation();

const open = ref(false);
const query = ref('');
const documents = ref<DocumentHit[]>([]);
const customers = ref<CustomerHit[]>([]);
/** The query the current server results answer, so stale hits never show. */
const answeredQuery = ref('');
const isApplePlatform = ref(false);

const http = useHttp<{ q: string }, SearchResponse>({ q: '' });

const trimmedQuery = computed(() => query.value.trim());
const isSearching = computed(
    () =>
        trimmedQuery.value.length >= MIN_QUERY_LENGTH &&
        (http.processing || answeredQuery.value !== trimmedQuery.value),
);

const shortcutKeys = computed(() =>
    isApplePlatform.value ? ['⌘', 'K'] : ['Ctrl', 'K'],
);

/** Viewers can look but not prepare, so they are not offered shortcuts to. */
const canPrepareDocuments = computed(() => {
    const current = page.props.auth.workspaces.find(
        (workspace) => workspace.current,
    );

    return current !== undefined && current.role !== 'viewer';
});

/** Accent- and case-blind, so "facturacao" finds "Facturação". */
function normalise(value: string): string {
    return value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

function wholeAmount(
    minor: number,
    currencyCode: string,
): { figure: string; currency: string } {
    return {
        figure: new Intl.NumberFormat('pt-AO', {
            maximumFractionDigits: 0,
        }).format(Math.round(minor / 100)),
        currency: currencyCode === 'AOA' ? 'Kz' : currencyCode,
    };
}

/** The key-cap recipe of the header's shortcut hint, reused wherever a key is named. */
const keyCap =
    'grid h-5 min-w-5 place-items-center rounded-md bg-white px-1.5 font-sans text-[0.6875rem] leading-none font-medium text-zinc-500 shadow-[0_0_0_1px_rgb(23_23_22/0.08)] dark:bg-white/10 dark:text-zinc-300 dark:shadow-none';

function shortDate(isoDate: string): string {
    return new Intl.DateTimeFormat('pt-AO', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${isoDate}T00:00:00`));
}

const actions = computed<Result[]>(() =>
    canPrepareDocuments.value
        ? [
              {
                  key: 'action:invoice',
                  label: 'Emitir factura',
                  hint: 'Novo documento fiscal',
                  href: createInvoice.url(),
                  icon: FilePlus2,
                  primary: true,
              },
              {
                  key: 'action:quote',
                  label: 'Novo orçamento',
                  hint: 'Proposta sem valor fiscal',
                  href: createQuote.url(),
                  icon: FileSignature,
              },
              {
                  key: 'action:transport',
                  label: 'Nova guia de transporte',
                  hint: 'Mercadoria em circulação',
                  href: createTransportDocument.url(),
                  icon: Truck,
              },
          ]
        : [],
);

const pages = computed<Result[]>(() =>
    navigationGroups.value.flatMap((group) =>
        group.items.map((item) => ({
            key: `page:${item.href}`,
            label: item.name,
            hint: group.name === item.name ? '' : group.name,
            keywords: 'Página',
            href: item.href,
            icon: item.icon,
        })),
    ),
);

function matchesQuery(result: Result): boolean {
    const needle = normalise(trimmedQuery.value);

    return (
        normalise(result.label).includes(needle) ||
        normalise(result.hint).includes(needle) ||
        normalise(result.keywords ?? '').includes(needle)
    );
}

const resultGroups = computed<ResultGroup[]>(() => {
    if (trimmedQuery.value === '') {
        return [
            { name: 'Criar', results: actions.value },
            { name: 'Ir para', results: pages.value.slice(0, 8) },
        ].filter((group) => group.results.length > 0);
    }

    // Hits from the previous term stay until the newer answer lands (the list
    // is dimmed meanwhile) so the palette does not blank on every keystroke.
    const showServerHits = trimmedQuery.value.length >= MIN_QUERY_LENGTH;

    const documentResults: Result[] = showServerHits
        ? documents.value.map((document) => ({
              key: `document:${document.public_id}`,
              label: document.document_no ?? 'Rascunho sem número',
              hint: [
                  document.customer_name ?? 'Consumidor final',
                  shortDate(document.document_date),
              ].join(' · '),
              href: documentsIndex.url({
                  query: { documento: document.public_id },
              }),
              icon: FileText,
              detail: true,
              mono: document.document_no !== null,
              amount: wholeAmount(
                  document.gross_total_minor,
                  document.currency_code,
              ),
              status: {
                  label: document.workflow_label,
                  tone: statusTone(document.workflow_status),
              },
          }))
        : [];

    const customerResults: Result[] = showServerHits
        ? customers.value.map((customer) => ({
              key: `customer:${customer.public_id}`,
              label: customer.name,
              hint: [
                  customer.tax_identification_number
                      ? `NIF ${customer.tax_identification_number}`
                      : 'Sem NIF',
                  customer.is_active ? null : 'inactivo',
              ]
                  .filter(Boolean)
                  .join(' · '),
              href: showCustomer.url(customer.public_id),
              icon: UserRound,
              detail: true,
          }))
        : [];

    const everywhere: Result[] =
        trimmedQuery.value.length >= MIN_QUERY_LENGTH
            ? [
                  {
                      key: 'search:documents',
                      label: `Procurar «${trimmedQuery.value}» em todos os documentos`,
                      hint: 'Abre o registo com este filtro',
                      href: documentsIndex.url({
                          query: { q: trimmedQuery.value },
                      }),
                      icon: Search,
                  },
              ]
            : [];

    return [
        { name: 'Documentos', results: documentResults },
        { name: 'Clientes', results: customerResults },
        {
            name: 'Páginas',
            results: pages.value.filter(matchesQuery).slice(0, 5),
        },
        { name: 'Criar', results: actions.value.filter(matchesQuery) },
        { name: 'Mais', results: everywhere },
    ].filter((group) => group.results.length > 0);
});

/** Results from an earlier term are on screen while a newer one is looked up. */
const isShowingStaleHits = computed(
    () =>
        isSearching.value &&
        documents.value.length + customers.value.length > 0,
);

const resultCount = computed(() =>
    resultGroups.value.reduce(
        (total, group) => total + group.results.length,
        0,
    ),
);

/** Spoken, not shown: what the list is doing, since it changes silently. */
const statusMessage = computed(() => {
    if (trimmedQuery.value.length < MIN_QUERY_LENGTH) {
        return '';
    }

    if (isSearching.value) {
        return 'A procurar…';
    }

    if (resultCount.value === 0) {
        return 'Nenhum resultado';
    }

    return resultCount.value === 1
        ? '1 resultado'
        : `${resultCount.value} resultados`;
});

const hasOnlyFallback = computed(
    () =>
        !isSearching.value &&
        resultGroups.value.length === 1 &&
        resultGroups.value[0]?.name === 'Mais',
);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

async function runSearch(term: string): Promise<void> {
    http.cancel();
    http.q = term;

    try {
        const response = await http.get(search.url());

        if (term !== trimmedQuery.value) {
            return;
        }

        documents.value = response.documents;
        customers.value = response.customers;
        answeredQuery.value = term;
    } catch {
        // A cancelled or failed lookup leaves the local results in place.
        if (term === trimmedQuery.value) {
            documents.value = [];
            customers.value = [];
            answeredQuery.value = term;
        }
    }
}

watch(trimmedQuery, (term) => {
    clearTimeout(debounceTimer);

    if (term.length < MIN_QUERY_LENGTH) {
        http.cancel();
        documents.value = [];
        customers.value = [];
        answeredQuery.value = term;

        return;
    }

    debounceTimer = setTimeout(() => void runSearch(term), DEBOUNCE_MS);
});

function openPalette(): void {
    open.value = true;
}

function closePalette(): void {
    open.value = false;
}

function resetPalette(): void {
    clearTimeout(debounceTimer);
    http.cancel();
    query.value = '';
    documents.value = [];
    customers.value = [];
    answeredQuery.value = '';
}

function choose(result: Result | null): void {
    if (result === null) {
        return;
    }

    closePalette();
    router.visit(result.href);
}

function isTypingInFormField(target: EventTarget | null): boolean {
    if (!(target instanceof HTMLElement)) {
        return false;
    }

    return (
        target.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
    );
}

/** Another dialog already has the user's attention, and the page behind it is inert. */
function isAnotherDialogOpen(): boolean {
    return (
        document.querySelector('[role="dialog"], [role="alertdialog"]') !== null
    );
}

function handleGlobalKeydown(event: KeyboardEvent): void {
    const modifier = isApplePlatform.value ? event.metaKey : event.ctrlKey;

    if ((event.key === 'k' || event.key === 'K') && modifier && !event.altKey) {
        if (!open.value && isAnotherDialogOpen()) {
            return;
        }

        event.preventDefault();
        open.value = !open.value;

        return;
    }

    // "/" is the web's other search key, but never while someone is typing.
    if (
        event.key === '/' &&
        !open.value &&
        !isTypingInFormField(event.target) &&
        !isAnotherDialogOpen()
    ) {
        event.preventDefault();
        openPalette();
    }
}

function detectApplePlatform(): boolean {
    if (typeof navigator === 'undefined') {
        return false;
    }

    const platform =
        (navigator as { userAgentData?: { platform?: string } }).userAgentData
            ?.platform ??
        navigator.platform ??
        '';

    return /mac|iphone|ipad|ipod/i.test(platform);
}

onMounted(() => {
    isApplePlatform.value = detectApplePlatform();
    window.addEventListener('keydown', handleGlobalKeydown);
});

onBeforeUnmount(() => {
    clearTimeout(debounceTimer);
    window.removeEventListener('keydown', handleGlobalKeydown);
});
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center">
        <button
            type="button"
            class="group flex h-10 w-full max-w-md min-w-0 items-center gap-3 rounded-full bg-zinc-900/[0.04] pr-2 pl-3.5 text-left text-sm text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.07] hover:text-zinc-700 dark:bg-white/[0.06] dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-200"
            aria-haspopup="dialog"
            :aria-keyshortcuts="isApplePlatform ? 'Meta+K' : 'Control+K'"
            @click="openPalette"
        >
            <Search class="size-4 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1 truncate">
                Pesquisar factura, cliente ou NIF…
            </span>
            <span
                class="hidden shrink-0 items-center gap-1 sm:flex"
                aria-hidden="true"
            >
                <kbd
                    v-for="key in shortcutKeys"
                    :key="key"
                    class="grid h-5 min-w-5 place-items-center rounded-md bg-white px-1.5 font-sans text-[0.6875rem] leading-none font-medium text-zinc-500 shadow-[0_0_0_1px_rgb(23_23_22/0.08)] dark:bg-white/10 dark:text-zinc-300 dark:shadow-none"
                    >{{ key }}</kbd
                >
            </span>
        </button>

        <TransitionRoot as="template" :show="open" @after-leave="resetPalette">
            <Dialog
                class="relative z-50"
                aria-label="Pesquisa"
                @close="closePalette"
            >
                <!-- No enter or leave animation: the palette answers a keystroke
                     and should be there before the eye has moved. -->
                <TransitionChild as="template">
                    <div class="fixed inset-0 dialog-scrim" />
                </TransitionChild>

                <div
                    class="fixed inset-0 z-50 overflow-y-auto p-3 pt-[max(0.75rem,env(safe-area-inset-top))] sm:p-6 sm:pt-[12dvh]"
                >
                    <TransitionChild as="template">
                        <DialogPanel
                            class="mx-auto w-full max-w-[40rem] overflow-clip dialog-panel"
                        >
                            <Combobox
                                :model-value="null"
                                @update:model-value="choose"
                            >
                                <div class="flex items-center gap-2 p-2.5">
                                    <div
                                        class="flex h-12 min-w-0 flex-1 items-center gap-3 rounded-full bg-zinc-900/[0.04] ps-4 pe-2 focus-within:outline-1 focus-within:-outline-offset-1 focus-within:outline-zinc-900/20 dark:bg-white/[0.06] dark:focus-within:outline-white/20"
                                    >
                                        <Search
                                            class="size-4.5 shrink-0 text-zinc-500 dark:text-zinc-400"
                                            aria-hidden="true"
                                        />
                                        <ComboboxInput
                                            class="h-full min-w-0 flex-1 bg-transparent text-[0.9375rem] text-zinc-950 outline-hidden placeholder:text-zinc-500 dark:text-white dark:placeholder:text-zinc-400"
                                            placeholder="Número, cliente, NIF ou página…"
                                            autocomplete="off"
                                            spellcheck="false"
                                            aria-label="Pesquisar"
                                            @change="
                                                query = $event.target.value
                                            "
                                        />
                                        <LoaderCircle
                                            v-if="isSearching"
                                            class="size-4 shrink-0 animate-spin-delayed text-zinc-500 dark:text-zinc-400"
                                            aria-hidden="true"
                                        />
                                        <kbd :class="[keyCap, 'hidden sm:grid']"
                                            >esc</kbd
                                        >
                                    </div>
                                    <button
                                        type="button"
                                        class="tap-target shrink-0 rounded-full px-2 py-1 text-sm font-medium text-zinc-500 focus-ring hover:text-zinc-900 sm:hidden dark:text-zinc-400 dark:hover:text-white"
                                        @click="closePalette"
                                    >
                                        Cancelar
                                    </button>
                                </div>

                                <p class="sr-only" role="status">
                                    {{ statusMessage }}
                                </p>

                                <ComboboxOptions
                                    static
                                    class="max-h-[min(28rem,45dvh)] scroll-py-2 overflow-y-auto overscroll-contain p-2 pt-0 sm:max-h-[min(28rem,65dvh)]"
                                    :class="
                                        isShowingStaleHits ? 'opacity-60' : ''
                                    "
                                >
                                    <li
                                        v-if="hasOnlyFallback"
                                        role="presentation"
                                        class="px-3 py-4"
                                    >
                                        <p
                                            class="text-sm font-medium text-zinc-950 dark:text-white"
                                        >
                                            Nada com «{{ trimmedQuery }}»
                                        </p>
                                        <p
                                            class="mt-1 text-[0.8125rem] text-zinc-500 dark:text-zinc-400"
                                        >
                                            Procura-se pelo número do documento,
                                            pelo nome ou NIF do cliente e pelos
                                            nomes das páginas.
                                        </p>
                                    </li>
                                    <li
                                        v-for="(group, index) in resultGroups"
                                        :key="group.name"
                                        role="presentation"
                                        :class="
                                            index > 0
                                                ? 'mt-2 border-t border-zinc-900/[0.06] pt-3 dark:border-white/10'
                                                : ''
                                        "
                                    >
                                        <ul
                                            role="group"
                                            :aria-labelledby="
                                                hasOnlyFallback
                                                    ? undefined
                                                    : `palette-group-${index}`
                                            "
                                        >
                                            <li
                                                v-if="!hasOnlyFallback"
                                                :id="`palette-group-${index}`"
                                                role="presentation"
                                                class="ps-3 pe-3 pb-1.5 eyebrow text-zinc-500 dark:text-zinc-400"
                                                :class="
                                                    index === 0 ? 'pt-1.5' : ''
                                                "
                                            >
                                                {{ group.name }}
                                            </li>
                                            <ComboboxOption
                                                v-for="result in group.results"
                                                :key="result.key"
                                                v-slot="{ active }"
                                                :value="result"
                                                as="template"
                                            >
                                                <li
                                                    class="flex cursor-pointer items-center gap-3 px-3 select-none"
                                                    :class="[
                                                        result.detail
                                                            ? 'rounded-2xl py-2.5'
                                                            : 'min-h-10 rounded-xl py-2 pointer-coarse:min-h-11',
                                                        active
                                                            ? 'bg-zinc-900/[0.05] dark:bg-white/[0.07]'
                                                            : '',
                                                    ]"
                                                >
                                                    <span
                                                        class="grid size-7 shrink-0 place-items-center"
                                                        :class="
                                                            result.primary
                                                                ? 'rounded-full bg-accent-400 text-brand-950'
                                                                : active
                                                                  ? 'text-zinc-950 dark:text-white'
                                                                  : 'text-zinc-500 dark:text-zinc-400'
                                                        "
                                                    >
                                                        <component
                                                            :is="result.icon"
                                                            class="size-4 shrink-0"
                                                            aria-hidden="true"
                                                        />
                                                    </span>

                                                    <span
                                                        v-if="result.detail"
                                                        class="min-w-0 flex-1"
                                                    >
                                                        <span
                                                            class="block truncate font-medium text-zinc-950 dark:text-white"
                                                            :class="
                                                                result.mono
                                                                    ? 'font-mono text-[0.8125rem]'
                                                                    : 'text-sm'
                                                            "
                                                            >{{
                                                                result.label
                                                            }}</span
                                                        >
                                                        <span
                                                            class="mt-0.5 block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                            >{{
                                                                result.hint
                                                            }}</span
                                                        >
                                                        <StatusBadge
                                                            v-if="result.status"
                                                            class="mt-1.5 sm:hidden"
                                                            :label="
                                                                result.status
                                                                    .label
                                                            "
                                                            :tone="
                                                                result.status
                                                                    .tone
                                                            "
                                                        />
                                                    </span>
                                                    <template v-else>
                                                        <span
                                                            class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-950 dark:text-white"
                                                            >{{
                                                                result.label
                                                            }}</span
                                                        >
                                                        <span
                                                            v-if="result.hint"
                                                            class="ms-auto hidden shrink-0 ps-3 text-xs text-zinc-500 sm:block dark:text-zinc-400"
                                                            >{{
                                                                result.hint
                                                            }}</span
                                                        >
                                                    </template>

                                                    <span
                                                        v-if="result.amount"
                                                        class="hidden shrink-0 sm:block"
                                                    >
                                                        <span
                                                            class="numeric text-sm text-zinc-950 dark:text-white"
                                                            >{{
                                                                result.amount
                                                                    .figure
                                                            }}</span
                                                        ><span
                                                            class="ms-1 text-xs text-zinc-500 dark:text-zinc-400"
                                                            >{{
                                                                result.amount
                                                                    .currency
                                                            }}</span
                                                        >
                                                    </span>
                                                    <StatusBadge
                                                        v-if="result.status"
                                                        class="shrink-0 max-sm:hidden"
                                                        :label="
                                                            result.status.label
                                                        "
                                                        :tone="
                                                            result.status.tone
                                                        "
                                                    />
                                                    <kbd
                                                        v-else
                                                        :class="[
                                                            keyCap,
                                                            'hidden shrink-0 sm:grid',
                                                            active
                                                                ? ''
                                                                : 'invisible',
                                                        ]"
                                                        aria-hidden="true"
                                                        ><CornerDownLeft
                                                            class="size-3"
                                                    /></kbd>
                                                </li>
                                            </ComboboxOption>
                                        </ul>
                                    </li>
                                </ComboboxOptions>

                                <div
                                    class="hidden items-center gap-5 border-t border-zinc-900/[0.06] px-4 py-2.5 text-xs text-zinc-500 sm:flex dark:border-white/10 dark:text-zinc-400"
                                >
                                    <span class="flex items-center gap-1.5">
                                        <kbd :class="keyCap">↑</kbd>
                                        <kbd :class="keyCap">↓</kbd>
                                        navegar
                                    </span>
                                    <span class="flex items-center gap-1.5">
                                        <kbd :class="keyCap"
                                            ><CornerDownLeft
                                                class="size-3"
                                                aria-hidden="true"
                                        /></kbd>
                                        abrir
                                    </span>
                                    <span class="ms-auto"
                                        >Só procura na empresa em que está a
                                        trabalhar.</span
                                    >
                                </div>
                            </Combobox>
                        </DialogPanel>
                    </TransitionChild>
                </div>
            </Dialog>
        </TransitionRoot>
    </div>
</template>
