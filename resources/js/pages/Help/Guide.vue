<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    ArrowRight,
    Bookmark,
    BookOpen,
    Check,
    ChevronLeft,
    ChevronRight,
    CircleCheck,
    Clock,
    Search,
    X,
} from '@lucide/vue';
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
        chapters: GuideChapter[];
    };
    selectedChapter: string;
    progressKey: string;
}>();
const query = ref('');
const category = ref('Todos');
const onlySaved = ref(false);
const progress = ref<GuideProgress>({ completed: [], saved: [], checks: [] });
const storageAvailable = ref(true);
const loaded = ref(false);
const heading = ref<HTMLElement | null>(null);
const announcement = ref('');
const active = computed(
    () =>
        props.guide.chapters.find(
            (chapter) => chapter.id === props.selectedChapter,
        ) ?? props.guide.chapters[0]!,
);
const activeIndex = computed(() =>
    props.guide.chapters.findIndex((chapter) => chapter.id === active.value.id),
);
const categories = computed(() => [
    'Todos',
    ...new Set(props.guide.chapters.map((chapter) => chapter.category)),
]);
const results = computed(() =>
    props.guide.chapters.filter(
        (chapter) =>
            (category.value === 'Todos' ||
                chapter.category === category.value) &&
            (!onlySaved.value || progress.value.saved.includes(chapter.id)) &&
            matchesGuideSearch(chapter, query.value),
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
    props.guide.chapters.filter((chapter) =>
        active.value.related.includes(chapter.id),
    ),
);
const completed = computed(() =>
    progress.value.completed.includes(active.value.id),
);
const saved = computed(() => progress.value.saved.includes(active.value.id));

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
    toggle('completed', active.value.id);
    announcement.value = completed.value
        ? 'Capítulo marcado como lido.'
        : 'Capítulo marcado como não lido.';
}
function openChapter(id: string): void {
    router.get(
        guideIndex.url(),
        { chapter: id },
        {
            preserveState: true,
            preserveScroll: true,
            onSuccess: async () => {
                await nextTick();
                heading.value?.focus({ preventScroll: true });
                heading.value?.scrollIntoView({
                    block: 'start',
                    behavior: 'instant',
                });
            },
        },
    );
}
function clearFilters(): void {
    query.value = '';
    category.value = 'Todos';
    onlySaved.value = false;
}
</script>

