<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Download,
    FileSpreadsheet,
    LoaderCircle,
    Save,
    Trash2,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import DateInput from '@/components/DateInput.vue';
import PageHeader from '@/components/PageHeader.vue';
import SelectInput from '@/components/SelectInput.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { download, file, history, preview } from '@/routes/transaction-reports';

interface ExportStatus {
    id: string;
    title: string;
    status: 'queued' | 'processing' | 'ready' | 'failed' | 'expired';
    processed: number;
    total: number;
    failure: string | null;
    created_at: string;
    expires_at: string;
}
interface Option {
    value: string;
    label: string;
}
interface Column {
    label: string;
    format: string;
    width: number;
}
interface Filters {
    title: string;
    from: string | null;
    to: string | null;
    columns: string[];
    types: string[];
    statuses: string[];
    establishment: string;
    customer: string;
    currency: string;
    payment_method: string;
    include_lines: boolean;
    include_payments: boolean;
}
interface Preset {
    name: string;
    columns: string[];
    types: string[];
    include_lines: boolean;
    include_payments: boolean;
}
const props = defineProps<{
    filters: Partial<Filters>;
    columns: Record<string, Column>;
    types: Option[];
    statuses: Option[];
    establishments: Option[];
    paymentMethods: Option[];
    companyName: string;
    preferencesKey: string;
    maxDocuments: number;
    exports: ExportStatus[];
}>();
const form = reactive<Filters>({
    title: 'Relatório de transacções',
    from: null,
    to: null,
    columns: [],
    types: [],
    statuses: [],
    establishment: '',
    customer: '',
    currency: '',
    payment_method: '',
    include_lines: false,
    include_payments: false,
    ...props.filters,
});
const exports = ref<ExportStatus[]>(props.exports);
const historyError = ref('');
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let disposed = false;
const statusLabels = {
    queued: 'Em fila',
    processing: 'A preparar',
    ready: 'Pronto para descarregar',
    failed: 'Não concluído',
    expired: 'Expirado',
};
function dateTime(value: string): string {
    return new Intl.DateTimeFormat('pt-AO', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(value));
}
function schedulePoll(delay = 5000): void {
    clearTimeout(pollTimer);

    if (
        !disposed &&
        exports.value.some((item) =>
            ['queued', 'processing'].includes(item.status),
        )
    ) {
        pollTimer = setTimeout(refreshExports, delay);
    }
}
async function refreshExports(): Promise<void> {
    try {
        const response = await fetch(history.url(), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (
            !response.ok ||
            !(response.headers.get('content-type') ?? '').includes(
                'application/json',
            )
        ) {
            throw new Error('Unable to refresh exports');
        }

        const data: ExportStatus[] = await response.json();

        if (disposed) {
            return;
        }

        exports.value = data;
        historyError.value = '';
        schedulePoll();
    } catch {
        if (disposed) {
            return;
        }

        historyError.value =
            'Não foi possível actualizar o estado. Pode voltar a esta página para consultar os relatórios.';
        schedulePoll(30000);
    }
}
onUnmounted(() => {
    disposed = true;
    clearTimeout(pollTimer);
});
const requiredColumns = ['document_no', 'document_date', 'currency_code'];
const busy = ref<'preview' | 'download' | null>(null);
const errors = ref<string[]>([]);
const notice = ref('');
const result = ref<{
    count: number;
    rows: Record<string, string | number | null>[];
} | null>(null);
const previewStale = ref(false);
const presets = ref<Preset[]>([]);
const presetName = ref('');
const selectedPreset = ref('');
const availableColumns = computed(() =>
    Object.keys(props.columns).filter((key) => !form.columns.includes(key)),
);
const tooMany = computed(
    () =>
        result.value !== null &&
        !previewStale.value &&
        result.value.count > props.maxDocuments,
);
const allEstablishments = computed(() => [
    { value: '', label: 'Todos os estabelecimentos' },
    ...props.establishments,
]);
const allMethods = computed(() => [
    { value: '', label: 'Todos os meios' },
    ...props.paymentMethods,
]);
watch(
    form,
    () => {
        previewStale.value = true;
        notice.value = '';
    },
    { deep: true },
);

onMounted(() => {
    schedulePoll();

    try {
        const saved: unknown = JSON.parse(
            localStorage.getItem(props.preferencesKey) ?? '[]',
        );

        if (Array.isArray(saved)) {
            presets.value = saved
                .filter(
                    (entry): entry is Preset =>
                        entry &&
                        typeof entry.name === 'string' &&
                        Array.isArray(entry.columns) &&
                        requiredColumns.every((key) =>
                            entry.columns.includes(key),
                        ) &&
                        entry.columns.every(
                            (key: unknown) =>
                                typeof key === 'string' && key in props.columns,
                        ) &&
                        Array.isArray(entry.types) &&
                        entry.types.every((key: unknown) =>
                            props.types.some((type) => type.value === key),
                        ) &&
                        typeof entry.include_lines === 'boolean' &&
                        typeof entry.include_payments === 'boolean',
                )
                .slice(0, 10);
        }
    } catch {
        presets.value = [];
    }
});

function moveColumn(index: number, offset: number): void {
    const next = index + offset;

    if (next < 0 || next >= form.columns.length) {
        return;
    }

    [form.columns[index], form.columns[next]] = [
        form.columns[next],
        form.columns[index],
    ];
}
function savePreset(): void {
    const name = presetName.value.trim();

    if (!name) {
        notice.value = 'Dê um nome ao modelo.';

        return;
    }

    const remaining = presets.value.filter((preset) => preset.name !== name);

    if (remaining.length >= 10) {
        notice.value = 'Pode guardar até 10 modelos neste navegador.';

        return;
    }

    const next = [
        ...remaining,
        {
            name,
            columns: [...form.columns],
            types: [...form.types],
            include_lines: form.include_lines,
            include_payments: form.include_payments,
        },
    ];

    try {
        localStorage.setItem(props.preferencesKey, JSON.stringify(next));
        presets.value = next;
        selectedPreset.value = name;
        notice.value = 'Modelo guardado neste navegador.';
    } catch {
        notice.value = 'O navegador não permitiu guardar o modelo.';
    }
}
function loadPreset(name: string): void {
    const preset = presets.value.find((item) => item.name === name);

    if (!preset) {
        return;
    }

    form.columns = [...preset.columns];
    form.types = [...preset.types];
    form.include_lines = preset.include_lines;
    form.include_payments = preset.include_payments;
    presetName.value = preset.name;
}
function removePreset(): void {
    const next = presets.value.filter(
        (item) => item.name !== selectedPreset.value,
    );

    try {
        localStorage.setItem(props.preferencesKey, JSON.stringify(next));
        presets.value = next;
        selectedPreset.value = '';
    } catch {
        notice.value = 'Não foi possível remover o modelo.';
    }
}
function preset(kind: 'sales' | 'payments' | 'complete'): void {
    form.types =
        kind === 'sales'
            ? ['FT', 'FR', 'TV', 'GF', 'ND', 'NC']
            : kind === 'payments'
              ? ['FR', 'TV', 'RG']
              : [];
    form.columns =
        kind === 'complete'
            ? Object.keys(props.columns)
            : kind === 'payments'
              ? [
                    'document_no',
                    'document_date',
                    'customer_name',
                    'currency_code',
                    'received',
                    'payment_method',
                    'payment_date',
                ]
              : [...(props.filters.columns ?? requiredColumns)];
    form.include_payments = kind === 'payments';
}
async function run(action: 'preview' | 'download'): Promise<void> {
    busy.value = action;
    errors.value = [];
    notice.value = '';

    try {
        const query = {
            ...form,
            include_lines: form.include_lines ? 1 : 0,
            include_payments: form.include_payments ? 1 : 0,
        };
        const token = document.cookie
            .split('; ')
            .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length);
        const response = await fetch(
            action === 'preview' ? preview.url({ query }) : download.url(),
            action === 'preview'
                ? {
                      headers: { Accept: 'application/json' },
                      credentials: 'same-origin',
                  }
                : {
                      method: 'POST',
                      credentials: 'same-origin',
                      headers: {
                          Accept: 'application/json',
                          'Content-Type': 'application/json',
                          'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                      },
                      body: JSON.stringify(query),
                  },
        );

        if (!response.ok) {
            if (response.status === 422) {
                const payload = await response.json();
                errors.value = Object.values(
                    payload.errors ?? {},
                ).flat() as string[];
            } else {
                errors.value = [
                    response.status === 429
                        ? 'Aguarde um momento antes de voltar a exportar.'
                        : 'Não foi possível preparar o relatório. Verifique a sessão e tente novamente.',
                ];
            }

            return;
        }

        if (response.status === 202) {
            const queued: ExportStatus = await response.json();
            exports.value = [
                queued,
                ...exports.value.filter((item) => item.id !== queued.id),
            ].slice(0, 30);
            notice.value =
                'O relatório está em preparação. Pode sair desta página e voltar mais tarde para o descarregar.';
            schedulePoll();
        } else if (action === 'preview') {
            if (
                !(response.headers.get('content-type') ?? '').includes(
                    'application/json',
                )
            ) {
                throw new Error('Sessão expirada');
            }

            result.value = await response.json();
            previewStale.value = false;
        } else {
            if (
                !(response.headers.get('content-type') ?? '').includes(
                    'spreadsheetml',
                )
            ) {
                throw new Error('Sessão expirada');
            }

            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download = `transaccoes-${form.from}-${form.to}.xlsx`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            notice.value =
                'O ficheiro Excel está pronto. Consulte os downloads do navegador.';
        }
    } catch {
        errors.value = [
            'Não foi possível descarregar os dados. Verifique a ligação e a sessão e tente novamente.',
        ];
    } finally {
        busy.value = null;
    }
}
function cell(value: string | number | null, key: string): string {
    if (value === null) {
        return '—';
    }

    if (props.columns[key].format === 'money' && typeof value === 'number') {
        return new Intl.NumberFormat('pt-AO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(value);
    }

    return String(value);
}
</script>

<template>
    <Head title="Exportar transacções" />
    <AppLayout>
        <div class="report-page">
            <PageHeader
                eyebrow="Análises · Excel"
                title="Os seus dados, o seu relatório"
                :description="`${companyName} · Escolha os movimentos e organize as colunas para trabalhar no Excel.`"
            />
            <section
                v-if="exports.length"
                class="report-section"
                aria-labelledby="exports-heading"
            >
                <div class="section-heading">
                    <h2 id="exports-heading">Relatórios em segundo plano</h2>
                    <span>Disponíveis durante 24 horas</span>
                </div>
                <p v-if="historyError" class="hint" role="status">
                    {{ historyError }}
                </p>
                <ul class="export-list">
                    <li
                        v-for="item in exports"
                        :key="item.id"
                        class="export-entry"
                    >
                        <div class="export-details">
                            <strong>{{ item.title }}</strong>
                            <span class="hint"
                                >Pedido em {{ dateTime(item.created_at) }}</span
                            >
                            <span role="status">
                                {{ statusLabels[item.status]
                                }}<template v-if="item.status === 'processing'">
                                    · {{ item.processed }} /
                                    {{ item.total }} documentos<template
                                        v-if="item.processed === item.total"
                                    >
                                        · A finalizar o ficheiro</template
                                    ></template
                                >
                            </span>
                            <progress
                                v-if="item.status === 'processing'"
                                :value="item.processed"
                                :max="Math.max(item.total, 1)"
                                :aria-label="`Preparação de ${item.title}`"
                            />
                            <p v-if="item.status === 'failed'" class="hint">
                                {{ item.failure }} Crie uma nova exportação para
                                tentar novamente.
                            </p>
                            <span v-if="item.status === 'ready'" class="hint"
                                >Disponível até
                                {{ dateTime(item.expires_at) }}</span
                            >
                        </div>
                        <a
                            v-if="item.status === 'ready'"
                            :href="file.url(item.id)"
                            class="report-button"
                            :aria-label="`Descarregar ${item.title}`"
                            ><Download :size="16" aria-hidden="true" />
                            Descarregar Excel</a
                        >
                        <LoaderCircle
                            v-if="
                                ['queued', 'processing'].includes(item.status)
                            "
                            :size="20"
                            class="motion-safe:animate-spin"
                            aria-hidden="true"
                        />
                    </li>
                </ul>
            </section>
            <form class="report-form" @submit.prevent="run('preview')">
                <fieldset :disabled="busy !== null" class="report-section">
                    <div class="section-heading">
                        <h2>1. Escolha os movimentos</h2>
                        <span>Documentos emitidos</span>
                    </div>
                    <div class="report-grid">
                        <label class="report-field"
                            ><span class="field-label">Título do relatório</span
                            ><input
                                v-model="form.title"
                                class="form-input"
                                maxlength="100"
                                required
                        /></label>
                        <label class="report-field"
                            ><span class="field-label">Cliente ou NIF</span
                            ><input
                                v-model="form.customer"
                                class="form-input"
                                maxlength="100"
                                placeholder="Todos os clientes"
                        /></label>
                        <div class="report-field">
                            <label for="report-from" class="field-label"
                                >Data inicial</label
                            ><DateInput
                                id="report-from"
                                v-model="form.from"
                                :clearable="false"
                                required
                            />
                        </div>
                        <div class="report-field">
                            <label for="report-to" class="field-label"
                                >Data final</label
                            ><DateInput
                                id="report-to"
                                v-model="form.to"
                                :min-date="form.from ?? undefined"
                                :clearable="false"
                                required
                            />
                        </div>
                        <div class="report-field">
                            <label
                                for="report-establishment"
                                class="field-label"
                                >Estabelecimento</label
                            ><SelectInput
                                id="report-establishment"
                                v-model="form.establishment"
                                :options="allEstablishments"
                            />
                        </div>
                        <div class="report-field">
                            <label for="report-method" class="field-label"
                                >Meio de pagamento</label
                            ><SelectInput
                                id="report-method"
                                v-model="form.payment_method"
                                :options="allMethods"
                            />
                        </div>
                        <label class="report-field"
                            ><span class="field-label">Moeda</span
                            ><input
                                v-model="form.currency"
                                class="form-input"
                                maxlength="3"
                                pattern="[A-Z]{3}"
                                placeholder="Todas · AOA, USD, EUR…"
                                @input="
                                    form.currency = form.currency.toUpperCase()
                                "
                        /></label>
                    </div>
                    <div class="report-field">
                        <span class="field-label">Começar com</span>
                        <div class="button-row">
                            <button
                                type="button"
                                class="report-button"
                                @click="preset('sales')"
                            >
                                Vendas e notas</button
                            ><button
                                type="button"
                                class="report-button"
                                @click="preset('payments')"
                            >
                                Pagamentos</button
                            ><button
                                type="button"
                                class="report-button"
                                @click="preset('complete')"
                            >
                                Relatório completo
                            </button>
                        </div>
                    </div>
                    <fieldset class="report-field">
                        <legend class="field-label">
                            Tipos de documento
                            <span class="hint"
                                >· Nenhum seleccionado inclui todos</span
                            >
                        </legend>
                        <div class="option-grid">
                            <label
                                v-for="type in types"
                                :key="type.value"
                                class="check-option"
                                ><input
                                    v-model="form.types"
                                    type="checkbox"
                                    :value="type.value"
                                /><span
                                    >{{ type.label }}
                                    <span class="hint">{{
                                        type.value
                                    }}</span></span
                                ></label
                            >
                        </div>
                    </fieldset>
                    <fieldset class="report-field">
                        <legend class="field-label">
                            Estado do documento
                            <span class="hint"
                                >· Nenhum seleccionado inclui todos os
                                emitidos</span
                            >
                        </legend>
                        <div class="option-grid">
                            <label
                                v-for="status in statuses"
                                :key="status.value"
                                class="check-option"
                                ><input
                                    v-model="form.statuses"
                                    type="checkbox"
                                    :value="status.value"
                                />{{ status.label }}</label
                            >
                        </div>
                    </fieldset>
                    <p class="hint">
                        O período usa a data de emissão. Rascunhos, orçamentos e
                        guias de transporte não entram nas transacções fiscais.
                    </p>
                </fieldset>
                <fieldset :disabled="busy !== null" class="report-section">
                    <div class="section-heading">
                        <h2>2. Personalize o ficheiro</h2>
                        <span>{{ form.columns.length }} colunas</span>
                    </div>
                    <div class="column-layout">
                        <div class="report-field">
                            <h3 class="field-label">
                                Colunas e ordem no Excel
                            </h3>
                            <ol class="column-list">
                                <li
                                    v-for="(key, index) in form.columns"
                                    :key="key"
                                >
                                    <span class="column-number">{{
                                        index + 1
                                    }}</span
                                    ><span class="column-label">{{
                                        columns[key].label
                                    }}</span
                                    ><button
                                        type="button"
                                        class="icon-button"
                                        :disabled="index === 0"
                                        :aria-label="`Subir ${columns[key].label}`"
                                        @click="moveColumn(index, -1)"
                                    >
                                        <ArrowUp :size="15" /></button
                                    ><button
                                        type="button"
                                        class="icon-button"
                                        :disabled="
                                            index === form.columns.length - 1
                                        "
                                        :aria-label="`Descer ${columns[key].label}`"
                                        @click="moveColumn(index, 1)"
                                    >
                                        <ArrowDown :size="15" /></button
                                    ><button
                                        type="button"
                                        class="icon-button"
                                        :disabled="
                                            requiredColumns.includes(key)
                                        "
                                        :aria-label="`Remover ${columns[key].label}`"
                                        @click="
                                            form.columns = form.columns.filter(
                                                (item) => item !== key,
                                            )
                                        "
                                    >
                                        <Trash2 :size="15" />
                                    </button>
                                </li>
                            </ol>
                        </div>
                        <div class="report-field column-options">
                            <h3 class="field-label">Adicionar colunas</h3>
                            <div class="button-row">
                                <button
                                    v-for="key in availableColumns"
                                    :key="key"
                                    type="button"
                                    class="report-button"
                                    @click="form.columns.push(key)"
                                >
                                    + {{ columns[key].label }}
                                </button>
                                <p
                                    v-if="availableColumns.length === 0"
                                    class="hint"
                                >
                                    Todas as colunas estão incluídas.
                                </p>
                            </div>
                            <div class="report-field">
                                <h3 class="field-label">Folhas adicionais</h3>
                                <label class="check-option"
                                    ><input
                                        v-model="form.include_lines"
                                        type="checkbox"
                                    />Artigos, quantidades, descontos e
                                    impostos</label
                                ><label class="check-option"
                                    ><input
                                        v-model="form.include_payments"
                                        type="checkbox"
                                    />Liquidações: recibos e facturas que
                                    liquidam</label
                                >
                                <p class="hint">
                                    Resumo, transacções e totais por mês
                                    incluídos. As moedas ficam separadas; as
                                    notas de crédito reduzem a facturação. Os
                                    saldos reflectem a data da exportação.
                                </p>
                            </div>
                            <div class="saved-model report-field">
                                <h3 class="field-label">
                                    Reutilizar esta configuração
                                </h3>
                                <div v-if="presets.length" class="button-row">
                                    <SelectInput
                                        v-model="selectedPreset"
                                        :options="
                                            presets.map((item) => ({
                                                value: item.name,
                                                label: item.name,
                                            }))
                                        "
                                        placeholder="Escolher modelo guardado"
                                        aria-label="Modelo guardado"
                                        @update:model-value="loadPreset"
                                    /><button
                                        type="button"
                                        class="icon-button"
                                        :disabled="!selectedPreset"
                                        aria-label="Eliminar modelo guardado"
                                        @click="removePreset"
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                                <label class="report-field"
                                    ><span class="hint">Nome do modelo</span
                                    ><input
                                        v-model="presetName"
                                        class="form-input"
                                        maxlength="60"
                                        placeholder="Ex.: Fecho mensal" /></label
                                ><button
                                    type="button"
                                    class="report-button"
                                    @click="savePreset"
                                >
                                    <Save :size="16" />Guardar modelo
                                </button>
                                <p class="hint">
                                    Guarda colunas, tipos e folhas neste
                                    navegador. As datas e os filtros de cliente
                                    mantêm-se independentes.
                                </p>
                            </div>
                        </div>
                    </div>
                </fieldset>
                <section
                    class="report-section"
                    aria-labelledby="preview-heading"
                >
                    <div class="section-heading">
                        <h2 id="preview-heading">
                            3. Pré-visualize e descarregue
                        </h2>
                        <FileSpreadsheet :size="23" />
                    </div>
                    <p class="hint">
                        Excel editável (.xlsx), com tabelas filtráveis,
                        cabeçalhos fixos e valores numéricos prontos para
                        fórmulas e tabelas dinâmicas. Até
                        {{ maxDocuments.toLocaleString('pt-AO') }} documentos e
                        20 000 linhas de detalhe por exportação.
                    </p>
                    <div v-if="errors.length" role="alert" class="report-error">
                        <p v-for="error in errors" :key="error">{{ error }}</p>
                    </div>
                    <p v-if="notice" role="status" class="hint">{{ notice }}</p>
                    <div class="button-row">
                        <button
                            type="submit"
                            class="report-button"
                            :disabled="busy !== null"
                        >
                            <LoaderCircle
                                v-if="busy === 'preview'"
                                :size="17"
                                class="motion-safe:animate-spin"
                            />Pré-visualizar</button
                        ><button
                            type="button"
                            class="report-button primary"
                            :disabled="busy !== null || tooMany"
                            @click="run('download')"
                        >
                            <LoaderCircle
                                v-if="busy === 'download'"
                                :size="17"
                                class="motion-safe:animate-spin"
                            /><Download v-else :size="17" />{{
                                busy === 'download'
                                    ? 'A preparar Excel…'
                                    : 'Descarregar Excel'
                            }}
                        </button>
                    </div>
                    <template v-if="result"
                        ><p class="hint" aria-live="polite">
                            {{
                                previewStale
                                    ? 'Os filtros mudaram. Actualize a pré-visualização; o download usará a configuração actual.'
                                    : `${result.count.toLocaleString('pt-AO')} documentos encontrados · primeiros 10 abaixo.`
                            }}
                        </p>
                        <p v-if="tooMany" role="alert" class="report-error">
                            Reduza o período ou aplique mais filtros para
                            descarregar.
                        </p>
                        <div
                            v-if="result.rows.length"
                            class="report-table"
                            tabindex="0"
                            aria-label="Pré-visualização das transacções"
                        >
                            <table>
                                <thead>
                                    <tr>
                                        <th
                                            v-for="key in form.columns"
                                            :key="key"
                                        >
                                            {{ columns[key].label }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(row, index) in result.rows"
                                        :key="index"
                                    >
                                        <td
                                            v-for="key in form.columns"
                                            :key="key"
                                            :class="{
                                                numeric:
                                                    columns[key].format ===
                                                    'money',
                                            }"
                                        >
                                            {{ cell(row[key], key) }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-else class="hint">
                            Sem movimentos para os filtros seleccionados. Pode
                            descarregar o ficheiro com os cabeçalhos.
                        </p></template
                    >
                </section>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.export-list {
    display: grid;
    gap: 1rem;
    list-style: none;
    padding: 0;
    margin: 0;
}
.export-entry {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}
.export-details {
    display: grid;
    gap: 0.25rem;
    flex: 1 1 18rem;
    min-inline-size: 0;
    overflow-wrap: break-word;
}
.export-details progress {
    inline-size: min(100%, 24rem);
    accent-color: var(--color-primary);
}

.report-page,
.report-form,
.report-section,
.report-field {
    display: grid;
    gap: 1.25rem;
}
.report-page {
    padding: clamp(1rem, 3vw, 2.5rem);
    max-inline-size: 100rem;
    margin-inline: auto;
}
.report-section {
    border: 1px solid var(--color-zinc-200);
    border-radius: 1rem;
    padding: clamp(1rem, 2vw, 1.75rem);
    background: var(--color-white);
}
.report-field {
    gap: 0.6rem;
}
.report-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 17rem), 1fr));
    gap: 1.25rem;
}
.section-heading,
.button-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.75rem;
}
.section-heading {
    justify-content: space-between;
}
.section-heading h2 {
    font-size: 1.2rem;
    font-weight: 600;
    letter-spacing: -0.02em;
}
.hint,
.section-heading > span {
    color: var(--color-zinc-500);
    font-size: 0.8125rem;
    line-height: 1.6;
    font-weight: 400;
}
.option-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 15rem), 1fr));
    gap: 0.25rem 0.75rem;
}
.check-option {
    display: flex;
    gap: 0.6rem;
    align-items: center;
    font-size: 0.875rem;
    padding-block: 0.5rem;
    cursor: pointer;
}
.check-option input {
    inline-size: 1rem;
    block-size: 1rem;
    accent-color: var(--color-zinc-900);
    flex: none;
}
.column-layout {
    display: flex;
    flex-wrap: wrap;
    gap: 2rem;
    align-items: start;
}
.column-layout > * {
    flex: 1 1 22rem;
    min-inline-size: 0;
}
.column-options {
    gap: 1.75rem;
}
.column-list {
    display: grid;
    gap: 0.35rem;
}
.column-list li {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    border-block-end: 1px solid var(--color-zinc-100);
    padding-block: 0.25rem;
}
.column-number {
    min-inline-size: 1.75rem;
    color: var(--color-zinc-400);
    font-size: 0.75rem;
    font-variant-numeric: tabular-nums;
}
.column-label {
    flex: 1;
    font-size: 0.875rem;
}
.report-button,
.icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-block-size: 2.5rem;
    border: 1px solid var(--color-zinc-200);
    border-radius: 0.625rem;
    padding-inline: 0.875rem;
    font-size: 0.8125rem;
    cursor: pointer;
    background: var(--color-white);
}
.icon-button {
    padding-inline: 0.5rem;
    min-inline-size: 2.5rem;
}
.report-button.primary {
    background: var(--color-zinc-900);
    color: var(--color-white);
    border-color: var(--color-zinc-900);
}
:is(.report-button, .icon-button):disabled {
    opacity: 0.4;
    cursor: default;
}
:is(.report-button, .icon-button):focus-visible,
.report-table:focus-visible {
    outline: 2px solid var(--color-zinc-600);
    outline-offset: 3px;
}
:is(.report-button, .icon-button):active:not(:disabled) {
    opacity: 0.75;
}
@media (hover: hover) and (pointer: fine) {
    :is(.report-button, .icon-button):hover:not(:disabled) {
        border-color: var(--color-zinc-500);
    }
}
.saved-model {
    border-block-start: 1px solid var(--color-zinc-200);
    padding-block-start: 1.25rem;
}
.report-error {
    color: var(--color-red-700);
    font-size: 0.875rem;
}
.report-table {
    overflow: auto;
    max-block-size: 30rem;
    border: 1px solid var(--color-zinc-200);
    border-radius: 0.5rem;
}
.report-table table {
    inline-size: 100%;
    border-collapse: collapse;
    font-size: 0.8125rem;
}
.report-table :is(th, td) {
    padding: 0.75rem;
    text-align: start;
    white-space: nowrap;
    border-block-end: 1px solid var(--color-zinc-200);
}
.report-table th {
    background: var(--color-zinc-100);
    font-weight: 600;
}
.report-table .numeric {
    text-align: end;
    font-variant-numeric: tabular-nums;
}
:global(.dark) .report-section,
:global(.dark) :is(.report-button, .icon-button) {
    background: var(--color-zinc-900);
    border-color: var(--color-zinc-700);
}
:global(.dark) .report-button.primary {
    background: var(--color-white);
    color: var(--color-zinc-900);
}
:global(.dark) .hint,
:global(.dark) .section-heading > span {
    color: var(--color-zinc-400);
}
:global(.dark) .report-table th {
    background: var(--color-zinc-800);
}
:global(.dark)
    :is(
        .column-list li,
        .report-table,
        .report-table td,
        .report-table th,
        .saved-model
    ) {
    border-color: var(--color-zinc-700);
}
:global(.dark) .report-error {
    color: var(--color-red-400);
}
</style>
