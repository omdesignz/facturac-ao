<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Package, PackageX, Search, X } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import {
    foldSearch,
    formatMinor,
    formatQuantity,
    grossUnitPriceMinor,
} from '@/lib/pos';
import {
    buttonOutline,
    currencyLabel,
    keyCap,
    wellSection,
} from '@/lib/pos-ui';
import { index as catalogueIndex } from '@/routes/catalogue';
import type { PosCatalogueItem } from '@/types/pos';

const props = defineProps<{
    items: PosCatalogueItem[];
    /** Stock on hand by article, as it stands after the sales of this shift. */
    stock: Record<string, string | null>;
    /** What is on the sale now, by article, for the count on each tile. */
    quantities: Record<string, string>;
    currencyCode: string;
}>();

const emit = defineEmits<{ add: [item: PosCatalogueItem] }>();

/** Enough to fill any screen; beyond this the answer is to search. */
const MAX_TILES = 120;

type Kind = 'all' | 'product' | 'service';

const query = ref('');
const kind = ref<Kind>('all');
const highlight = ref(0);
const navigated = ref(false);
const notFound = ref('');
const announcement = ref('');
const searchField = ref<HTMLInputElement | null>(null);
const grid = ref<HTMLElement | null>(null);

/** Folded once per article; the catalogue itself never changes during a shift. */
const index = computed(() =>
    props.items.map((item) => ({
        item,
        name: foldSearch(item.name),
        code: foldSearch(item.code),
        barcode: item.barcode === null ? '' : foldSearch(item.barcode),
    })),
);

const hasBothKinds = computed(
    () =>
        props.items.some((item) => item.type === 'product') &&
        props.items.some((item) => item.type === 'service'),
);

const folded = computed(() => foldSearch(query.value.trim()));
const tokens = computed(() => folded.value.split(/\s+/).filter(Boolean));

/**
 * What the query finds: every word has to appear in the name, the code or the
 * barcode. An exact code or barcode comes first, then names that start with
 * the query, then the rest in the catalogue's own order.
 */
const matches = computed<PosCatalogueItem[]>(() => {
    const wanted = tokens.value;
    const activeKind = hasBothKinds.value ? kind.value : 'all';
    const pool = index.value.filter(
        (entry) => activeKind === 'all' || entry.item.type === activeKind,
    );

    if (wanted.length === 0) {
        return pool.map((entry) => entry.item);
    }

    const ranked: { item: PosCatalogueItem; rank: number; order: number }[] =
        [];

    pool.forEach((entry, order) => {
        const haystack = `${entry.name} ${entry.code} ${entry.barcode}`;

        if (!wanted.every((token) => haystack.includes(token))) {
            return;
        }

        const rank =
            entry.barcode === folded.value || entry.code === folded.value
                ? 0
                : entry.name.startsWith(folded.value)
                  ? 1
                  : 2;

        ranked.push({ item: entry.item, rank, order });
    });

    return ranked
        .sort(
            (left, right) => left.rank - right.rank || left.order - right.order,
        )
        .map((row) => row.item);
});

const shown = computed(() => matches.value.slice(0, MAX_TILES));

const highlightedId = computed(() =>
    (query.value.trim() !== '' || navigated.value) && shown.value.length > 0
        ? (shown.value[Math.min(highlight.value, shown.value.length - 1)]
              ?.public_id ?? null)
        : null,
);

watch([query, kind], () => {
    highlight.value = 0;
    navigated.value = false;
    notFound.value = '';
});

const kinds: { value: Kind; label: string }[] = [
    { value: 'all', label: 'Todos' },
    { value: 'product', label: 'Produtos' },
    { value: 'service', label: 'Serviços' },
];

function priceOf(item: PosCatalogueItem): string {
    return formatMinor(
        grossUnitPriceMinor({
            unitPriceMinor: item.unit_price_minor,
            taxPercentage: item.tax_percentage,
        }),
    );
}

