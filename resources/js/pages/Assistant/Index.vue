<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3';
import { FileText, LoaderCircle, UserRound, X } from '@lucide/vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import AssistantPrivacyNotice from '@/components/AssistantPrivacyNotice.vue';
import AssistantResultCard from '@/components/AssistantResultCard.vue';
import PageHeader from '@/components/PageHeader.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import {
    acceptsResponse,
    assistantFailure,
    assistantReason,
    contextKey,
} from '@/lib/assistant';
import type { AssistantContext, AssistantResponse } from '@/lib/assistant';
import { search } from '@/routes';
import { acknowledgement, store } from '@/routes/assistant';

type ProviderNotice = {
    policy: string;
    notice_url: string | null;
    available: boolean;
    acknowledged: boolean;
};
type ReferenceKind = 'customer' | 'document';
type Reference = { kind: ReferenceKind; public_id: string; label: string };
type Candidate = Reference & { hint: string };
type SearchResponse = {
    documents: {
        public_id: string;
        document_no: string | null;
        document_type_label: string;
        document_date: string;
        customer_name: string | null;
    }[];
    customers: {
        public_id: string;
        name: string;
        tax_identification_number: string | null;
        is_active: boolean;
    }[];
};

const MAX_REFERENCES = 2;
const MIN_SEARCH_LENGTH = 2;
const SEARCH_DEBOUNCE_MS = 200;

const primaryButton =
    'inline-flex h-10 items-center justify-center gap-2 rounded-full bg-brand-950 px-[1.125rem] text-sm font-semibold text-white focus-ring transition hover:bg-brand-800 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-zinc-100 dark:text-brand-950 dark:hover:bg-white';
const outlineButton =
    'inline-flex h-10 items-center justify-center rounded-full px-[1.125rem] text-sm font-semibold text-zinc-800 ring-1 ring-zinc-900/10 focus-ring transition ring-inset hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-50 dark:text-zinc-200 dark:ring-white/15 dark:hover:bg-white/5';
const wellClasses = 'rounded-2xl bg-zinc-900/[0.04] p-5 dark:bg-white/[0.05]';

const props = defineProps<{
    context: AssistantContext;
    provider?: ProviderNotice;
}>();
const unavailableNotice = (): ProviderNotice => ({
    policy: '',
    notice_url: null,
    available: false,
    acknowledged: false,
});
const providerState = ref<ProviderNotice>(
    props.provider ?? unavailableNotice(),
);
const acknowledgementHttp = useHttp<
    { policy: string; acknowledged: boolean },
    { context: AssistantContext; provider: ProviderNotice }
>({ policy: '', acknowledged: false });
const answer = ref<AssistantResponse | null>(null);
const error = ref('');
const questionField = ref<HTMLTextAreaElement | null>(null);
const referenceField = ref<HTMLInputElement | null>(null);
const references = ref<Reference[]>([]);
const referenceQuery = ref('');
const candidates = ref<Candidate[]>([]);
const answeredQuery = ref('');
const activeCandidate = ref(-1);
const pickerFocused = ref(false);
const documentPrompt = ref(false);
const http = useHttp<
    {
        request_nonce: string;
        question: string;
        references: { kind: string; public_id: string }[];
    },
    AssistantResponse
>({ request_nonce: '', question: '', references: [] });
const searchHttp = useHttp<{ q: string }, SearchResponse>({ q: '' });
let generation = 0;
let searchToken = 0;
let searchTimer: ReturnType<typeof setTimeout> | undefined;

const trimmedQuery = computed(() => referenceQuery.value.trim());
const canAddReference = computed(
    () => references.value.length < MAX_REFERENCES,
);
const hasDocumentReference = computed(() =>
    references.value.some((reference) => reference.kind === 'document'),
);
const isSearching = computed(
    () =>
        trimmedQuery.value.length >= MIN_SEARCH_LENGTH &&
        answeredQuery.value !== trimmedQuery.value,
);
const visibleCandidates = computed(() =>
    answeredQuery.value === trimmedQuery.value && canAddReference.value
        ? candidates.value.filter(
              (candidate) =>
                  !references.value.some(
                      (reference) =>
                          reference.kind === candidate.kind &&
                          reference.public_id === candidate.public_id,
                  ),
          )
        : [],
);
const listOpen = computed(
    () =>
        pickerFocused.value &&
        canAddReference.value &&
        trimmedQuery.value.length >= MIN_SEARCH_LENGTH,
);
const canAsk = computed(
    () => providerState.value.available && providerState.value.acknowledged,
);

