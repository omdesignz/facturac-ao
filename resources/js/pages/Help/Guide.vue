<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowLeft,
    ArrowRight,
    Bookmark,
    BookOpen,
    CalendarCheck,
    Check,
    ChevronDown,
    CircleCheck,
    Clock,
    Compass,
    LifeBuoy,
    Receipt,
    Search,
    ShieldCheck,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import BrandWordmark from '@/components/BrandWordmark.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { matchesGuideSearch, readGuideProgress } from '@/lib/user-guide';
import type { GuideChapter, GuideProgress } from '@/lib/user-guide';
import { index as helpIndex } from '@/routes/help';
import { index as guideIndex, pdf as guidePdf } from '@/routes/help/guide';

const props = defineProps<{
    guide: {
        edition: string;
        updated_at: string;
        title: string;
        subtitle: string;
        /** Topic name → one line on what it covers, in shelf order. */
        categories: Record<string, string>;
        popular: { label: string; chapter: string }[];
        chapters: GuideChapter[];
    };
    /** Null opens the guide's home; a chapter id opens that chapter. */
    selectedChapter: string | null;
    progressKey: string;
}>();

const query = ref('');
const libraryQuery = ref('');
const progress = ref<GuideProgress>({ completed: [], saved: [], checks: [] });
const storageAvailable = ref(true);
const loaded = ref(false);
const heading = ref<HTMLElement | null>(null);
const announcement = ref('');

/** A plain signifier per topic; an unknown topic falls back to the book. */
const topicIcons: Record<string, Component> = {
    'Primeiros passos': Compass,
    'Vendas e recebimentos': Receipt,
    'Operação diária': CalendarCheck,
    'Controlo e gestão': ShieldCheck,
};

const active = computed(() =>
    props.selectedChapter === null
        ? null
        : (props.guide.chapters.find(
              (chapter) => chapter.id === props.selectedChapter,
          ) ?? null),
);
const activeIndex = computed(() =>
    active.value === null
        ? -1
        : props.guide.chapters.findIndex(
              (chapter) => chapter.id === active.value!.id,
          ),
);
const previousChapter = computed(() =>
    activeIndex.value > 0 ? props.guide.chapters[activeIndex.value - 1]! : null,
);
const nextChapter = computed(() =>
    activeIndex.value >= 0 &&
    activeIndex.value < props.guide.chapters.length - 1
        ? props.guide.chapters[activeIndex.value + 1]!
        : null,
);

/** Topics in the order the config shelves them, each with its chapters. */
const topics = computed(() => {
    const names = [
        ...Object.keys(props.guide.categories ?? {}),
        ...props.guide.chapters.map((chapter) => chapter.category),
    ];

    return [...new Set(names)]
        .map((name) => {
            const chapters = props.guide.chapters.filter(
                (chapter) => chapter.category === name,
            );

            return {
                name,
                description: props.guide.categories?.[name] ?? '',
                icon: topicIcons[name] ?? BookOpen,
                chapters,
                minutes: chapters.reduce(
                    (total, chapter) => total + chapter.minutes,
                    0,
                ),
            };
        })
        .filter((topic) => topic.chapters.length > 0);
});

/** The first topic is the path a new user walks; the rest are shelves. */
const firstSteps = computed(() => topics.value[0] ?? null);
const shelves = computed(() => topics.value.slice(1));

const totalMinutes = computed(() =>
    props.guide.chapters.reduce((total, chapter) => total + chapter.minutes, 0),
);
const searching = computed(() => query.value.trim() !== '');
const results = computed(() =>
    props.guide.chapters.filter((chapter) =>
        matchesGuideSearch(chapter, query.value),
    ),
);
const libraryTopics = computed(() =>
    topics.value
        .map((topic) => ({
            ...topic,
            chapters: topic.chapters.filter((chapter) =>
                matchesGuideSearch(chapter, libraryQuery.value),
            ),
        }))
        .filter((topic) => topic.chapters.length > 0),
);
const popular = computed(() =>
    (props.guide.popular ?? []).filter((task) =>
        props.guide.chapters.some((chapter) => chapter.id === task.chapter),
    ),
);
const savedChapters = computed(() =>
    props.guide.chapters.filter((chapter) =>
        progress.value.saved.includes(chapter.id),
    ),
);
const percent = computed(() =>
    Math.round(
        (progress.value.completed.length / props.guide.chapters.length) * 100,
    ),
);
const nextUnread = computed(() =>
    props.guide.chapters.find(
        (chapter) => !progress.value.completed.includes(chapter.id),
    ),
);
const related = computed(() =>
    active.value === null
        ? []
        : props.guide.chapters.filter((chapter) =>
              active.value!.related.includes(chapter.id),
          ),
);
const completed = computed(
    () =>
        active.value !== null &&
        progress.value.completed.includes(active.value.id),
);
const saved = computed(
    () =>
        active.value !== null && progress.value.saved.includes(active.value.id),
);

function chapterNumber(chapter: GuideChapter): string {
    return String(props.guide.chapters.indexOf(chapter) + 1).padStart(2, '0');
}

function isRead(chapter: GuideChapter): boolean {
    return progress.value.completed.includes(chapter.id);
}