function stockOf(item: PosCatalogueItem): string | null {
    return item.tracks_stock
        ? (props.stock[item.public_id] ?? item.quantity_on_hand)
        : null;
}

function isOutOfStock(item: PosCatalogueItem): boolean {
    const stock = stockOf(item);

    return stock !== null && Number(stock) <= 0;
}

function stockLabel(item: PosCatalogueItem): string {
    const unit = item.unit_of_measure.toLowerCase();

    return `${formatQuantity(stockOf(item) ?? '0')} ${unit === 'un' ? 'un.' : unit}`;
}

function tileId(item: PosCatalogueItem): string {
    return `pos-tile-${item.public_id}`;
}

function describe(item: PosCatalogueItem): string {
    const stock = stockOf(item);
    const parts = [
        item.name,
        `${priceOf(item)} ${currencyLabel(props.currencyCode)}`,
    ];

    if (stock !== null) {
        parts.push(isOutOfStock(item) ? 'sem stock' : stockLabel(item));
    }

    return parts.join(', ');
}

function finePointer(): boolean {
    return window.matchMedia('(hover: hover) and (pointer: fine)').matches;
}

function focusSearch(select = false): void {
    searchField.value?.focus({ preventScroll: true });

    if (select) {
        searchField.value?.select();
    }
}

function announce(message: string): void {
    // The same sentence twice in a row would not be read again.
    announcement.value =
        announcement.value === message ? `${message} ` : message;
}

function addItem(item: PosCatalogueItem): void {
    emit('add', item);
}

function clearQuery(): void {
    query.value = '';
    notFound.value = '';
}

/**
 * Enter. A scanner types the code and presses Enter, so this is the till's
 * hottest path: an exact barcode or code adds that article and leaves the
 * field empty and focused for the next scan.
 */
function commit(typed: string): void {
    const text = typed.trim();

    if (text === '') {
        // After the arrow keys, Enter takes the tile that is lit.
        const lit = navigated.value ? shown.value[highlight.value] : undefined;

        if (lit !== undefined) {
            addItem(lit);
        }

        return;
    }

    const wanted = foldSearch(text);
    const exact = index.value.find(
        (entry) => entry.barcode === wanted || entry.code === wanted,
    );

    if (exact !== undefined) {
        addItem(exact.item);
        clearQuery();

        return;
    }

    if (matches.value.length === 1 && matches.value[0] !== undefined) {
        addItem(matches.value[0]);
        clearQuery();

        return;
    }

    const target = shown.value[highlight.value] ?? shown.value[0];

    if (target !== undefined) {
        addItem(target);
        clearQuery();

        return;
    }

    // Selected, so the next scan overwrites the one that found nothing.
    notFound.value = text;
    announce(`Nenhum artigo com «${text}».`);
    searchField.value?.select();
}

function columnCount(): number {
    if (grid.value === null) {
        return 1;
    }

    return Math.max(
        1,
        getComputedStyle(grid.value).gridTemplateColumns.split(' ').length,
    );
}

function moveHighlight(step: number): void {
    if (shown.value.length === 0) {
        return;
    }

    navigated.value = true;
    highlight.value = Math.min(
        shown.value.length - 1,
        Math.max(0, highlight.value + step),
    );

    const item = shown.value[highlight.value];

    void nextTick(() => {
        if (item === undefined) {
            return;
        }

        document
            .getElementById(tileId(item))
            ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
        announce(describe(item));
    });
}

function onSearchKeydown(event: KeyboardEvent): void {
    const field = event.target as HTMLInputElement;

    if (event.isComposing) {
        return;
    }

    switch (event.key) {
        case 'Enter':
            event.preventDefault();
            commit(field.value);
            break;
        case 'ArrowDown':
            event.preventDefault();
            moveHighlight(columnCount());
            break;
        case 'ArrowUp':
            event.preventDefault();
            moveHighlight(-columnCount());
            break;
        case 'ArrowRight':
            if (field.selectionStart === field.value.length) {
                event.preventDefault();
                moveHighlight(1);
            }

            break;
        case 'ArrowLeft':
            if (field.selectionEnd === 0) {
                event.preventDefault();
                moveHighlight(-1);
            }

            break;
        case 'Escape':
            if (query.value !== '') {
                event.preventDefault();
                clearQuery();
            }

            break;
        default:
            break;
    }
}