function cancelSearch(): void {
    searchToken++;
    clearTimeout(searchTimer);
    searchHttp.cancel();
}
function clear(): void {
    generation++;
    cancelSearch();
    http.cancel();
    acknowledgementHttp.cancel();
    acknowledgementHttp.reset();
    acknowledgementHttp.response = null;
    http.reset();
    http.response = null;
    references.value = [];
    referenceQuery.value = '';
    candidates.value = [];
    answeredQuery.value = '';
    activeCandidate.value = -1;
    documentPrompt.value = false;
    answer.value = null;
    error.value = '';
}
watch(
    () => [contextKey(props.context), props.provider],
    () => {
        clear();
        providerState.value = props.provider ?? unavailableNotice();
    },
);
const removeNavigationListener = router.on('before', clear);
onBeforeUnmount(() => {
    clear();
    removeNavigationListener();
});

watch(trimmedQuery, (term) => {
    cancelSearch();
    activeCandidate.value = -1;

    if (term.length < MIN_SEARCH_LENGTH) {
        candidates.value = [];
        answeredQuery.value = term;

        return;
    }

    searchTimer = setTimeout(() => void runSearch(term), SEARCH_DEBOUNCE_MS);
});

async function runSearch(term: string): Promise<void> {
    const submitted = ++searchToken;
    searchHttp.cancel();
    searchHttp.q = term;

    try {
        const response = await searchHttp.get(search.url());

        if (submitted !== searchToken) {
            return;
        }

        candidates.value = [
            ...response.customers.map((customer): Candidate => ({
                kind: 'customer',
                public_id: customer.public_id,
                label: customer.name,
                hint: [
                    'Cliente',
                    customer.tax_identification_number
                        ? `NIF ${customer.tax_identification_number}`
                        : null,
                    customer.is_active ? null : 'inactivo',
                ]
                    .filter(Boolean)
                    .join(' · '),
            })),
            ...response.documents.flatMap((document): Candidate[] =>
                document.document_no === null
                    ? []
                    : [
                          {
                              kind: 'document',
                              public_id: document.public_id,
                              label: document.document_no,
                              hint: [
                                  document.document_type_label,
                                  document.customer_name,
                                  document.document_date,
                              ]
                                  .filter(Boolean)
                                  .join(' · '),
                          },
                      ],
            ),
        ];
        answeredQuery.value = term;
    } catch {
        if (submitted === searchToken) {
            candidates.value = [];
            answeredQuery.value = term;
        }
    }
}

function addReference(candidate: Candidate | undefined): void {
    if (!candidate || !canAddReference.value) {
        return;
    }

    references.value = [
        ...references.value,
        {
            kind: candidate.kind,
            public_id: candidate.public_id,
            label: candidate.label,
        },
    ];
    referenceQuery.value = '';
    documentPrompt.value = false;

    void nextTick(() => referenceField.value?.focus());
}
function removeReference(reference: Reference): void {
    references.value = references.value.filter((item) => item !== reference);
    void nextTick(() => referenceField.value?.focus());
}
function moveCandidate(step: number): void {
    const total = visibleCandidates.value.length;

    if (total === 0) {
        return;
    }

    activeCandidate.value = (activeCandidate.value + step + total) % total;
}
/** Enter in the picker never sends the question; it only picks a match. */
function chooseActiveCandidate(event: KeyboardEvent): void {
    event.preventDefault();

    const candidate = visibleCandidates.value[activeCandidate.value];

    if (listOpen.value && candidate) {
        addReference(candidate);
    }
}

function fillQuestion(text: string): void {
    http.question = text;
    documentPrompt.value = false;

    void nextTick(() => {
        const field = questionField.value;

        field?.focus();
        field?.setSelectionRange(text.length, text.length);
    });
}
function suggestDocumentStatus(): void {
    if (hasDocumentReference.value) {
        fillQuestion('Qual é o estado deste documento na AGT?');

        return;
    }

    documentPrompt.value = true;
    referenceField.value?.focus();
}