<template>
    <AppLayout>
        <Head title="Guia de utilização" />
        <div class="guide-shell">
            <header class="guide-hero">
                <div class="hero-copy">
                    <p class="guide-kicker">
                        FACTURAC / CENTRO DE APRENDIZAGEM
                    </p>
                    <h1>Mais confiança.<br />Em cada passo.</h1>
                    <p class="hero-summary">
                        O seu guia para preparar a empresa, facturar e
                        acompanhar o negócio. Comece pelo essencial ou encontre
                        a tarefa de hoje.
                    </p>
                    <div class="hero-actions">
                        <button
                            v-if="nextUnread"
                            class="guide-button primary"
                            @click="openChapter(nextUnread.id)"
                        >
                            {{
                                progress.completed.length
                                    ? 'Continuar a aprender'
                                    : 'Começar pelo essencial'
                            }}
                            <ArrowRight aria-hidden="true" class="size-4" />
                        </button>
                        <Link
                            class="guide-button secondary"
                            :href="helpIndex.url()"
                            >Falar com o apoio</Link
                        >
                    </div>
                </div>
                <a
                    class="pdf-card"
                    :href="guidePdf.url()"
                    aria-label="Descarregar guia completo em PDF, com capa e índice"
                >
                    <div class="mini-cover" aria-hidden="true">
                        <BrandWordmark class="mini-brand" />
                        <strong>Guia de<br />utilização.</strong>
                        <img
                            class="mini-art"
                            src="/brand/guide-cover-art.svg"
                            alt=""
                        />
                        <small>PREPARAR · OPERAR · CONFERIR</small>
                        <div class="mini-developer">
                            <img src="/brand/vap-developer.svg" alt="" /><span
                                >DESENVOLVIDO POR VAP</span
                            >
                        </div>
                    </div>
                    <div class="pdf-caption">
                        <span
                            ><strong>O guia, sempre à mão.</strong
                            ><small>PDF completo · capa e índice</small></span
                        ><ArrowDownToLine class="size-5" aria-hidden="true" />
                    </div>
                </a>
            </header>

            <section
                class="reading-progress"
                aria-label="O seu progresso de leitura"
            >
                <BookOpen class="size-5 shrink-0" aria-hidden="true" />
                <div class="progress-copy">
                    <strong
                        >{{ progress.completed.length }} de
                        {{ guide.chapters.length }} capítulos lidos</strong
                    ><span>{{
                        storageAvailable
                            ? 'Progresso guardado neste navegador, por utilizador.'
                            : 'Armazenamento indisponível. O progresso fica apenas nesta visita.'
                    }}</span>
                </div>
                <progress
                    :value="progress.completed.length"
                    :max="guide.chapters.length"
                    :aria-label="`${percent}% do guia lido`"
                ></progress>
                <span class="progress-percent">{{ percent }}%</span>
            </section>

            <div class="guide-workspace">
                <aside
                    class="guide-library"
                    aria-label="Biblioteca de capítulos"
                >
                    <label class="search-label" for="guide-search"
                        >Encontre uma resposta</label
                    >
                    <div class="guide-search">
                        <Search class="size-4" aria-hidden="true" /><input
                            id="guide-search"
                            v-model="query"
                            type="search"
                            placeholder="Ex.: recibo, caixa, AGT…"
                            autocomplete="off"
                        /><button
                            v-if="query"
                            aria-label="Limpar pesquisa"
                            @click="query = ''"
                        >
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </div>
                    <div class="category-filters" aria-label="Filtrar por tema">
                        <button
                            v-for="item in categories"
                            :key="item"
                            :aria-pressed="category === item"
                            @click="category = item"
                        >
                            {{ item }}
                        </button>
                    </div>
                    <button
                        class="saved-filter"
                        :aria-pressed="onlySaved"
                        @click="onlySaved = !onlySaved"
                    >
                        <Bookmark class="size-4" aria-hidden="true" /> Só
                        guardados <span>{{ progress.saved.length }}</span>
                    </button>
                    <p class="result-count" role="status">
                        {{ results.length }}
                        {{
                            results.length === 1
                                ? 'capítulo encontrado'
                                : 'capítulos encontrados'
                        }}
                    </p>
                    <nav class="chapter-list" aria-label="Capítulos do guia">
                        <Link
                            v-for="chapter in results"
                            :key="chapter.id"
                            :href="
                                guideIndex.url({
                                    query: { chapter: chapter.id },
                                })
                            "
                            :aria-current="
                                active.id === chapter.id ? 'page' : undefined
                            "
                            @click.prevent="openChapter(chapter.id)"
                        >
                            <span class="chapter-number"
                                ><CircleCheck
                                    v-if="
                                        progress.completed.includes(chapter.id)
                                    "
                                    class="size-4"
                                    aria-label="Lido"
                                /><template v-else>{{
                                    String(
                                        guide.chapters.indexOf(chapter) + 1,
                                    ).padStart(2, '0')
                                }}</template></span
                            >
                            <span
                                ><strong>{{ chapter.title }}</strong
                                ><small
                                    >{{ chapter.category }} ·
                                    {{ chapter.minutes }} min</small
                                ></span
                            >
                            <ChevronRight
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                        </Link>
                    </nav>
                    <div v-if="!results.length" class="guide-empty">
                        <BookOpen class="size-6" aria-hidden="true" /><strong
                            >Nenhum capítulo encontrado.</strong
                        >
                        <p>Tente outra palavra ou remova os filtros.</p>
                        <button
                            class="guide-button secondary"
                            @click="clearFilters"
                        >
                            Limpar filtros
                        </button>
                    </div>
                </aside>

                <article
                    class="guide-article"
                    aria-labelledby="chapter-heading"
                >
                    <header class="article-header">
                        <div class="article-meta">
                            <span
                                >{{
                                    String(activeIndex + 1).padStart(2, '0')
                                }}
                                / {{ active.category }}</span
                            ><span
                                ><Clock class="size-3.5" aria-hidden="true" />{{
                                    active.minutes
                                }}
                                min de leitura</span
                            >
                        </div>
                        <h2 id="chapter-heading" ref="heading" tabindex="-1">
                            {{ active.title }}
                        </h2>
                        <p>{{ active.summary }}</p>
                        <div class="article-actions">
                            <Link
                                :href="active.url"
                                class="guide-button primary"
                                >Abrir na aplicação
                                <ArrowRight
                                    class="size-4"
                                    aria-hidden="true" /></Link
                            ><button
                                class="guide-button secondary"
                                :aria-pressed="saved"
                                @click="toggle('saved', active.id)"
                            >
                                <Bookmark
                                    class="size-4"
                                    :fill="saved ? 'currentColor' : 'none'"
                                    aria-hidden="true"
                                />{{ saved ? 'Guardado' : 'Guardar capítulo' }}
                            </button>
                        </div>
                    </header>
                    <ol class="guide-steps">
                        <li
                            v-for="(step, index) in active.steps"
                            :key="`${active.id}-${index}`"
                        >
                            <span aria-hidden="true">{{ index + 1 }}</span>
                            <div>
                                <h3>{{ step.title }}</h3>
                                <p>{{ step.body }}</p>
                            </div>
                        </li>
                    </ol>
                    <section
                        v-if="active.scenarios?.length"
                        class="guide-scenarios"
                        :aria-labelledby="`scenarios-${active.id}`"
                    >
                        <p class="guide-kicker">NA PRÁTICA</p>
                        <h3 :id="`scenarios-${active.id}`">
                            Qual documento usar?
                        </h3>
                        <p>
                            Situações ilustrativas para reconhecer a sua
                            operação.
                        </p>
                        <div
                            v-for="scenario in active.scenarios"
                            :key="scenario.code"
                            class="scenario-card"
                        >
                            <span class="scenario-code">{{
                                scenario.code
                            }}</span>
                            <div>
                                <h4>{{ scenario.title }}</h4>
                                <strong>{{ scenario.when }}</strong>
                                <p>{{ scenario.example }}</p>
                                <p>{{ scenario.action }}</p>
                                <p class="scenario-attention">
                                    {{ scenario.attention }}
                                </p>
                            </div>
                        </div>
                        <a
                            v-if="active.source"
                            :href="active.source.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="guide-source"
                            >{{ active.source.label }} ↗</a
                        >
                    </section>
                    <figure
                        v-for="screenshot in active.screenshots"
                        :key="screenshot.src"
                        class="guide-screenshot"
                    >
                        <h3>{{ screenshot.title }}</h3>
                        <p>{{ screenshot.caption }}</p>
                        <a
                            :href="screenshot.src"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="`Ampliar: ${screenshot.title}`"
                            ><img
                                :src="screenshot.src"
                                :alt="screenshot.alt"
                                loading="lazy"
                        /></a>
                        <figcaption>
                            <span class="guide-kicker"
                                >DADOS DE DEMONSTRAÇÃO · TOQUE PARA
                                AMPLIAR</span
                            >
                            <p>{{ screenshot.instruction }}</p>
                        </figcaption>
                    </figure>
                    <aside class="guide-note">
                        <span class="guide-kicker">TENHA EM CONTA</span>
                        <p>{{ active.tip }}</p>
                    </aside>
                    <section
                        class="guide-checklist"
                        :aria-labelledby="`checklist-${active.id}`"
                    >
                        <h3 :id="`checklist-${active.id}`">
                            Antes de continuar
                        </h3>
                        <p>
                            Confirme o que aprendeu. Estes vistos não alteram os
                            dados da empresa.
                        </p>
                        <label
                            v-for="(item, index) in active.checklist"
                            :key="`${active.id}:${index}`"
                            ><input
                                type="checkbox"
                                :checked="
                                    progress.checks.includes(
                                        `${active.id}:${index}`,
                                    )
                                "
                                @change="
                                    toggle('checks', `${active.id}:${index}`)
                                "
                            /><span>{{ item }}</span></label
                        >
                    </section>
                    <details :key="active.id" class="guide-question">
                        <summary>{{ active.question }}</summary>
                        <p>{{ active.answer }}</p>
                    </details>
                    <div class="article-completion">
                        <button
                            class="guide-button"
                            :class="completed ? 'secondary' : 'primary'"
                            :aria-pressed="completed"
                            @click="markRead"
                        >
                            <Check class="size-4" aria-hidden="true" />{{
                                completed
                                    ? 'Lido · marcar como não lido'
                                    : 'Marcar capítulo como lido'
                            }}</button
                        ><span>Ao seu ritmo, sem perder o ponto.</span>
                    </div>
                    <nav
                        class="chapter-pagination"
                        aria-label="Percorrer capítulos"
                    >
                        <button
                            v-if="activeIndex > 0"
                            @click="
                                openChapter(guide.chapters[activeIndex - 1]!.id)
                            "
                        >
                            <ChevronLeft class="size-4" aria-hidden="true" />
                            Anterior</button
                        ><button
                            v-if="activeIndex < guide.chapters.length - 1"
                            class="next-chapter"
                            @click="
                                openChapter(guide.chapters[activeIndex + 1]!.id)
                            "
                        >
                            Próximo capítulo
                            <ChevronRight class="size-4" aria-hidden="true" />
                        </button>
                    </nav>
                    <section class="related-chapters">
                        <p class="guide-kicker">CONTINUE A APRENDER</p>
                        <div>
                            <Link
                                v-for="chapter in related"
                                :key="chapter.id"
                                :href="
                                    guideIndex.url({
                                        query: { chapter: chapter.id },
                                    })
                                "
                                @click.prevent="openChapter(chapter.id)"
                                >{{ chapter.title
                                }}<ArrowRight class="size-4" aria-hidden="true"
                            /></Link>
                        </div>
                    </section>
                </article>
            </div>
            <footer class="guide-footer">
                <span>Edição {{ guide.edition }} · {{ guide.updated_at }}</span
                ><a :href="guidePdf.url()"
                    >Descarregar o guia completo
                    <ArrowDownToLine class="size-4" aria-hidden="true"
                /></a>
            </footer>
            <span class="sr-only" role="status">{{ announcement }}</span>
        </div>
    </AppLayout>