function loadProgress(): void {
    loaded.value = false;

    try {
        progress.value = readGuideProgress(
            localStorage.getItem(props.progressKey),
            props.guide.chapters,
        );
        storageAvailable.value = true;
    } catch {
        progress.value = { completed: [], saved: [], checks: [] };
        storageAvailable.value = false;
    }

    loaded.value = true;
}
onMounted(loadProgress);
watch(() => props.progressKey, loadProgress);
watch(
    progress,
    (value) => {
        if (!loaded.value) {
            return;
        }

        try {
            localStorage.setItem(props.progressKey, JSON.stringify(value));
        } catch {
            storageAvailable.value = false;
        }
    },
    { deep: true },
);

function toggle(key: keyof GuideProgress, value: string): void {
    progress.value[key] = progress.value[key].includes(value)
        ? progress.value[key].filter((item) => item !== value)
        : [...progress.value[key], value];
}
function markRead(): void {
    if (active.value === null) {
        return;
    }

    toggle('completed', active.value.id);
    announcement.value = completed.value
        ? 'Capítulo marcado como lido.'
        : 'Capítulo marcado como não lido.';
}

function chapterUrl(id: string): string {
    return guideIndex.url({ query: { chapter: id } });
}

/**
 * Moves between the home and a chapter without reloading the page, then puts
 * focus on the new heading so a keyboard or screen-reader user starts reading
 * where the eye does.
 */