async function setAcknowledgement(acknowledged: boolean): Promise<void> {
    if (acknowledgementHttp.processing) {
        return;
    }

    clear();
    const submitted = ++generation;
    const expected = { ...props.context };
    acknowledgementHttp.policy = providerState.value.policy;
    acknowledgementHttp.acknowledged = acknowledged;

    try {
        const response = await acknowledgementHttp.post(
            acknowledgement.url({
                workspacePublicId: expected.workspace_public_id,
                entityPublicId: expected.legal_entity_public_id,
                environment: expected.environment,
            }),
        );

        if (
            acceptsResponse(
                props.context,
                response.context,
                generation,
                submitted,
            )
        ) {
            providerState.value = response.provider;
        }
    } catch (failure) {
        if (generation === submitted) {
            error.value = assistantFailure(
                failure,
                'Não foi possível actualizar a confirmação. Tente novamente.',
            );
        }
    }
}
async function submit(): Promise<void> {
    if (
        http.processing ||
        acknowledgementHttp.processing ||
        !providerState.value.available ||
        !providerState.value.acknowledged
    ) {
        return;
    }

    answer.value = null;
    error.value = '';
    const submitted = ++generation;
    const expected = { ...props.context };
    http.request_nonce = crypto.randomUUID();
    http.references = references.value.map(({ kind, public_id }) => ({
        kind,
        public_id,
    }));

    try {
        const response = await http.post(
            store.url({
                workspacePublicId: expected.workspace_public_id,
                entityPublicId: expected.legal_entity_public_id,
                environment: expected.environment,
            }),
        );

        if (
            acceptsResponse(
                props.context,
                response.context,
                generation,
                submitted,
            )
        ) {
            answer.value = response;
        }
    } catch (failure) {
        if (generation === submitted) {
            error.value = assistantFailure(failure);
        }
    }
}

const suggestionClasses =
    'inline-flex h-9 items-center rounded-full bg-zinc-900/[0.04] px-3.5 text-sm text-zinc-700 focus-ring transition hover:bg-zinc-900/[0.07] hover:text-zinc-950 dark:bg-white/[0.06] dark:text-zinc-300 dark:hover:bg-white/10 dark:hover:text-white';