</template>

<style scoped>
.guide-scenarios,
.guide-screenshot {
    margin-block: 2rem;
}
.guide-scenarios > h3,
.guide-screenshot h3 {
    font-size: 1.35rem;
    font-weight: 650;
    margin-block: 0.4rem;
}
.guide-scenarios > p,
.guide-screenshot > p {
    color: var(--guide-muted);
    line-height: 1.6;
}
.scenario-card {
    display: flex;
    align-items: start;
    gap: 1rem;
    padding-block: 1.5rem;
    border-block-end: 1px solid var(--guide-line);
}
.scenario-code {
    flex: none;
    min-inline-size: 2.7rem;
    padding: 0.5rem;
    text-align: center;
    border-radius: 0.5rem;
    background: var(--guide-soft);
    font-weight: 750;
}
.scenario-card h4 {
    font-size: 1.1rem;
    font-weight: 700;
    margin-block-end: 0.5rem;
}
.scenario-card strong {
    font-size: 0.85rem;
}
.scenario-card p {
    margin-block-start: 0.6rem;
    line-height: 1.65;
    font-size: 0.9rem;
}
.scenario-attention {
    color: var(--guide-muted);
    border-inline-start: 2px solid var(--color-accent-400);
    padding-inline-start: 0.8rem;
}
.guide-source {
    display: inline-block;
    margin-block-start: 1rem;
    text-decoration: underline;
    text-underline-offset: 0.2em;
    font-size: 0.8rem;
}
.guide-source:active,
.guide-screenshot a:active {
    opacity: 0.8;
}
.guide-screenshot img {
    inline-size: 100%;
    block-size: auto;
    border: 1px solid var(--guide-line);
    border-radius: 0.6rem;
    margin-block: 1rem;
}
.guide-screenshot figcaption p {
    margin-block-start: 0.5rem;
    font-size: 0.85rem;
    line-height: 1.6;
    color: var(--guide-muted);
}