function onTileClick(item: PosCatalogueItem, event: MouseEvent): void {
    addItem(item);

    // A mouse click leaves focus on the tile, and a scanner would then type
    // into it. A keyboard press (detail 0) keeps its place; a finger must not
    // raise the on-screen keyboard.
    if (event.detail > 0 && finePointer()) {
        focusSearch();
    }
}

onMounted(() => {
    if (finePointer()) {
        focusSearch();
    }
});

defineExpose({ focusSearch, announce });
</script>

<template>
    <section aria-labelledby="pos-catalogue-heading" class="min-w-0">
        <h2 id="pos-catalogue-heading" class="sr-only">Artigos</h2>

        <div
            class="sticky inset-bs-[calc(4rem+var(--impersonation-bar,0px))] z-10 -mt-2 bg-stone-50 pt-2 pb-3 dark:bg-zinc-950"
        >
            <div class="relative">
                <label for="pos-search" class="sr-only">
                    Procurar artigo por nome, código ou código de barras
                </label>
                <Search
                    class="pointer-events-none absolute inset-s-4 top-1/2 size-4 -translate-y-1/2 text-zinc-500 dark:text-zinc-400"
                    aria-hidden="true"
                />
                <input
                    id="pos-search"
                    ref="searchField"
                    v-model="query"
                    type="search"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    enterkeyhint="search"
                    placeholder="Nome, código ou código de barras…"
                    aria-keyshortcuts="/ F2"
                    class="h-12 w-full [appearance:none] rounded-full bg-zinc-900/[0.045] ps-11 pe-20 text-base text-zinc-950 outline-hidden placeholder:text-zinc-500 focus:bg-white focus:ring-2 focus:ring-brand-950 dark:bg-white/[0.06] dark:text-white dark:placeholder:text-zinc-400 dark:focus:bg-zinc-900 dark:focus:ring-zinc-200 [&::-webkit-search-cancel-button]:hidden"
                    @keydown="onSearchKeydown"
                />
                <div
                    class="absolute inset-e-1.5 top-1/2 flex -translate-y-1/2 items-center gap-1"
                >
                    <kbd
                        v-if="query === ''"
                        :class="[keyCap, 'me-2.5 hidden lg:grid']"
                        aria-hidden="true"
                        >/</kbd
                    >
                    <button
                        v-if="query !== ''"
                        type="button"
                        class="icon-button rounded-full text-zinc-500 focus-ring hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                        @click="
                            clearQuery();
                            focusSearch();
                        "
                    >
                        <span class="sr-only">Limpar procura</span>
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </div>
            </div>

            <p
                v-if="notFound !== ''"
                class="mt-2 ps-4 text-sm font-medium text-rose-700 dark:text-rose-400"
                role="status"
            >
                Nenhum artigo com «{{ notFound }}».
            </p>

            <div
                v-if="hasBothKinds"
                class="mt-3 flex flex-wrap items-center gap-1"
                role="group"
                aria-label="Tipo de artigo"
            >
                <button
                    v-for="option in kinds"
                    :key="option.value"
                    type="button"
                    :aria-pressed="kind === option.value"
                    class="inline-flex h-[2.125rem] items-center rounded-full px-3.5 text-[0.8125rem] font-medium focus-ring transition pointer-coarse:h-11"
                    :class="
                        kind === option.value
                            ? 'bg-brand-950 text-white dark:bg-zinc-100 dark:text-brand-950'
                            : 'text-zinc-600 hover:bg-zinc-900/[0.05] hover:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/5 dark:hover:text-white'
                    "
                    @click="kind = option.value"
                >
                    {{ option.label }}
                </button>
            </div>
        </div>

        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <div
            v-if="items.length === 0"
            :class="[wellSection, 'px-6 py-14 text-center']"
        >
            <Package
                class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                aria-hidden="true"
            />
            <p class="mt-4 font-semibold text-zinc-900 dark:text-white">
                Ainda não há artigos activos.
            </p>
            <Link :href="catalogueIndex.url()" :class="[buttonOutline, 'mt-6']">
                Abrir os artigos
            </Link>
        </div>

        <p
            v-else-if="shown.length === 0"
            class="px-1 py-10 text-center text-sm text-zinc-600 dark:text-zinc-400"
        >
            Nenhum artigo corresponde à procura.
        </p>

        <ul
            v-else
            ref="grid"
            class="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2.5 pbs-2"
            aria-label="Artigos"
        >
            <li
                v-for="item in shown"
                :key="item.public_id"
                class="relative flex"
            >
                <button
                    :id="tileId(item)"
                    type="button"
                    :aria-label="describe(item)"
                    :data-highlighted="
                        highlightedId === item.public_id ? 'true' : undefined
                    "
                    class="relative flex min-h-[7.25rem] min-w-0 flex-1 flex-col rounded-2xl bg-zinc-900/[0.04] p-3 text-start focus-ring hover:bg-zinc-900/[0.07] data-[highlighted=true]:bg-white data-[highlighted=true]:ring-2 data-[highlighted=true]:ring-brand-950 motion-safe:transition-[scale] motion-safe:duration-100 motion-safe:active:scale-[0.98] dark:bg-white/[0.04] dark:hover:bg-white/[0.08] dark:data-[highlighted=true]:bg-white/[0.1] dark:data-[highlighted=true]:ring-zinc-200"
                    @click="onTileClick(item, $event)"
                >
                    <span
                        class="line-clamp-2 text-sm/5 font-medium [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                        >{{ item.name }}</span
                    >
                    <span
                        class="mt-0.5 truncate font-mono text-xs text-zinc-500 dark:text-zinc-400"
                        >{{ item.code }}</span
                    >
                    <span
                        v-if="stockOf(item) !== null"
                        class="mt-1 flex items-center gap-1 numeric text-xs"
                        :class="
                            isOutOfStock(item)
                                ? 'font-medium text-rose-700 dark:text-rose-400'
                                : 'text-zinc-500 dark:text-zinc-400'
                        "
                    >
                        <PackageX
                            v-if="isOutOfStock(item)"
                            class="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        {{
                            isOutOfStock(item) ? 'Sem stock' : stockLabel(item)
                        }}
                    </span>
                    <span
                        class="mt-auto block pt-2 numeric text-base/5 font-semibold text-zinc-950 dark:text-white"
                        >{{ priceOf(item)
                        }}<span
                            class="ms-1 text-xs font-normal text-zinc-500 dark:text-zinc-400"
                            >{{ currencyLabel(currencyCode) }}</span
                        ></span
                    >
                </button>
                <span
                    v-if="quantities[item.public_id] !== undefined"
                    class="pointer-events-none absolute -inset-e-1.5 -inset-bs-1.5 grid h-6 min-w-6 place-items-center rounded-full bg-brand-950 px-1.5 numeric text-[0.6875rem] font-semibold text-white ring-2 ring-stone-50 dark:bg-zinc-100 dark:text-brand-950 dark:ring-zinc-950"
                    aria-hidden="true"
                    >{{
                        formatQuantity(quantities[item.public_id] ?? '')
                    }}</span
                >
            </li>
        </ul>

        <p
            v-if="matches.length > MAX_TILES"
            class="mt-4 text-center text-sm text-zinc-600 dark:text-zinc-400"
        >
            A mostrar {{ MAX_TILES }} de {{ matches.length }}. Refine a procura.
        </p>
    </section>
</template>