</script>
<template>
    <AppLayout>
        <Head title="Assistente" />
        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-3xl space-y-7">
                <PageHeader
                    eyebrow="Assistente · Só leitura"
                    title="Assistente"
                    description="Pergunte sobre a sua facturação, clientes e documentos."
                />

                <p
                    v-if="!providerState.available"
                    :class="[wellClasses, 'text-sm/6']"
                >
                    O processamento externo de perguntas está indisponível.
                    Utilize a pesquisa e as páginas habituais da aplicação. Não
                    inclua dados pessoais ou segredos nas perguntas.
                </p>

                <section
                    v-if="
                        providerState.available && !providerState.acknowledged
                    "
                    aria-label="Processamento externo de perguntas"
                    :class="[wellClasses, 'space-y-4']"
                >
                    <h2 class="font-semibold text-zinc-950 dark:text-white">
                        Antes de perguntar
                    </h2>
                    <AssistantPrivacyNotice
                        :policy="providerState.policy"
                        :notice-url="providerState.notice_url"
                    />
                    <button
                        type="button"
                        :disabled="acknowledgementHttp.processing"
                        :class="[
                            primaryButton,
                            'h-auto min-h-10 py-2 text-left',
                        ]"
                        @click="setAcknowledgement(true)"
                    >
                        Compreendo como funciona o processamento externo de
                        perguntas
                    </button>
                </section>

                <details
                    v-if="providerState.available && providerState.acknowledged"
                    :class="wellClasses"
                >
                    <summary
                        class="cursor-pointer rounded font-semibold text-zinc-950 focus-ring dark:text-white"
                    >
                        Como funciona
                    </summary>
                    <div class="mt-4">
                        <AssistantPrivacyNotice
                            :policy="providerState.policy"
                            :notice-url="providerState.notice_url"
                        />
                    </div>
                </details>

                <form class="space-y-6" @submit.prevent="submit">
                    <div class="space-y-2">
                        <label
                            class="block text-sm font-semibold text-zinc-950 dark:text-white"
                            for="assistant-question"
                            >Pergunta</label
                        >
                        <textarea
                            id="assistant-question"
                            ref="questionField"
                            v-model="http.question"
                            maxlength="2000"
                            required
                            rows="3"
                            placeholder="Por exemplo: Quanto facturei este mês?"
                            class="form-input"
                        />
                        <ul
                            role="list"
                            aria-label="Perguntas sugeridas"
                            class="flex flex-wrap gap-2 pt-1"
                        >
                            <li>
                                <button
                                    type="button"
                                    :class="suggestionClasses"
                                    @click="
                                        fillQuestion(
                                            'Quanto facturei este mês?',
                                        )
                                    "
                                >
                                    Quanto facturei este mês?
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    :class="suggestionClasses"
                                    @click="
                                        fillQuestion(
                                            'Quanto facturei no mês passado?',
                                        )
                                    "
                                >
                                    Quanto facturei no mês passado?
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    :class="suggestionClasses"
                                    @click="fillQuestion('Procurar o cliente ')"
                                >
                                    Procurar o cliente …
                                </button>
                            </li>
                            <li>
                                <button
                                    type="button"
                                    :aria-disabled="!hasDocumentReference"
                                    :class="[
                                        suggestionClasses,
                                        hasDocumentReference
                                            ? ''
                                            : 'text-zinc-400 hover:text-zinc-500 dark:text-zinc-500',
                                    ]"
                                    @click="suggestDocumentStatus"
                                >
                                    Qual é o estado deste documento na AGT?
                                </button>
                            </li>
                        </ul>
                        <p
                            v-if="documentPrompt && !hasDocumentReference"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{
                                canAddReference
                                    ? 'Escolha primeiro um documento em «Referências» e depois use esta pergunta.'
                                    : 'Remova uma das referências para escolher um documento e depois use esta pergunta.'
                            }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <label
                            class="block text-sm font-semibold text-zinc-950 dark:text-white"
                            for="assistant-reference-search"
                            >Referências
                            <span
                                class="font-normal text-zinc-500 dark:text-zinc-400"
                                >(opcional)</span
                            ></label
                        >
                        <p
                            id="assistant-reference-help"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            Escreva o nome de um cliente ou o número de um
                            documento e escolha até duas referências.
                        </p>
                        <ul
                            v-if="references.length > 0"
                            role="list"
                            aria-label="Referências escolhidas"
                            class="flex flex-wrap gap-2"
                        >
                            <li
                                v-for="reference in references"
                                :key="`${reference.kind}:${reference.public_id}`"
                                class="inline-flex h-9 max-w-full items-center gap-1.5 rounded-full bg-zinc-900/[0.06] pr-1.5 pl-3 text-sm text-zinc-900 dark:bg-white/10 dark:text-white"
                            >
                                <component
                                    :is="
                                        reference.kind === 'customer'
                                            ? UserRound
                                            : FileText
                                    "
                                    class="size-4 shrink-0 text-zinc-400"
                                    aria-hidden="true"
                                />
                                <span class="truncate">{{
                                    reference.label
                                }}</span>
                                <button
                                    type="button"
                                    class="grid size-6 shrink-0 place-items-center rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/10 hover:text-zinc-950 dark:hover:bg-white/10 dark:hover:text-white"
                                    @click="removeReference(reference)"
                                >
                                    <span class="sr-only"
                                        >Remover {{ reference.label }}</span
                                    >
                                    <X class="size-3.5" aria-hidden="true" />
                                </button>
                            </li>
                        </ul>
                        <div v-if="canAddReference" class="relative">
                            <input
                                id="assistant-reference-search"
                                ref="referenceField"
                                v-model="referenceQuery"
                                type="search"
                                role="combobox"
                                autocomplete="off"
                                maxlength="80"
                                aria-autocomplete="list"
                                aria-controls="assistant-reference-options"
                                aria-describedby="assistant-reference-help"
                                :aria-expanded="listOpen"
                                :aria-activedescendant="
                                    activeCandidate >= 0
                                        ? `assistant-reference-option-${activeCandidate}`
                                        : undefined
                                "
                                placeholder="Nome do cliente ou número do documento"
                                class="form-input"
                                @focus="pickerFocused = true"
                                @blur="pickerFocused = false"
                                @keydown.down.prevent="moveCandidate(1)"
                                @keydown.up.prevent="moveCandidate(-1)"
                                @keydown.enter="chooseActiveCandidate"
                                @keydown.esc="referenceQuery = ''"
                            />
                            <div
                                v-show="listOpen"
                                class="absolute inset-x-0 top-full z-20 mt-2 menu-panel p-1.5"
                            >
                                <p
                                    v-if="isSearching"
                                    class="flex items-center gap-2 px-2.5 py-2 text-sm text-zinc-500"
                                    role="status"
                                >
                                    <LoaderCircle
                                        class="size-4 animate-spin"
                                        aria-hidden="true"
                                    />
                                    A procurar…
                                </p>
                                <p
                                    v-else-if="visibleCandidates.length === 0"
                                    class="px-2.5 py-2 text-sm text-zinc-500"
                                    role="status"
                                >
                                    Nenhum cliente ou documento encontrado.
                                </p>
                                <ul
                                    id="assistant-reference-options"
                                    role="listbox"
                                    aria-label="Resultados da pesquisa"
                                >
                                    <li
                                        v-for="(
                                            candidate, index
                                        ) in visibleCandidates"
                                        :id="`assistant-reference-option-${index}`"
                                        :key="`${candidate.kind}:${candidate.public_id}`"
                                        role="option"
                                        :aria-selected="
                                            index === activeCandidate
                                        "
                                        :class="[
                                            index === activeCandidate
                                                ? 'bg-zinc-100 dark:bg-white/5'
                                                : '',
                                            'flex cursor-pointer items-center gap-2.5 rounded-xl px-2.5 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-white/5',
                                        ]"
                                        @mousedown.prevent="
                                            addReference(candidate)
                                        "
                                    >
                                        <component
                                            :is="
                                                candidate.kind === 'customer'
                                                    ? UserRound
                                                    : FileText
                                            "
                                            class="size-4 shrink-0 text-zinc-400"
                                            aria-hidden="true"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span
                                                class="block truncate font-medium"
                                                >{{ candidate.label }}</span
                                            >
                                            <span
                                                class="block truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                >{{ candidate.hint }}</span
                                            >
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <button
                            type="submit"
                            :disabled="
                                http.processing ||
                                acknowledgementHttp.processing ||
                                !providerState.available ||
                                !providerState.acknowledged
                            "
                            :class="primaryButton"
                        >
                            <LoaderCircle
                                v-if="http.processing"
                                class="size-4 animate-spin"
                                aria-hidden="true"
                            />
                            {{ http.processing ? 'A consultar…' : 'Consultar' }}
                        </button>
                        <p
                            v-if="!canAsk"
                            class="text-sm text-zinc-600 dark:text-zinc-400"
                        >
                            {{
                                providerState.available
                                    ? 'Confirme o aviso acima para poder perguntar.'
                                    : 'Indisponível de momento.'
                            }}
                        </p>
                    </div>
                </form>

                <p
                    v-if="error"
                    role="alert"
                    class="rounded-2xl bg-rose-50 px-4 py-3 text-sm/6 text-rose-800 dark:bg-rose-400/10 dark:text-rose-200"
                >
                    {{ error }}
                </p>

                <div aria-live="polite" :aria-busy="http.processing">
                    <section v-if="answer" class="space-y-3">
                        <p
                            v-if="answer.outcome !== 'answered'"
                            :class="[wellClasses, 'text-sm/6']"
                        >
                            {{ assistantReason(answer.reason) }}
                        </p>
                        <AssistantResultCard
                            v-for="result in answer.results"
                            :key="result.result_id"
                            :result="result"
                            :context="answer.context"
                        />
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Cada fonte representa uma consulta própria.
                        </p>
                        <details class="text-sm">
                            <summary
                                class="cursor-pointer rounded text-zinc-600 focus-ring dark:text-zinc-400"
                            >
                                Detalhes técnicos
                            </summary>
                            <dl
                                class="mt-3 grid gap-x-4 gap-y-1 font-mono text-xs break-all text-zinc-600 sm:grid-cols-[auto_1fr] dark:text-zinc-400"
                            >
                                <dt>Pedido</dt>
                                <dd>{{ answer.request_id }}</dd>
                                <dt>Empresa</dt>
                                <dd>
                                    {{ answer.context.workspace_public_id }}
                                </dd>
                                <dt>Entidade</dt>
                                <dd>
                                    {{ answer.context.legal_entity_public_id }}
                                </dd>
                                <dt>Ambiente</dt>
                                <dd>{{ answer.context.environment }}</dd>
                            </dl>
                        </details>
                    </section>
                </div>

                <div v-if="providerState.acknowledged">
                    <button
                        type="button"
                        :disabled="acknowledgementHttp.processing"
                        :class="outlineButton"
                        @click="setAcknowledgement(false)"
                    >
                        Retirar a confirmação e impedir novos envios
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