.guide-shell {
    --guide-ink: oklch(0.22 0 none);
    --guide-muted: oklch(0.48 0 none);
    --guide-line: oklch(0.88 0.005 90);
    --guide-paper: oklch(1 0 none);
    --guide-soft: oklch(0.975 0.005 90);
    color: var(--guide-ink);
    padding: clamp(1rem, 3vw, 3rem);
    max-inline-size: 94rem;
    margin-inline: auto;
}
.dark .guide-shell {
    --guide-ink: oklch(0.95 0 none);
    --guide-muted: oklch(0.72 0 none);
    --guide-line: oklch(0.32 0 none);
    --guide-paper: oklch(0.18 0 none);
    --guide-soft: oklch(0.23 0 none);
}
.guide-hero {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: clamp(2rem, 5vw, 5rem);
    padding-block: 1rem 2.5rem;
}
.hero-copy {
    flex: 999 1 28rem;
    min-inline-size: min(100%, 18rem);
    display: flex;
    flex-direction: column;
    gap: 1.4rem;
}
.guide-kicker {
    font-size: 0.68rem;
    letter-spacing: 0.14em;
    font-weight: 700;
    color: var(--guide-muted);
}
h1 {
    font-size: clamp(2.45rem, 4.5vw, 4.3rem);
    line-height: 1.04;
    letter-spacing: -0.045em;
    font-weight: 650;
}
.hero-summary {
    color: var(--guide-muted);
    font-size: 1rem;
    line-height: 1.65;
    max-inline-size: 35rem;
}
.hero-actions,
.article-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
}
.guide-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    min-block-size: 2.75rem;
    padding: 0.65rem 1.1rem;
    border-radius: 99px;
    font-size: 0.84rem;
    font-weight: 650;
    cursor: pointer;
}
.primary {
    background: var(--guide-ink);
    color: var(--guide-paper);
}
.secondary {
    border: 1px solid var(--guide-line);
    background: var(--guide-paper);
    color: var(--guide-ink);
}
.pdf-card {
    flex: 1 1 15rem;
    max-inline-size: 21rem;
    display: flex;
    flex-direction: column;
    gap: 1rem;
    background: var(--guide-soft);
    border: 1px solid var(--guide-line);
    border-radius: 1.25rem;
    padding: 1.3rem;
}
.mini-cover {
    background: oklch(0.985 0.005 90);
    color: oklch(0.22 0 none);
    border: 1px solid oklch(0.87 0.005 90);
    padding: 1.6rem;
    inline-size: 11rem;
    margin-inline: auto;
    aspect-ratio: 210 / 270;
    display: flex;
    flex-direction: column;
    align-items: start;
    justify-content: space-between;
    box-shadow: 0.35rem 0.35rem 0 oklch(0.83 0.02 85);
}
.mini-brand {
    inline-size: 7rem;
    block-size: auto;
    color: var(--color-brand-950);
}
.mini-cover strong {
    font-size: 1.85rem;
    line-height: 1.05;
    letter-spacing: -0.035em;
}
.mini-cover small {
    font-size: 0.4rem;
    letter-spacing: 0.08em;
}
.mini-developer {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.32rem;
}
.mini-developer img {
    inline-size: 1.5rem;
    block-size: auto;
}
.mini-art {
    inline-size: 100%;
    block-size: auto;
}
.pdf-caption {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    font-size: 0.85rem;
}
.pdf-caption small {
    display: block;
    font-size: 0.73rem;
    color: var(--guide-muted);
    margin-block-start: 0.2rem;
}
.reading-progress {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
    border-block: 1px solid var(--guide-line);
    padding-block: 1.2rem;
    margin-block-end: 2rem;
}
.progress-copy {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
    min-inline-size: 12rem;
    font-size: 0.83rem;
}
.progress-copy span {
    font-size: 0.73rem;
    color: var(--guide-muted);
}
.progress-percent {
    font-size: 0.8rem;
    font-variant-numeric: tabular-nums;
}
progress {
    inline-size: clamp(5rem, 15vw, 13rem);
    block-size: 0.35rem;
    border: 0;
    border-radius: 99px;
    overflow: clip;
    background: var(--guide-line);
}
progress::-webkit-progress-bar {
    background: var(--guide-line);
}
progress::-webkit-progress-value {
    background: var(--color-accent-400);
}
progress::-moz-progress-bar {
    background: var(--color-accent-400);
}
.guide-workspace {
    display: flex;
    flex-wrap: wrap;
    gap: clamp(1.25rem, 3vw, 2.5rem);
    align-items: start;
}
.guide-library {
    flex: 1 1 17rem;
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
}
.guide-article {
    flex: 999 1 35rem;
    min-inline-size: min(100%, 24rem);
    background: var(--guide-paper);
    border: 1px solid var(--guide-line);
    border-radius: 1.25rem;
    padding: clamp(1.25rem, 3vw, 3rem);
}
.search-label {
    font-size: 0.82rem;
    font-weight: 650;
}
.guide-search {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    border: 1px solid var(--guide-line);
    border-radius: 0.7rem;
    padding-inline: 0.75rem;
    background: var(--guide-paper);
}
.guide-search input {
    inline-size: 100%;
    min-inline-size: 0;
    min-block-size: 2.9rem;
    background: transparent;
    font-size: 0.85rem;
}
.guide-search button {
    min-inline-size: 2rem;
    min-block-size: 2.75rem;
    display: grid;
    place-items: center;
}
.category-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 0.3rem;
}
.category-filters button {
    font-size: 0.7rem;
    border-radius: 99px;
    padding: 0.6rem 0.75rem;
    border: 1px solid var(--guide-line);
    min-block-size: 2.5rem;
}
.category-filters button[aria-pressed='true'] {
    background: var(--guide-ink);
    color: var(--guide-paper);
    border-color: var(--guide-ink);
}
.saved-filter {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.77rem;
    min-block-size: 2.5rem;
    align-self: start;
}
.saved-filter[aria-pressed='true'] {
    font-weight: 750;
}
.saved-filter span {
    background: var(--guide-line);
    border-radius: 99px;
    padding-inline: 0.5rem;
}
.result-count {
    font-size: 0.7rem;
    color: var(--guide-muted);
}
.chapter-list {
    display: flex;
    flex-direction: column;
    gap: 0.3rem;
    max-block-size: 65vh;
    overflow-y: auto;
    padding: 0.2rem;
}
.chapter-list a {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    padding: 0.85rem 0.7rem;
    border-radius: 0.7rem;
    border: 1px solid transparent;
}
.chapter-list a[aria-current='page'] {
    background: var(--guide-paper);
    border-color: var(--guide-line);
    box-shadow: 0 2px 5px oklch(0 0 none / 0.03);
}
.chapter-number {
    font-size: 0.72rem;
    color: var(--guide-muted);
    flex: 0 0 1.3rem;
    font-variant-numeric: tabular-nums;
}
.chapter-list a > span:nth-child(2) {
    flex: 1;
}
.chapter-list strong {
    display: block;
    font-size: 0.83rem;
    line-height: 1.3;
    font-weight: 600;
}
.chapter-list small {
    display: block;
    color: var(--guide-muted);
    font-size: 0.65rem;
    margin-block-start: 0.25rem;
}
.guide-empty {
    display: flex;
    flex-direction: column;
    align-items: start;
    gap: 0.75rem;
    padding-block: 1.5rem;
    font-size: 0.85rem;
}
.guide-empty p {
    color: var(--guide-muted);
}
.article-header {
    display: flex;
    flex-direction: column;
    gap: 1.1rem;
    padding-block-end: 2rem;
    border-block-end: 2px solid var(--color-accent-400);
}
.article-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    justify-content: space-between;
    font-size: 0.67rem;
    font-weight: 600;
    color: var(--guide-muted);
}
.article-meta span {
    display: inline-flex;
    gap: 0.4rem;
    align-items: center;
}
h2 {
    font-size: clamp(1.85rem, 3vw, 2.7rem);
    font-weight: 650;
    line-height: 1.1;
    letter-spacing: -0.035em;
    scroll-margin-block-start: 6rem;
}
.article-header > p {
    font-size: 1rem;
    color: var(--guide-muted);
    line-height: 1.6;
}
.guide-steps {
    display: flex;
    flex-direction: column;
    gap: 1.8rem;
    padding-block: 2rem;
}
.guide-steps li {
    display: flex;
    align-items: start;
    gap: 1rem;
}
.guide-steps li > span {
    flex: 0 0 1.7rem;
    display: grid;
    place-items: center;
    block-size: 1.7rem;
    border: 1px solid var(--guide-line);
    border-radius: 50%;
    font-size: 0.75rem;
}
h3 {
    font-size: 1.06rem;
    font-weight: 650;
}
.guide-steps p {
    margin-block-start: 0.4rem;
    font-size: 0.9rem;
    line-height: 1.8;
    color: var(--guide-muted);
}
.guide-note {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
    padding: 1.2rem;
    border-inline-start: 3px solid var(--color-accent-400);
    background: var(--guide-soft);
    font-size: 0.86rem;
    line-height: 1.7;
}
.guide-checklist {
    display: flex;
    flex-direction: column;
    gap: 0.85rem;
    padding-block: 2rem;
}
.guide-checklist > p {
    color: var(--guide-muted);
    font-size: 0.8rem;
}
.guide-checklist label {
    display: flex;
    align-items: start;
    gap: 0.7rem;
    font-size: 0.88rem;
    line-height: 1.5;
    cursor: pointer;
    min-block-size: 2.4rem;
}
.guide-checklist input {
    flex: 0 0 1.15rem;
    block-size: 1.15rem;
    margin-block-start: 0.1rem;
    accent-color: var(--guide-ink);
}
.guide-question {
    border-block: 1px solid var(--guide-line);
    padding-block: 1rem;
}
.guide-question summary {
    cursor: pointer;
    font-weight: 600;
    font-size: 0.9rem;
    padding-block: 0.25rem;
}
.guide-question p {
    margin-block-start: 0.75rem;
    color: var(--guide-muted);
    font-size: 0.85rem;
    line-height: 1.7;
}
.article-completion {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 1rem;
    padding-block: 1.75rem;
}
.article-completion span {
    font-size: 0.73rem;
    color: var(--guide-muted);
}
.chapter-pagination {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    border-block-start: 1px solid var(--guide-line);
    padding-block-start: 1rem;
}
.chapter-pagination button {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    min-block-size: 2.75rem;
}
.next-chapter {
    margin-inline-start: auto;
}
.related-chapters {
    padding-block-start: 1.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
}
.related-chapters > div {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.related-chapters a {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
    border: 1px solid var(--guide-line);
    border-radius: 0.5rem;
    padding: 0.65rem;
    font-size: 0.75rem;
}
.guide-footer {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 1rem;
    padding-block: 2rem 0.5rem;
    color: var(--guide-muted);
    font-size: 0.73rem;
}
.guide-footer a {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-block-size: 2.75rem;
}
:is(button, a, input, summary):focus-visible {
    outline: 2px solid var(--color-accent-600);
    outline-offset: 3px;
}
:is(button, a, summary):active {
    opacity: 0.75;
}
@media (hover: hover) and (pointer: fine) {
    .guide-button:hover,
    .chapter-list a:hover,
    .related-chapters a:hover,
    .category-filters button:hover {
        box-shadow: 0 0 0 2px var(--guide-line);
    }
    .pdf-card:hover {
        border-color: var(--guide-muted);
    }
}
</style>