function openChapter(id: string | null): void {
    router.get(guideIndex.url(), id === null ? {} : { chapter: id }, {
        preserveState: true,
        preserveScroll: true,
        onSuccess: async () => {
            query.value = '';
            await nextTick();
            heading.value?.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: 'instant' });
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head
            :title="active ? `${active.title} · Guia` : 'Guia de utilização'"
        />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <!-- ======================================================== home -->
            <div v-if="active === null" class="mx-auto max-w-6xl">
                <header
                    class="grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem] lg:items-end lg:gap-16"
                >
                    <div class="min-w-0">
                        <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                            Ajuda · Guia de utilização
                        </p>
                        <h1
                            ref="heading"
                            tabindex="-1"
                            class="mt-3 font-display text-section text-balance text-zinc-950 focus:outline-none dark:text-white"
                        >
                            Mais confiança. Em cada passo<span
                                class="brand-dot"
                                aria-hidden="true"
                            />
                        </h1>
                        <p
                            class="mt-4 max-w-xl text-base/7 text-pretty text-zinc-600 dark:text-zinc-400"
                        >
                            {{ guide.chapters.length }} capítulos curtos para
                            preparar a empresa, facturar e acompanhar o negócio.
                            Leia pela ordem, ou procure só a tarefa de hoje.
                        </p>

                        <div class="mt-7 max-w-xl">
                            <label
                                class="field-adorned [--field-adorn-start:3rem]"
                            >
                                <span class="sr-only">Procurar no guia</span>
                                <Search
                                    class="field-adornment-start start-5! size-5"
                                    aria-hidden="true"
                                />
                                <input
                                    v-model="query"
                                    type="search"
                                    autocomplete="off"
                                    spellcheck="false"
                                    placeholder="Procure: recibo, caixa, AGT, nota de crédito…"
                                    class="form-search min-h-14! text-base!"
                                />
                            </label>
                            <div
                                v-if="popular.length && !searching"
                                class="mt-4 flex flex-wrap items-center gap-2"
                            >
                                <span
                                    class="text-sm text-zinc-500 dark:text-zinc-400"
                                    >Mais procurados:</span
                                >
                                <Link
                                    v-for="task in popular"
                                    :key="task.chapter"
                                    :href="chapterUrl(task.chapter)"
                                    class="rounded-full bg-zinc-900/[0.04] px-3 py-1.5 text-sm text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.08] hover:text-zinc-950 dark:bg-white/[0.06] dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white"
                                    @click.prevent="openChapter(task.chapter)"
                                    >{{ task.label }}</Link
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Where the reader is, and the one next step. -->
                    <section
                        aria-labelledby="guide-progress"
                        class="rounded-3xl bg-zinc-900/[0.04] p-6 dark:bg-white/[0.05]"
                    >
                        <h2
                            id="guide-progress"
                            class="eyebrow text-zinc-500 dark:text-zinc-400"
                        >
                            O seu percurso
                        </h2>
                        <p class="mt-3 flex items-baseline gap-2">
                            <span
                                class="font-display numeric text-5xl leading-none tracking-[-0.03em]"
                                >{{ progress.completed.length }}</span
                            >
                            <span
                                class="text-sm text-zinc-600 dark:text-zinc-400"
                                >de {{ guide.chapters.length }} capítulos
                                lidos</span
                            >
                        </p>
                        <div
                            class="mt-4 h-1.5 overflow-hidden rounded-full bg-zinc-900/10 dark:bg-white/10"
                            role="progressbar"
                            :aria-valuenow="percent"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`${percent}% do guia lido`"
                        >
                            <div
                                class="h-full rounded-full bg-accent-400 motion-safe:transition-[width] motion-safe:duration-500"
                                :style="{ width: `${percent}%` }"
                            />
                        </div>
                        <button
                            v-if="nextUnread"
                            type="button"
                            class="mt-5 flex w-full items-center justify-between gap-3 rounded-full bg-brand-950 py-3 ps-5 pe-4 text-start text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                            @click="openChapter(nextUnread.id)"
                        >
                            <span class="min-w-0 truncate">
                                {{
                                    progress.completed.length
                                        ? 'Continuar'
                                        : 'Começar'
                                }}:
                                {{ nextUnread.title }}
                            </span>
                            <ArrowRight
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                        </button>
                        <p
                            v-else
                            class="mt-5 flex items-center gap-2 text-sm font-semibold"
                        >
                            <CircleCheck
                                class="size-5 text-accent-500"
                                aria-hidden="true"
                            />
                            Leu o guia todo. Bom trabalho.
                        </p>
                        <p
                            class="mt-4 text-xs/5 text-zinc-500 dark:text-zinc-400"
                        >
                            {{
                                storageAvailable
                                    ? 'O progresso fica guardado neste navegador, só para si.'
                                    : 'Armazenamento indisponível: o progresso fica apenas nesta visita.'
                            }}
                        </p>
                    </section>
                </header>

                <!-- ------------------------------------------------- results -->
                <section
                    v-if="searching"
                    aria-labelledby="guide-results"
                    class="mt-14"
                >
                    <h2
                        id="guide-results"
                        class="text-sm font-semibold"
                        role="status"
                    >
                        {{ results.length }}
                        {{
                            results.length === 1
                                ? 'capítulo responde a'
                                : 'capítulos respondem a'
                        }}
                        «{{ query.trim() }}»
                    </h2>
                    <ul
                        v-if="results.length"
                        class="mt-4 divide-y divide-zinc-900/10 border-y border-zinc-900/10 dark:divide-white/10 dark:border-white/10"
                    >
                        <li v-for="chapter in results" :key="chapter.id">
                            <Link
                                :href="chapterUrl(chapter.id)"
                                class="group flex items-center gap-5 py-5 focus-ring"
                                @click.prevent="openChapter(chapter.id)"
                            >
                                <span
                                    class="w-8 shrink-0 numeric text-sm text-zinc-400"
                                    >{{ chapterNumber(chapter) }}</span
                                >
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block font-semibold group-hover:underline group-hover:underline-offset-4"
                                        >{{ chapter.title }}</span
                                    >
                                    <span
                                        class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400"
                                        >{{ chapter.summary }}</span
                                    >
                                </span>
                                <span
                                    class="hidden shrink-0 text-xs text-zinc-500 sm:block dark:text-zinc-400"
                                    >{{ chapter.category }} ·
                                    {{ chapter.minutes }} min</span
                                >
                                <ArrowRight
                                    class="size-4 shrink-0 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-zinc-950 dark:group-hover:text-white"
                                    aria-hidden="true"
                                />
                            </Link>
                        </li>
                    </ul>
                    <div
                        v-else
                        class="mt-4 rounded-3xl bg-zinc-900/[0.04] p-8 dark:bg-white/[0.05]"
                    >
                        <p class="font-semibold">Nenhum capítulo fala disso.</p>
                        <p
                            class="mt-1 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            Tente outra palavra, ou pergunte directamente à
                            equipa de apoio.
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2.5">
                            <button
                                type="button"
                                class="rounded-full px-4 py-2.5 text-sm font-semibold ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50"
                                @click="query = ''"
                            >
                                Limpar a pesquisa
                            </button>
                            <Link
                                :href="helpIndex.url()"
                                class="rounded-full bg-brand-950 px-4 py-2.5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                                >Falar com o apoio</Link
                            >
                        </div>
                    </div>
                </section>

                <template v-else>
                    <!-- ------------------------------------------ first steps -->
                    <section
                        v-if="firstSteps"
                        aria-labelledby="guide-first-steps"
                        class="mt-16"
                    >
                        <div
                            class="flex flex-wrap items-end justify-between gap-4"
                        >
                            <div>
                                <p
                                    class="eyebrow text-zinc-500 dark:text-zinc-400"
                                >
                                    Comece por aqui ·
                                    {{ firstSteps.minutes }} min no total
                                </p>
                                <h2
                                    id="guide-first-steps"
                                    class="mt-2 font-display text-3xl tracking-[-0.025em]"
                                >
                                    {{ firstSteps.name }}
                                </h2>
                            </div>
                            <p
                                class="max-w-md text-sm text-pretty text-zinc-600 dark:text-zinc-400"
                            >
                                {{ firstSteps.description }}
                            </p>
                        </div>

                        <!-- A path, read left to right: one line joins the stops. -->
                        <ol
                            class="relative mt-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-5"
                        >
                            <li
                                v-for="(chapter, step) in firstSteps.chapters"
                                :key="chapter.id"
                                class="min-w-0"
                            >
                                <Link
                                    :href="chapterUrl(chapter.id)"
                                    :class="[
                                        isRead(chapter)
                                            ? 'bg-accent-50 ring-accent-400/40 dark:bg-accent-400/[0.07] dark:ring-accent-400/30'
                                            : 'bg-white ring-zinc-900/10 hover:ring-zinc-900/30 dark:bg-zinc-900 dark:ring-white/10 dark:hover:ring-white/30',
                                        'group flex h-full flex-col rounded-2xl p-5 ring-1 focus-ring transition',
                                    ]"
                                    @click.prevent="openChapter(chapter.id)"
                                >
                                    <span
                                        class="flex items-center justify-between gap-3"
                                    >
                                        <span
                                            :class="[
                                                isRead(chapter)
                                                    ? 'bg-accent-400 text-brand-950'
                                                    : 'bg-zinc-900/[0.06] text-zinc-700 dark:bg-white/10 dark:text-zinc-200',
                                                'grid size-8 place-items-center rounded-full numeric text-sm font-semibold',
                                            ]"
                                        >
                                            <Check
                                                v-if="isRead(chapter)"
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            <template v-else>{{
                                                step + 1
                                            }}</template>
                                        </span>
                                        <span
                                            class="text-xs text-zinc-500 dark:text-zinc-400"
                                            >{{ chapter.minutes }} min</span
                                        >
                                    </span>
                                    <span
                                        class="mt-6 font-semibold text-balance"
                                        >{{ chapter.title }}</span
                                    >
                                    <span
                                        class="mt-1.5 line-clamp-3 text-sm text-zinc-600 dark:text-zinc-400"
                                        >{{ chapter.summary }}</span
                                    >
                                    <span class="sr-only">{{
                                        isRead(chapter) ? '(lido)' : ''
                                    }}</span>
                                </Link>
                            </li>
                        </ol>
                    </section>

                    <!-- --------------------------------------------- shelves -->
                    <section
                        v-for="topic in shelves"
                        :key="topic.name"
                        :aria-labelledby="`topic-${topic.name}`"
                        class="mt-16 grid gap-6 border-t border-zinc-900/10 pt-10 lg:grid-cols-[16rem_minmax(0,1fr)] lg:gap-12 dark:border-white/10"
                    >
                        <div>
                            <span
                                class="grid size-11 place-items-center rounded-2xl bg-accent-400/15 text-accent-700 dark:text-accent-300"
                            >
                                <component
                                    :is="topic.icon"
                                    class="size-5"
                                    aria-hidden="true"
                                />
                            </span>
                            <h2
                                :id="`topic-${topic.name}`"
                                class="mt-4 font-display text-2xl tracking-[-0.02em]"
                            >
                                {{ topic.name }}
                            </h2>
                            <p
                                class="mt-2 text-sm text-pretty text-zinc-600 dark:text-zinc-400"
                            >
                                {{ topic.description }}
                            </p>
                            <p
                                class="mt-3 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                {{ topic.chapters.length }} capítulos ·
                                {{ topic.minutes }} min
                            </p>
                        </div>
                        <ul class="grid gap-3 sm:grid-cols-2">
                            <li
                                v-for="chapter in topic.chapters"
                                :key="chapter.id"
                                class="min-w-0"
                            >
                                <Link
                                    :href="chapterUrl(chapter.id)"
                                    class="group flex h-full flex-col rounded-2xl bg-zinc-900/[0.04] p-5 focus-ring transition hover:bg-zinc-900/[0.07] dark:bg-white/[0.05] dark:hover:bg-white/[0.08]"
                                    @click.prevent="openChapter(chapter.id)"
                                >
                                    <span
                                        class="flex items-start justify-between gap-3"
                                    >
                                        <span
                                            class="font-semibold text-balance"
                                        >
                                            {{ chapter.title }}
                                        </span>
                                        <CircleCheck
                                            v-if="isRead(chapter)"
                                            class="size-5 shrink-0 text-accent-500"
                                            aria-label="Lido"
                                        />
                                    </span>
                                    <span
                                        class="mt-1.5 line-clamp-2 text-sm text-zinc-600 dark:text-zinc-400"
                                        >{{ chapter.summary }}</span
                                    >
                                    <span
                                        class="mt-auto flex items-center gap-3 pt-4 text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        <span
                                            class="inline-flex items-center gap-1"
                                            ><Clock
                                                class="size-3.5"
                                                aria-hidden="true"
                                            />{{ chapter.minutes }} min</span
                                        >
                                        <span
                                            v-if="chapter.scenarios?.length"
                                            >{{
                                                chapter.scenarios.length === 1
                                                    ? '1 situação'
                                                    : `${chapter.scenarios.length} situações`
                                            }}</span
                                        >
                                        <Bookmark
                                            v-if="
                                                progress.saved.includes(
                                                    chapter.id,
                                                )
                                            "
                                            class="size-3.5"
                                            fill="currentColor"
                                            aria-label="Guardado"
                                        />
                                        <ArrowRight
                                            class="ms-auto size-4 text-zinc-400 transition group-hover:translate-x-0.5 group-hover:text-zinc-950 dark:group-hover:text-white"
                                            aria-hidden="true"
                                        />
                                    </span>
                                </Link>
                            </li>
                        </ul>
                    </section>

                    <!-- ------------------------------------------------ saved -->
                    <section
                        v-if="savedChapters.length"
                        aria-labelledby="guide-saved"
                        class="mt-16 border-t border-zinc-900/10 pt-10 dark:border-white/10"
                    >
                        <h2
                            id="guide-saved"
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <Bookmark
                                class="size-4"
                                fill="currentColor"
                                aria-hidden="true"
                            />
                            Guardados para mais tarde
                        </h2>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <Link
                                v-for="chapter in savedChapters"
                                :key="chapter.id"
                                :href="chapterUrl(chapter.id)"
                                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50"
                                @click.prevent="openChapter(chapter.id)"
                                >{{ chapter.title
                                }}<ArrowRight
                                    class="size-3.5"
                                    aria-hidden="true"
                            /></Link>
                        </div>
                    </section>
                </template>

                <!-- ---------------------------------------- take it with you -->
                <div
                    class="mt-16 grid gap-3 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]"
                >
                    <a
                        :href="guidePdf.url()"
                        class="group flex items-center gap-6 overflow-hidden rounded-3xl bg-accent-50 p-6 focus-ring sm:gap-8 sm:p-8 dark:bg-accent-400/[0.07]"
                    >
                        <!-- The PDF's own cover, small. -->
                        <span
                            class="hidden aspect-[210/270] w-28 shrink-0 flex-col justify-between bg-white p-3 text-brand-950 shadow-[0.3rem_0.3rem_0_rgb(249_178_51/0.45)] ring-1 ring-zinc-900/10 transition group-hover:-rotate-2 motion-reduce:transition-none sm:flex"
                            aria-hidden="true"
                        >
                            <BrandWordmark class="h-auto w-16" />
                            <span
                                class="font-display text-[0.95rem] leading-tight tracking-[-0.02em]"
                                >Guia de utilização.</span
                            >
                            <img
                                class="h-auto w-full"
                                src="/brand/guide-cover-art.svg"
                                alt=""
                            />
                        </span>
                        <span class="min-w-0">
                            <span
                                class="eyebrow text-accent-800 dark:text-accent-300"
                                >PDF · Edição {{ guide.edition }}</span
                            >
                            <span
                                class="mt-2 block font-display text-2xl tracking-[-0.02em]"
                                >O guia completo, para ler ou imprimir</span
                            >
                            <span
                                class="mt-2 block text-sm text-zinc-600 dark:text-zinc-400"
                                >Com capa, índice e os
                                {{ guide.chapters.length }} capítulos ·
                                {{ totalMinutes }} min de leitura.</span
                            >
                            <span
                                class="mt-4 inline-flex items-center gap-2 text-sm font-semibold"
                                >Descarregar
                                <ArrowDownToLine
                                    class="size-4 transition group-hover:translate-y-0.5"
                                    aria-hidden="true"
                            /></span>
                        </span>
                    </a>
                    <Link
                        :href="helpIndex.url()"
                        class="group flex flex-col rounded-3xl bg-zinc-900/[0.04] p-6 focus-ring transition hover:bg-zinc-900/[0.07] sm:p-8 dark:bg-white/[0.05] dark:hover:bg-white/[0.08]"
                    >
                        <LifeBuoy
                            class="size-6 text-zinc-500 dark:text-zinc-400"
                            aria-hidden="true"
                        />
                        <span
                            class="mt-4 font-display text-2xl tracking-[-0.02em]"
                            >Não encontrou a resposta?</span
                        >
                        <span
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                            >Fale com a equipa de apoio: explique o que procura
                            e indicamos o caminho.</span
                        >
                        <span
                            class="mt-auto inline-flex items-center gap-2 pt-4 text-sm font-semibold"
                            >Falar com o apoio
                            <ArrowRight
                                class="size-4 transition group-hover:translate-x-0.5"
                                aria-hidden="true"
                        /></span>
                    </Link>
                </div>

                <p class="mt-8 text-xs text-zinc-500 dark:text-zinc-400">
                    Edição {{ guide.edition }} · actualizado a
                    {{ guide.updated_at }}
                </p>
            </div>

            <!-- ===================================================== chapter -->
            <div
                v-else
                class="mx-auto grid max-w-6xl gap-8 lg:grid-cols-[17rem_minmax(0,1fr)] lg:gap-14"
            >
                <aside
                    class="min-w-0 lg:sticky lg:top-24 lg:max-h-[calc(100dvh-7rem)] lg:self-start lg:overflow-y-auto lg:overscroll-contain lg:pe-2"
                    aria-label="Capítulos do guia"
                >
                    <Link
                        :href="guideIndex.url()"
                        class="inline-flex items-center gap-2 rounded-full py-1.5 text-sm font-semibold focus-ring"
                        @click.prevent="openChapter(null)"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        Início do guia
                    </Link>

                    <!-- On a phone the list folds away above the chapter. -->
                    <details class="group mt-4 lg:hidden">
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between rounded-2xl bg-zinc-900/[0.04] px-4 py-3 text-sm font-semibold focus-ring dark:bg-white/[0.05] [&::-webkit-details-marker]:hidden"
                        >
                            Todos os capítulos ·
                            {{ progress.completed.length }}/{{
                                guide.chapters.length
                            }}
                            lidos
                            <ChevronDown
                                class="size-4 transition group-open:rotate-180"
                                aria-hidden="true"
                            />
                        </summary>
                        <nav class="mt-3" aria-label="Capítulos">
                            <div
                                v-for="topic in topics"
                                :key="topic.name"
                                class="mt-4 first:mt-0"
                            >
                                <p
                                    class="px-3 eyebrow text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ topic.name }}
                                </p>
                                <Link
                                    v-for="chapter in topic.chapters"
                                    :key="chapter.id"
                                    :href="chapterUrl(chapter.id)"
                                    :aria-current="
                                        active.id === chapter.id
                                            ? 'page'
                                            : undefined
                                    "
                                    class="mt-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm focus-ring aria-[current=page]:bg-zinc-900/[0.06] aria-[current=page]:font-semibold dark:aria-[current=page]:bg-white/10"
                                    @click.prevent="openChapter(chapter.id)"
                                >
                                    <CircleCheck
                                        v-if="isRead(chapter)"
                                        class="size-4 shrink-0 text-accent-500"
                                        aria-label="Lido"
                                    />
                                    <span
                                        v-else
                                        class="w-4 shrink-0 numeric text-xs text-zinc-400"
                                        >{{ chapterNumber(chapter) }}</span
                                    >
                                    {{ chapter.title }}
                                </Link>
                            </div>
                        </nav>
                    </details>

                    <div class="mt-6 hidden lg:block">
                        <label
                            class="field-adorned [--field-adorn-start:2.5rem]"
                        >
                            <span class="sr-only">Filtrar capítulos</span>
                            <Search
                                class="field-adornment-start size-4"
                                aria-hidden="true"
                            />
                            <input
                                v-model="libraryQuery"
                                type="search"
                                autocomplete="off"
                                placeholder="Filtrar capítulos"
                                class="form-search"
                            />
                        </label>
                        <nav class="mt-5" aria-label="Capítulos">
                            <div
                                v-for="topic in libraryTopics"
                                :key="topic.name"
                                class="mt-5 first:mt-0"
                            >
                                <p
                                    class="px-3 eyebrow text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ topic.name }}
                                </p>
                                <Link
                                    v-for="chapter in topic.chapters"
                                    :key="chapter.id"
                                    :href="chapterUrl(chapter.id)"
                                    :aria-current="
                                        active.id === chapter.id
                                            ? 'page'
                                            : undefined
                                    "
                                    class="mt-0.5 flex items-start gap-3 rounded-xl px-3 py-2 text-sm/5 text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.04] hover:text-zinc-950 aria-[current=page]:bg-zinc-900/[0.06] aria-[current=page]:font-semibold aria-[current=page]:text-zinc-950 dark:text-zinc-300 dark:hover:bg-white/[0.06] dark:hover:text-white dark:aria-[current=page]:bg-white/10 dark:aria-[current=page]:text-white"
                                    @click.prevent="openChapter(chapter.id)"
                                >
                                    <CircleCheck
                                        v-if="isRead(chapter)"
                                        class="mt-0.5 size-4 shrink-0 text-accent-500"
                                        aria-label="Lido"
                                    />
                                    <span
                                        v-else
                                        class="mt-px w-4 shrink-0 numeric text-xs text-zinc-400"
                                        >{{ chapterNumber(chapter) }}</span
                                    >
                                    <span class="min-w-0">{{
                                        chapter.title
                                    }}</span>
                                </Link>
                            </div>
                            <p
                                v-if="!libraryTopics.length"
                                class="px-3 text-sm text-zinc-500"
                            >
                                Nenhum capítulo com essa palavra.
                            </p>
                        </nav>
                        <div
                            class="mt-6 border-t border-zinc-900/10 px-3 pt-5 dark:border-white/10"
                        >
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ progress.completed.length }} de
                                {{ guide.chapters.length }} lidos
                            </p>
                            <div
                                class="mt-2 h-1 overflow-hidden rounded-full bg-zinc-900/10 dark:bg-white/10"
                                aria-hidden="true"
                            >
                                <div
                                    class="h-full rounded-full bg-accent-400"
                                    :style="{ width: `${percent}%` }"
                                />
                            </div>
                        </div>
                    </div>
                </aside>

                <article
                    class="max-w-3xl min-w-0"
                    aria-labelledby="chapter-heading"
                >
                    <header
                        class="border-b border-zinc-900/10 pb-8 dark:border-white/10"
                    >
                        <p
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 eyebrow text-zinc-500 dark:text-zinc-400"
                        >
                            <span
                                >Capítulo {{ chapterNumber(active) }} ·
                                {{ active.category }}</span
                            >
                            <span class="inline-flex items-center gap-1"
                                ><Clock class="size-3" aria-hidden="true" />{{
                                    active.minutes
                                }}
                                min</span
                            >
                            <span
                                v-if="completed"
                                class="inline-flex items-center gap-1 text-accent-700 dark:text-accent-300"
                                ><Check class="size-3" aria-hidden="true" />
                                Lido</span
                            >
                        </p>
                        <h1
                            id="chapter-heading"
                            ref="heading"
                            tabindex="-1"
                            class="mt-3 scroll-mt-24 font-display text-[clamp(2rem,1.4rem+2.4vw,2.9rem)] leading-[1.05] tracking-[-0.03em] text-balance text-zinc-950 focus:outline-none dark:text-white"
                        >
                            {{ active.title }}
                        </h1>
                        <p
                            class="mt-4 text-lg/8 text-pretty text-zinc-600 dark:text-zinc-400"
                        >
                            {{ active.summary }}
                        </p>
                        <div class="mt-6 flex flex-wrap gap-2.5">
                            <Link
                                :href="active.url"
                                class="inline-flex h-11 items-center gap-2 rounded-full bg-brand-950 px-5 text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200"
                                >Abrir na aplicação
                                <ArrowRight class="size-4" aria-hidden="true"
                            /></Link>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center gap-2 rounded-full px-5 text-sm font-semibold ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50"
                                :aria-pressed="saved"
                                @click="toggle('saved', active.id)"
                            >
                                <Bookmark
                                    class="size-4"
                                    :fill="saved ? 'currentColor' : 'none'"
                                    aria-hidden="true"
                                />{{
                                    saved ? 'Guardado' : 'Guardar para depois'
                                }}
                            </button>
                        </div>
                    </header>

                    <!-- Steps: one line runs down the numbers, so it reads as a sequence. -->
                    <ol class="relative mt-10 grid gap-9">
                        <li
                            v-for="(step, index) in active.steps"
                            :key="`${active.id}-${index}`"
                            class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-4"
                        >
                            <span
                                v-if="index < active.steps.length - 1"
                                class="absolute start-5 top-11 -bottom-9 w-px bg-zinc-900/10 dark:bg-white/10"
                                aria-hidden="true"
                            />
                            <span
                                class="relative grid size-10 place-items-center rounded-full bg-accent-400 font-display text-lg text-brand-950"
                                aria-hidden="true"
                                >{{ index + 1 }}</span
                            >
                            <div class="min-w-0 pt-1.5">
                                <h2 class="text-lg font-semibold">
                                    {{ step.title }}
                                </h2>
                                <p
                                    class="mt-2 text-[0.95rem]/7 text-pretty text-zinc-700 dark:text-zinc-300"
                                >
                                    {{ step.body }}
                                </p>
                            </div>
                        </li>
                    </ol>

                    <section
                        v-if="active.scenarios?.length"
                        class="mt-14"
                        :aria-labelledby="`scenarios-${active.id}`"
                    >
                        <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                            Na prática
                        </p>
                        <h2
                            :id="`scenarios-${active.id}`"
                            class="mt-2 font-display text-2xl tracking-[-0.02em]"
                        >
                            Qual documento usar?
                        </h2>
                        <p
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            Situações ilustrativas para reconhecer a sua
                            operação.
                        </p>
                        <div class="mt-6 grid gap-3">
                            <div
                                v-for="scenario in active.scenarios"
                                :key="scenario.code"
                                class="grid grid-cols-[3.25rem_minmax(0,1fr)] gap-4 rounded-2xl bg-zinc-900/[0.04] p-5 dark:bg-white/[0.05]"
                            >
                                <span
                                    class="grid h-9 place-items-center rounded-lg bg-white font-mono text-sm font-semibold ring-1 ring-zinc-900/10 dark:bg-zinc-900 dark:ring-white/10"
                                    >{{ scenario.code }}</span
                                >
                                <div class="min-w-0">
                                    <h3 class="font-semibold">
                                        {{ scenario.title }}
                                    </h3>
                                    <p
                                        class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ scenario.when }}
                                    </p>
                                    <p
                                        class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ scenario.example }}
                                    </p>
                                    <p
                                        class="mt-2 text-sm/6 text-zinc-600 dark:text-zinc-400"
                                    >
                                        {{ scenario.action }}
                                    </p>
                                    <p
                                        class="mt-3 border-s-2 border-accent-400 ps-3 text-sm/6 text-zinc-700 dark:text-zinc-300"
                                    >
                                        {{ scenario.attention }}
                                    </p>
                                </div>
                            </div>
                        </div>
                        <a
                            v-if="active.source"
                            :href="active.source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-4 inline-block text-xs text-zinc-500 underline underline-offset-4 focus-ring hover:text-zinc-950 dark:text-zinc-400 dark:hover:text-white"
                            >{{ active.source.label }} ↗</a
                        >
                    </section>

                    <figure
                        v-for="screenshot in active.screenshots"
                        :key="screenshot.src"
                        class="mt-14"
                    >
                        <h2 class="font-display text-2xl tracking-[-0.02em]">
                            {{ screenshot.title }}
                        </h2>
                        <p
                            class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{ screenshot.caption }}
                        </p>
                        <a
                            :href="screenshot.src"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-5 block overflow-hidden rounded-2xl ring-1 ring-zinc-900/10 focus-ring dark:ring-white/10"
                            :aria-label="`Ampliar: ${screenshot.title}`"
                            ><img
                                :src="screenshot.src"
                                :alt="screenshot.alt"
                                loading="lazy"
                                class="h-auto w-full"
                        /></a>
                        <figcaption class="mt-3">
                            <span
                                class="eyebrow text-zinc-500 dark:text-zinc-400"
                                >Dados de demonstração · toque para
                                ampliar</span
                            >
                            <p
                                class="mt-1.5 text-sm/6 text-zinc-600 dark:text-zinc-400"
                            >
                                {{ screenshot.instruction }}
                            </p>
                        </figcaption>
                    </figure>

                    <aside
                        class="mt-12 rounded-2xl bg-accent-50 p-5 sm:p-6 dark:bg-accent-400/[0.07]"
                    >
                        <p class="eyebrow text-accent-800 dark:text-accent-300">
                            Tenha em conta
                        </p>
                        <p
                            class="mt-2 text-[0.95rem]/7 text-zinc-800 dark:text-zinc-200"
                        >
                            {{ active.tip }}
                        </p>
                    </aside>

                    <section
                        class="mt-12"
                        :aria-labelledby="`checklist-${active.id}`"
                    >
                        <h2
                            :id="`checklist-${active.id}`"
                            class="text-lg font-semibold"
                        >
                            Antes de continuar
                        </h2>
                        <p
                            class="mt-1 text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            Confirme o que aprendeu. Estes vistos não alteram os
                            dados da empresa.
                        </p>
                        <div class="mt-4 grid gap-1">
                            <label
                                v-for="(item, index) in active.checklist"
                                :key="`${active.id}:${index}`"
                                class="flex cursor-pointer items-start gap-3 rounded-xl px-3 py-2.5 text-[0.95rem] transition hover:bg-zinc-900/[0.04] dark:hover:bg-white/[0.05]"
                                ><input
                                    type="checkbox"
                                    class="form-checkbox mt-0.5"
                                    :checked="
                                        progress.checks.includes(
                                            `${active.id}:${index}`,
                                        )
                                    "
                                    @change="
                                        toggle(
                                            'checks',
                                            `${active.id}:${index}`,
                                        )
                                    "
                                /><span>{{ item }}</span></label
                            >
                        </div>
                    </section>

                    <details
                        :key="active.id"
                        class="group mt-8 border-y border-zinc-900/10 dark:border-white/10"
                    >
                        <summary
                            class="flex cursor-pointer list-none items-center justify-between gap-4 py-4 font-semibold focus-ring [&::-webkit-details-marker]:hidden"
                        >
                            {{ active.question }}
                            <ChevronDown
                                class="size-4 shrink-0 transition group-open:rotate-180 motion-reduce:transition-none"
                                aria-hidden="true"
                            />
                        </summary>
                        <p
                            class="pb-5 text-[0.95rem]/7 text-zinc-600 dark:text-zinc-400"
                        >
                            {{ active.answer }}
                        </p>
                    </details>

                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <button
                            type="button"
                            :class="[
                                completed
                                    ? 'ring-1 ring-zinc-900/15 hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50'
                                    : 'bg-brand-950 text-white hover:bg-brand-800 dark:bg-white dark:text-brand-950 dark:hover:bg-zinc-200',
                                'inline-flex h-11 items-center gap-2 rounded-full px-5 text-sm font-semibold focus-ring transition',
                            ]"
                            :aria-pressed="completed"
                            @click="markRead"
                        >
                            <Check class="size-4" aria-hidden="true" />{{
                                completed
                                    ? 'Lido · marcar como não lido'
                                    : 'Marcar capítulo como lido'
                            }}
                        </button>
                        <span class="text-sm text-zinc-500 dark:text-zinc-400"
                            >Ao seu ritmo, sem perder o ponto.</span
                        >
                    </div>

                    <nav
                        class="mt-10 grid gap-3 sm:grid-cols-2"
                        aria-label="Percorrer capítulos"
                    >
                        <Link
                            v-if="previousChapter"
                            :href="chapterUrl(previousChapter.id)"
                            class="group flex flex-col rounded-2xl p-5 ring-1 ring-zinc-900/10 focus-ring transition hover:ring-zinc-900/30 dark:ring-white/10 dark:hover:ring-white/30"
                            @click.prevent="openChapter(previousChapter.id)"
                        >
                            <span
                                class="inline-flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                ><ArrowLeft
                                    class="size-3.5 transition group-hover:-translate-x-0.5"
                                    aria-hidden="true"
                                />Anterior</span
                            >
                            <span class="mt-2 font-semibold">{{
                                previousChapter.title
                            }}</span>
                        </Link>
                        <Link
                            v-if="nextChapter"
                            :href="chapterUrl(nextChapter.id)"
                            class="group flex flex-col rounded-2xl bg-zinc-900/[0.04] p-5 text-end focus-ring transition hover:bg-zinc-900/[0.07] sm:col-start-2 dark:bg-white/[0.05] dark:hover:bg-white/[0.08]"
                            @click.prevent="openChapter(nextChapter.id)"
                        >
                            <span
                                class="inline-flex items-center justify-end gap-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >Próximo capítulo<ArrowRight
                                    class="size-3.5 transition group-hover:translate-x-0.5"
                                    aria-hidden="true"
                            /></span>
                            <span class="mt-2 font-semibold">{{
                                nextChapter.title
                            }}</span>
                        </Link>
                    </nav>

                    <section v-if="related.length" class="mt-12">
                        <p class="eyebrow text-zinc-500 dark:text-zinc-400">
                            Continue a aprender
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <Link
                                v-for="chapter in related"
                                :key="chapter.id"
                                :href="chapterUrl(chapter.id)"
                                class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm ring-1 ring-zinc-900/15 focus-ring transition hover:ring-zinc-900/40 dark:ring-white/20 dark:hover:ring-white/50"
                                @click.prevent="openChapter(chapter.id)"
                                >{{ chapter.title
                                }}<ArrowRight
                                    class="size-3.5"
                                    aria-hidden="true"
                            /></Link>
                        </div>
                    </section>

                    <footer
                        class="mt-14 flex flex-wrap items-center justify-between gap-4 border-t border-zinc-900/10 pt-6 text-xs text-zinc-500 dark:border-white/10 dark:text-zinc-400"
                    >
                        <span
                            >Edição {{ guide.edition }} ·
                            {{ guide.updated_at }}</span
                        >
                        <a
                            :href="guidePdf.url()"
                            class="inline-flex items-center gap-2 rounded-full py-2 font-medium focus-ring hover:text-zinc-950 dark:hover:text-white"
                            >Descarregar o guia completo
                            <ArrowDownToLine class="size-4" aria-hidden="true"
                        /></a>
                    </footer>
                </article>
            </div>

            <span class="sr-only" role="status">{{ announcement }}</span>
        </div>
    </AppLayout>
</template>
