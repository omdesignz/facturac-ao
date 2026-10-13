<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    Check,
    ChevronDown,
    ChevronUp,
    Layers,
    LoaderCircle,
    Pencil,
    Plus,
    Trash2,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import FlashBanner from '@/components/FlashBanner.vue';
import FormError from '@/components/FormError.vue';
import PageHeader from '@/components/PageHeader.vue';
import RecordDialog from '@/components/RecordDialog.vue';
import SelectInput from '@/components/SelectInput.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleSwitch from '@/components/ToggleSwitch.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { confirmAction } from '@/lib/confirm';
import { focusFirstInvalid } from '@/lib/focus';
import {
    costsMoreThanMonthly,
    formatMinorWhole,
    minorToInputText,
    monthlyEquivalentMinor,
    parseAmount,
    priceChipLabel,
    savingPercentage,
    slugFromName,
    splitPastedFeatures,
} from '@/lib/packages';
import {
    buttonGold,
    buttonOutline,
    buttonOutlineSmall,
    currencyLabel,
    wellItem,
    wellSection,
} from '@/lib/pos-ui';
import {
    destroy as destroyPackage,
    store as storePackage,
    update as updatePackage,
} from '@/routes/support/packages';
import type { SelectOption } from '@/types/select';

interface PackagePeriod {
    interval: string;
    interval_label: string;
    months: number;
    amount_minor: number;
    amount: string;
    is_active: boolean;
    plan_public_id: string;
    subscribers_count: number;
}

interface PackageRow {
    public_id: string;
    code: string;
    name: string;
    summary: string | null;
    features: string[];
    entitlements: string[];
    trial_days: number;
    currency_code: string;
    is_active: boolean;
    is_featured: boolean;
    sort_order: number;
    subscribers_count: number;
    can_delete: boolean;
    periods: PackagePeriod[];
}

interface IntervalOption {
    value: string;
    label: string;
    months: number;
    period_label: string;
}

/** One line of the «Períodos e preços» group, as it is being edited. */
interface PeriodDraft {
    interval: string;
    enabled: boolean;
    /** The price as typed. */
    amount: string;
}

const MAXIMUM_FEATURES = 20;
const FEATURE_COUNTER_FROM = 15;
const SUMMARY_COUNTER_FROM = 400;
const MONTHLY = 'monthly';
const DRAFT_KEY = 'support-package-draft';
const DRAFT_MAX_AGE_MS = 15 * 60 * 1000;

const props = defineProps<{
    packages: PackageRow[];
    intervals: IntervalOption[];
    currencies: string[];
    featureOptions: { value: string; label: string }[];
    trialDays: number;
}>();

const dialogOpen = ref(false);
const editing = ref<PackageRow | null>(null);
const formElement = ref<HTMLFormElement | null>(null);
const periodsGroup = ref<HTMLElement | null>(null);
const newPackageButton = ref<HTMLButtonElement | null>(null);
const submitButton = ref<HTMLButtonElement | null>(null);
const page = usePage();

/** The dialog was reopened from a draft kept across the password confirmation. */
const restoredDraft = ref(false);

const codeTouched = ref(false);
const featureIds = ref<number[]>([]);
let nextFeatureId = 1;
const featureNotice = ref('');

/** What the client found wrong before sending anything. */
const clientRowErrors = ref<Record<string, string>>({});
const clientPeriodsError = ref<string | null>(null);
/** The intervals in the order they were sent, to map `periods.N.*` errors back. */
const sentIntervals = ref<string[]>([]);

const form = useForm({
    code: '',
    name: '',
    summary: '',
    features: [] as string[],
    entitlements: ['invoicing'] as string[],
    trial_days: String(props.trialDays),
    currency_code: 'AOA',
    sort_order: '0',
    is_active: true,
    is_featured: false,
    periods: [] as PeriodDraft[],
});

const currencyOptions = computed<SelectOption[]>(() =>
    props.currencies.map((code) => ({
        value: code,
        label: currencyLabel(code),
    })),
);

const currencySymbol = computed(() => currencyLabel(form.currency_code));

function errorFor(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

/* ---------------------------------------------------------------- the list */

function activeMonthlyMinor(row: PackageRow): number | null {
    return (
        row.periods.find(
            (period) => period.interval === MONTHLY && period.is_active,
        )?.amount_minor ?? null
    );
}

function chipText(row: PackageRow, period: PackagePeriod): string {
    return priceChipLabel({
        intervalLabel: period.interval_label,
        amountMinor: period.amount_minor,
        months: period.months,
        currency: currencyLabel(row.currency_code),
        // An inactive period is not offered, so it promises no saving.
        monthlyAmountMinor: period.is_active ? activeMonthlyMinor(row) : null,
    });
}

function shownFeatures(row: PackageRow): string[] {
    return row.features.slice(0, 4);
}

function trialText(days: number): string {
    return days === 1 ? '1 dia de experiência' : `${days} dias de experiência`;
}

function subscribersText(count: number): string {
    if (count === 0) {
        return 'Sem subscritores activos';
    }

    return count === 1
        ? '1 subscritor activo'
        : `${count} subscritores activos`;
}

async function remove(row: PackageRow): Promise<void> {
    const confirmed = await confirmAction({
        title: `Apagar ${row.name}?`,
        message:
            'Este pacote nunca teve subscritores, por isso desaparece por completo, com os seus preços. Um pacote que já vendeu só se desactiva.',
        confirmLabel: 'Apagar pacote',
    });

    if (!confirmed) {
        return;
    }

    router.delete(destroyPackage.url(row.public_id), {
        preserveScroll: true,
        onFinish: () => {
            void nextTick(() => newPackageButton.value?.focus());
        },
    });
}

/* ------------------------------------------------------------------ the draft */

/*
 * Saving asks for the password again once the confirmation lapses, and the
 * server answers with a redirect to the confirmation page and back, which
 * throws the dialog away. The form is kept in sessionStorage for that round
 * trip only: written just before a save, removed as soon as the save settles
 * here, and reopened (never resubmitted) when the page comes back.
 */
interface DraftFields {
    code: string;
    name: string;
    summary: string;
    features: string[];
    entitlements: string[];
    trial_days: string;
    currency_code: string;
    sort_order: string;
    is_active: boolean;
    is_featured: boolean;
    periods: PeriodDraft[];
}

interface StoredDraft {
    mode: 'create' | 'edit';
    public_id: string | null;
    savedAt: number;
    fields: DraftFields;
}

function clearDraft(): void {
    try {
        window.sessionStorage.removeItem(DRAFT_KEY);
    } catch {
        // Storage can be blocked; there is then nothing to clean up.
    }
}

function storeDraft(): void {
    const draft: StoredDraft = {
        mode: editing.value === null ? 'create' : 'edit',
        public_id: editing.value?.public_id ?? null,
        savedAt: Date.now(),
        fields: {
            code: form.code,
            name: form.name,
            summary: form.summary,
            features: [...form.features],
            entitlements: [...form.entitlements],
            trial_days: form.trial_days,
            currency_code: form.currency_code,
            sort_order: form.sort_order,
            is_active: form.is_active,
            is_featured: form.is_featured,
            periods: form.periods.map((row) => ({
                interval: row.interval,
                enabled: row.enabled,
                amount: row.amount,
            })),
        },
    };

    try {
        window.sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draft));
    } catch {
        // Without storage the draft is simply not kept.
    }
}

function isText(value: unknown, longest: number): value is string {
    return typeof value === 'string' && value.length <= longest;
}

/** Reads the stored draft, keeping only what has the expected shape. */
function readDraft(): StoredDraft | null {
    let raw: string | null = null;

    try {
        raw = window.sessionStorage.getItem(DRAFT_KEY);
    } catch {
        return null;
    }

    if (raw === null) {
        return null;
    }

    try {
        const data: unknown = JSON.parse(raw);

        if (typeof data !== 'object' || data === null) {
            return null;
        }

        const stored = data as Record<string, unknown>;
        const fields = stored.fields as Record<string, unknown> | undefined;

        if (
            (stored.mode !== 'create' && stored.mode !== 'edit') ||
            typeof stored.savedAt !== 'number' ||
            typeof fields !== 'object' ||
            fields === null ||
            !isText(fields.code, 48) ||
            !isText(fields.name, 200) ||
            !isText(fields.summary, 1000) ||
            !isText(fields.trial_days, 10) ||
            !isText(fields.currency_code, 3) ||
            !isText(fields.sort_order, 10) ||
            typeof fields.is_active !== 'boolean' ||
            typeof fields.is_featured !== 'boolean' ||
            !Array.isArray(fields.features) ||
            fields.features.length > MAXIMUM_FEATURES ||
            !fields.features.every((line) => isText(line, 400)) ||
            !Array.isArray(fields.periods) ||
            fields.periods.length > props.intervals.length
        ) {
            return null;
        }

        const periods: PeriodDraft[] = [];

        for (const row of fields.periods as unknown[]) {
            const period = row as Record<string, unknown>;

            if (
                typeof period !== 'object' ||
                period === null ||
                !isText(period.interval, 20) ||
                typeof period.enabled !== 'boolean' ||
                !isText(period.amount, 40)
            ) {
                return null;
            }

            periods.push({
                interval: period.interval,
                enabled: period.enabled,
                amount: period.amount,
            });
        }

        return {
            mode: stored.mode,
            public_id:
                stored.mode === 'edit' && typeof stored.public_id === 'string'
                    ? stored.public_id
                    : null,
            savedAt: stored.savedAt,
            fields: {
                code: fields.code,
                name: fields.name,
                summary: fields.summary,
                features: fields.features as string[],
                entitlements: Array.isArray(fields.entitlements)
                    ? fields.entitlements.filter(
                          (value): value is string =>
                              typeof value === 'string' &&
                              props.featureOptions.some(
                                  (option) => option.value === value,
                              ),
                      )
                    : ['invoicing'],
                trial_days: fields.trial_days,
                currency_code: fields.currency_code,
                sort_order: fields.sort_order,
                is_active: fields.is_active,
                is_featured: fields.is_featured,
                periods,
            },
        };
    } catch {
        return null;
    }
}

/** Reopens the dialog with a draft that survived the password confirmation. */
function restoreDraft(): void {
    const draft = readDraft();

    // Whatever happens next, the open dialog (or nothing) now owns the draft.
    clearDraft();

    if (draft === null || Date.now() - draft.savedAt > DRAFT_MAX_AGE_MS) {
        return;
    }

    if (draft.mode === 'edit') {
        const row = props.packages.find(
            (candidate) => candidate.public_id === draft.public_id,
        );

        if (!row) {
            return;
        }

        openEdit(row);
    } else {
        openCreate();
    }

    const fields = draft.fields;

    form.code = fields.code;
    form.name = fields.name;
    form.summary = fields.summary;
    form.trial_days = String(props.trialDays);
    form.entitlements = [...new Set(['invoicing', ...fields.entitlements])];
    form.sort_order = fields.sort_order;
    form.is_active = fields.is_active;
    form.is_featured = fields.is_featured;

    if (props.currencies.includes(fields.currency_code)) {
        form.currency_code = fields.currency_code;
    }

    loadFeatures(
        fields.features
            .slice(0, MAXIMUM_FEATURES)
            .map((line) => line.slice(0, 160)),
    );

    for (const row of form.periods) {
        const saved = fields.periods.find(
            (candidate) => candidate.interval === row.interval,
        );

        if (saved) {
            row.enabled = saved.enabled;
            row.amount = saved.amount;
        }
    }

    codeTouched.value = true;
    restoredDraft.value = true;

    // After the dialog's own focus move has run; the note at the top stays in view.
    window.setTimeout(() => {
        submitButton.value?.focus({ preventScroll: true });
    }, 120);
}

onMounted(restoreDraft);

// A success flash means the save went through; no draft may outlive it.
watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            clearDraft();
        }
    },
);

/* --------------------------------------------------------- opening, closing */

function draftPeriods(row: PackageRow | null): PeriodDraft[] {
    return props.intervals.map((interval) => {
        const existing = row?.periods.find(
            (period) => period.interval === interval.value,
        );

        return {
            interval: interval.value,
            enabled: existing
                ? existing.is_active
                : row === null && interval.value === MONTHLY,
            amount: existing ? minorToInputText(existing.amount_minor) : '',
        };
    });
}

function loadFeatures(features: string[]): void {
    form.features = [...features];
    featureIds.value = features.map(() => nextFeatureId++);
}

function resetFeedback(): void {
    clientRowErrors.value = {};
    clientPeriodsError.value = null;
    sentIntervals.value = [];
    featureNotice.value = '';
}

function openCreate(): void {
    editing.value = null;
    restoredDraft.value = false;
    codeTouched.value = false;
    resetFeedback();
    form.defaults({
        code: '',
        name: '',
        summary: '',
        features: [],
        entitlements: ['invoicing'],
        trial_days: String(props.trialDays),
        currency_code: props.currencies[0] ?? 'AOA',
        sort_order: String(
            props.packages.reduce(
                (highest, row) => Math.max(highest, row.sort_order),
                -1,
            ) + 1,
        ),
        is_active: true,
        is_featured: false,
        periods: draftPeriods(null),
    });
    form.reset();
    form.clearErrors();
    loadFeatures([]);
    dialogOpen.value = true;
}

function openEdit(row: PackageRow): void {
    editing.value = row;
    restoredDraft.value = false;
    codeTouched.value = true;
    resetFeedback();
    form.defaults({
        code: row.code,
        name: row.name,
        summary: row.summary ?? '',
        features: [...row.features],
        entitlements: [...row.entitlements],
        trial_days: String(props.trialDays),
        currency_code: row.currency_code,
        sort_order: String(row.sort_order),
        is_active: row.is_active,
        is_featured: row.is_featured,
        periods: draftPeriods(row),
    });
    form.reset();
    form.clearErrors();
    loadFeatures(row.features);
    dialogOpen.value = true;
}

async function requestClose(): Promise<void> {
    if (form.processing) {
        return;
    }

    if (form.isDirty || restoredDraft.value) {
        const discard = await confirmAction({
            title: 'Descartar as alterações?',
            message:
                'O que escreveu neste pacote ainda não foi guardado e perde-se.',
            confirmLabel: 'Descartar',
            cancelLabel: 'Continuar a editar',
        });

        if (!discard) {
            return;
        }
    }

    clearDraft();
    dialogOpen.value = false;
}

/* ------------------------------------------------------------ name -> code */

watch(
    () => form.name,
    (name) => {
        if (editing.value === null && !codeTouched.value) {
            form.code = slugFromName(name);
        }
    },
);

function onCodeInput(event: Event): void {
    const field = event.target as HTMLInputElement;
    const normalised = field.value.toLowerCase().replace(/\s+/g, '-');

    form.code = normalised;
    field.value = normalised;
    codeTouched.value = normalised !== '';
}

/* ----------------------------------------------------------------- periods */

const monthlyDraftMinor = computed<number | null>(() => {
    const monthly = form.periods.find((row) => row.interval === MONTHLY);

    if (!monthly?.enabled) {
        return null;
    }

    const parsed = parseAmount(monthly.amount);

    return parsed !== null && parsed.minor > 0 ? parsed.minor : null;
});

interface RowNote {
    text: string;
    warning: boolean;
}

function rowNote(row: PeriodDraft): RowNote | null {
    const months = props.intervals.find(
        (interval) => interval.value === row.interval,
    )?.months;
    const parsed = parseAmount(row.amount);

    if (!row.enabled || !months || months < 2 || !parsed || parsed.minor <= 0) {
        return null;
    }

    if (costsMoreThanMonthly(parsed.minor, months, monthlyDraftMinor.value)) {
        return { text: 'mais caro do que pagar mês a mês', warning: true };
    }

    const equivalent = `≈ ${formatMinorWhole(monthlyEquivalentMinor(parsed.minor, months))} ${currencySymbol.value} por mês`;
    const saving = savingPercentage(
        parsed.minor,
        months,
        monthlyDraftMinor.value,
    );

    return {
        text: saving === null ? equivalent : `${equivalent} · poupa ${saving}%`,
        warning: false,
    };
}

function intervalLabel(interval: string): string {
    return (
        props.intervals.find((option) => option.value === interval)?.label ??
        interval
    );
}

function periodError(interval: string): string | undefined {
    const index = sentIntervals.value.indexOf(interval);

    return (
        clientRowErrors.value[interval] ??
        (index === -1
            ? undefined
            : (errorFor(`periods.${index}.amount`) ??
              errorFor(`periods.${index}.interval`)))
    );
}

const periodsError = computed(
    () => clientPeriodsError.value ?? errorFor('periods'),
);

function onAmountInput(interval: string): void {
    delete clientRowErrors.value[interval];
}

function describedBy(
    ...ids: (string | false | undefined)[]
): string | undefined {
    const present = ids.filter((id): id is string => typeof id === 'string');

    return present.length > 0 ? present.join(' ') : undefined;
}

/** The periods to send: every switched-on row, and a switched-off row that has a price. */
function periodsToSend(): {
    interval: string;
    amount: string;
    is_active: boolean;
}[] {
    const sent: { interval: string; amount: string; is_active: boolean }[] = [];

    for (const row of form.periods) {
        const parsed = parseAmount(row.amount);

        if (row.enabled) {
            sent.push({
                interval: row.interval,
                amount: parsed?.decimal ?? row.amount.trim(),
                is_active: true,
            });
        } else if (parsed !== null && parsed.minor > 0) {
            sent.push({
                interval: row.interval,
                amount: parsed.decimal,
                is_active: false,
            });
        }
    }

    return sent;
}

/* ---------------------------------------------------------------- features */

const featureCounterVisible = computed(
    () => form.features.length >= FEATURE_COUNTER_FROM,
);
const canAddFeature = computed(() => form.features.length < MAXIMUM_FEATURES);

function featureInputId(id: number): string {
    return `package-feature-${id}`;
}

function focusElement(id: string): void {
    void nextTick(() => document.getElementById(id)?.focus());
}

function addFeature(afterIndex?: number): void {
    if (!canAddFeature.value) {
        featureNotice.value = `Um pacote tem no máximo ${MAXIMUM_FEATURES} linhas.`;

        return;
    }

    const at = afterIndex === undefined ? form.features.length : afterIndex + 1;
    const id = nextFeatureId++;

    form.features.splice(at, 0, '');
    featureIds.value.splice(at, 0, id);
    featureNotice.value = `Linha ${at + 1} adicionada. ${form.features.length} de ${MAXIMUM_FEATURES}.`;
    focusElement(featureInputId(id));
}

function removeFeature(index: number): void {
    form.features.splice(index, 1);
    featureIds.value.splice(index, 1);
    featureNotice.value = `Linha ${index + 1} removida.`;

    if (form.features.length === 0) {
        focusElement('package-feature-add');
    } else {
        focusElement(featureInputId(featureIds.value[Math.max(0, index - 1)]));
    }
}

function moveFeature(index: number, direction: -1 | 1): void {
    const target = index + direction;

    if (target < 0 || target >= form.features.length) {
        return;
    }

    const id = featureIds.value[index];

    [form.features[index], form.features[target]] = [
        form.features[target],
        form.features[index],
    ];
    [featureIds.value[index], featureIds.value[target]] = [
        featureIds.value[target],
        featureIds.value[index],
    ];
    featureNotice.value = `Linha movida para a posição ${target + 1}.`;

    const sameWay = `${featureInputId(id)}-${direction === -1 ? 'up' : 'down'}`;
    const otherWay = `${featureInputId(id)}-${direction === -1 ? 'down' : 'up'}`;
    const atEdge =
        target === 0 && direction === -1
            ? true
            : target === form.features.length - 1 && direction === 1;

    focusElement(atEdge ? otherWay : sameWay);
}

function onFeatureEnter(event: KeyboardEvent, index: number): void {
    // Enter that confirms an input-method composition is not a new line.
    if (event.isComposing) {
        return;
    }

    event.preventDefault();
    addFeature(index);
}

function onFeaturePaste(event: ClipboardEvent, index: number): void {
    const lines = splitPastedFeatures(
        event.clipboardData?.getData('text') ?? '',
    );

    if (lines.length < 2) {
        return;
    }

    event.preventDefault();

    const replacesCurrent = form.features[index].trim() === '';
    const room =
        MAXIMUM_FEATURES - form.features.length + (replacesCurrent ? 1 : 0);
    const accepted = lines.slice(0, Math.max(room, 0));
    const at = replacesCurrent ? index : index + 1;
    const ids = accepted.map(() => nextFeatureId++);

    form.features.splice(at, replacesCurrent ? 1 : 0, ...accepted);
    featureIds.value.splice(at, replacesCurrent ? 1 : 0, ...ids);

    const dropped = lines.length - accepted.length;

    featureNotice.value =
        dropped > 0
            ? `${accepted.length} linhas coladas. ${dropped} ficaram de fora: o máximo é ${MAXIMUM_FEATURES}.`
            : `${accepted.length} linhas coladas.`;

    if (ids.length > 0) {
        focusElement(featureInputId(ids[ids.length - 1]));
    }
}

/** Drops blank lines and trims the rest, keeping the ids in step. */
function tidyFeatures(): void {
    const features: string[] = [];
    const ids: number[] = [];

    form.features.forEach((line, index) => {
        const trimmed = line.trim();

        if (trimmed !== '') {
            features.push(trimmed);
            ids.push(featureIds.value[index]);
        }
    });

    form.features = features;
    featureIds.value = ids;
}

/* ----------------------------------------------------------------- summary */

const summaryCounterVisible = computed(
    () => form.summary.length > SUMMARY_COUNTER_FROM,
);

/* ---------------------------------------------------------- price-change note */

const priceChangeNote = computed(() => {
    const row = editing.value;

    if (row === null || row.subscribers_count === 0) {
        return false;
    }

    return row.periods.some((period) => {
        const draft = form.periods.find(
            (candidate) => candidate.interval === period.interval,
        );
        const parsed = draft ? parseAmount(draft.amount) : null;

        return parsed !== null && parsed.minor !== period.amount_minor;
    });
});

/* ------------------------------------------------------------------ submit */

/** The checks the server would make, made first: a refusal that comes back after
 *  a password confirmation has already thrown the form away. */
function validate(): boolean {
    form.clearErrors();
    clientRowErrors.value = {};
    clientPeriodsError.value = null;

    const name = form.name.trim();

    if (name.length < 2 || name.length > 80) {
        form.setError('name', 'O nome precisa de 2 a 80 caracteres.');
    }

    if (editing.value === null) {
        if (form.code === '') {
            form.setError('code', 'Indique o código do pacote.');
        } else if (
            form.code.length > 48 ||
            !/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(form.code)
        ) {
            form.setError(
                'code',
                'Use apenas letras minúsculas, algarismos e hífenes, sem hífen no início nem no fim.',
            );
        }
    }

    if (form.summary.length > 500) {
        form.setError(
            'summary',
            'O resumo não pode ter mais de 500 caracteres.',
        );
    }

    if (
        !/^\d{1,2}$/.test(form.trial_days.trim()) ||
        Number(form.trial_days) > 90
    ) {
        form.setError(
            'trial_days',
            'Indique os dias de experiência, de 0 a 90.',
        );
    }

    if (!/^\d{1,3}$/.test(form.sort_order.trim())) {
        form.setError('sort_order', 'Indique a ordem, de 0 a 999.');
    }

    const seen = new Set<string>();

    form.features.forEach((line, index) => {
        const key = line.toLowerCase();

        if (line.length < 2 || line.length > 160) {
            form.setError(
                `features.${index}` as 'features',
                'Cada linha precisa de 2 a 160 caracteres.',
            );
        } else if (seen.has(key)) {
            form.setError(
                `features.${index}` as 'features',
                'Esta linha está repetida.',
            );
        }

        seen.add(key);
    });

    for (const row of form.periods) {
        if (!row.enabled) {
            continue;
        }

        const parsed = parseAmount(row.amount);

        if (row.amount.trim() === '') {
            clientRowErrors.value[row.interval] =
                'Indique o preço deste período.';
        } else if (parsed === null || parsed.minor <= 0) {
            clientRowErrors.value[row.interval] =
                'Use um valor como 12500 ou 12500,50, superior a zero.';
        }
    }

    if (form.is_active && !form.periods.some((row) => row.enabled)) {
        clientPeriodsError.value =
            'Um pacote activo precisa de pelo menos um período disponível.';
    } else if (periodsToSend().length === 0) {
        clientPeriodsError.value =
            'Indique o preço de pelo menos um período, mesmo que fique desligado.';
    }

    return (
        Object.keys(form.errors).length === 0 &&
        Object.keys(clientRowErrors.value).length === 0 &&
        clientPeriodsError.value === null
    );
}

async function focusFirstProblem(): Promise<void> {
    const found = await focusFirstInvalid(formElement.value);

    if (!found && periodsError.value) {
        periodsGroup.value?.focus();
        periodsGroup.value?.scrollIntoView({ block: 'center' });
    }
}

function submit(): void {
    tidyFeatures();

    if (!validate()) {
        void focusFirstProblem();

        return;
    }

    const sent = periodsToSend();

    sentIntervals.value = sent.map((period) => period.interval);

    form.transform((data) => ({
        ...(editing.value === null ? { code: data.code } : {}),
        name: data.name.trim(),
        summary: data.summary.trim() === '' ? null : data.summary.trim(),
        features: data.features,
        entitlements: data.entitlements,
        trial_days: Number(data.trial_days),
        currency_code: data.currency_code,
        sort_order: Number(data.sort_order),
        is_active: data.is_active,
        is_featured: data.is_featured,
        periods: sent,
    }));

    // A password confirmation in the way would throw the dialog away: keep the
    // form for the way back. It is never resubmitted by itself.
    storeDraft();

    // The save settled on this page when the page is still this one; a bounce to
    // the confirmation screen is a «success» of another page, and keeps the draft.
    const settledHere = (): boolean => page.component === 'Support/Packages';

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            if (settledHere()) {
                clearDraft();
                dialogOpen.value = false;
            }
        },
        onError: () => {
            clearDraft();
            void focusFirstProblem();
        },
        onFinish: () => {
            if (settledHere()) {
                clearDraft();
            }
        },
    };

    if (editing.value === null) {
        form.post(storePackage.url(), options);

        return;
    }

    form.put(updatePackage.url(editing.value.public_id), options);
}

const iconButton =
    'icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-zinc-900/[0.06] hover:text-zinc-900 disabled:pointer-events-none disabled:opacity-40 dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-white';
</script>

<template>
    <AppLayout>
        <Head title="Pacotes de subscrição" />

        <div class="px-4 py-8 sm:px-6 lg:px-8 lg:py-10">
            <div class="mx-auto max-w-4xl space-y-6">
                <FlashBanner />

                <PageHeader
                    eyebrow="Apoio · Comercial"
                    title="Pacotes de subscrição"
                    description="O que cada pacote inclui e quanto custa em cada período. O que publicar aqui é o que aparece na página de preços e na subscrição dos clientes."
                >
                    <template #actions>
                        <button
                            ref="newPackageButton"
                            type="button"
                            :class="buttonGold"
                            @click="openCreate"
                        >
                            <Plus class="size-4" aria-hidden="true" />
                            Novo pacote
                        </button>
                    </template>
                </PageHeader>

                <div
                    v-if="packages.length === 0"
                    :class="[wellSection, 'px-6 py-14 text-center']"
                >
                    <Layers
                        class="mx-auto size-8 text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    />
                    <p class="mt-4 font-semibold text-zinc-900 dark:text-white">
                        Ainda não há pacotes.
                    </p>
                    <p
                        class="mx-auto mt-1 max-w-sm text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        Crie o primeiro para os clientes terem o que escolher.
                    </p>
                    <button
                        type="button"
                        :class="[buttonGold, 'mt-6']"
                        @click="openCreate"
                    >
                        <Plus class="size-4" aria-hidden="true" />
                        Novo pacote
                    </button>
                </div>

                <ul v-else class="grid gap-2.5">
                    <li
                        v-for="row in packages"
                        :key="row.public_id"
                        :class="[wellItem, 'p-4 sm:p-5']"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-x-3 gap-y-3"
                        >
                            <div class="min-w-[min(100%,12rem)] flex-1">
                                <p
                                    class="flex flex-wrap items-center gap-x-2.5 gap-y-1.5"
                                >
                                    <span
                                        class="font-semibold [overflow-wrap:anywhere] text-zinc-950 dark:text-white"
                                        >{{ row.name }}</span
                                    >
                                    <StatusBadge
                                        :label="
                                            row.is_active
                                                ? 'Activo'
                                                : 'Inactivo'
                                        "
                                        :tone="
                                            row.is_active
                                                ? 'success'
                                                : 'neutral'
                                        "
                                    />
                                    <span
                                        v-if="row.is_featured"
                                        class="inline-flex h-[1.375rem] items-center rounded-full px-2 text-xs font-semibold whitespace-nowrap text-zinc-800 ring-1 ring-accent-500 ring-inset dark:text-accent-300 dark:ring-accent-400/60"
                                        >Em destaque</span
                                    >
                                </p>
                                <p
                                    class="mt-1 font-mono text-xs [overflow-wrap:anywhere] text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ row.code }}
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    :class="buttonOutlineSmall"
                                    @click="openEdit(row)"
                                >
                                    <Pencil
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Editar<span class="sr-only">
                                        {{ row.name }}</span
                                    >
                                </button>
                                <button
                                    v-if="row.can_delete"
                                    type="button"
                                    class="icon-button rounded-full text-zinc-500 focus-ring transition hover:bg-rose-50 hover:text-rose-700 dark:text-zinc-400 dark:hover:bg-rose-400/10 dark:hover:text-rose-300"
                                    @click="remove(row)"
                                >
                                    <span class="sr-only"
                                        >Apagar {{ row.name }}</span
                                    >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </button>
                            </div>
                        </div>

                        <p
                            v-if="row.summary"
                            class="mt-3 max-w-prose text-sm/6 [overflow-wrap:anywhere] text-zinc-700 dark:text-zinc-300"
                        >
                            {{ row.summary }}
                        </p>

                        <ul class="mt-3.5 flex flex-wrap gap-2">
                            <li
                                v-for="period in row.periods"
                                :key="period.interval"
                                :class="[
                                    'inline-flex min-h-7 items-center rounded-full bg-white px-3 py-1 numeric text-xs ring-1 ring-zinc-900/10 ring-inset dark:bg-white/5 dark:ring-white/10',
                                    period.is_active
                                        ? 'font-medium text-zinc-800 dark:text-zinc-100'
                                        : 'text-zinc-500 line-through dark:text-zinc-400',
                                ]"
                            >
                                {{ chipText(row, period) }}
                                <span v-if="!period.is_active" class="sr-only">
                                    (inactivo)</span
                                >
                            </li>
                        </ul>

                        <ul
                            v-if="row.features.length > 0"
                            class="mt-3.5 grid gap-1.5 text-sm/5 text-zinc-700 dark:text-zinc-300"
                        >
                            <li
                                v-for="feature in shownFeatures(row)"
                                :key="feature"
                                class="flex items-start gap-2"
                            >
                                <Check
                                    class="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-zinc-400"
                                    aria-hidden="true"
                                />
                                <span
                                    class="min-w-0 [overflow-wrap:anywhere]"
                                    >{{ feature }}</span
                                >
                            </li>
                            <li
                                v-if="row.features.length > 4"
                                class="ps-6 text-zinc-500 dark:text-zinc-400"
                            >
                                mais {{ row.features.length - 4 }}
                            </li>
                        </ul>

                        <p
                            class="mt-3.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400"
                        >
                            <span v-if="row.trial_days > 0">{{
                                trialText(row.trial_days)
                            }}</span>
                            <span class="numeric">{{
                                subscribersText(row.subscribers_count)
                            }}</span>
                        </p>
                    </li>
                </ul>
            </div>
        </div>

        <RecordDialog
            eyebrow="Pacotes"
            :open="dialogOpen"
            :title="editing === null ? 'Novo pacote' : `Editar ${editing.name}`"
            description="O pacote só aparece aos clientes enquanto estiver activo e tiver pelo menos um período disponível."
            @close="requestClose"
        >
            <form
                ref="formElement"
                class="space-y-6"
                novalidate
                @submit.prevent="submit"
            >
                <p
                    v-if="restoredDraft"
                    id="package-restored-note"
                    class="text-sm/6 text-zinc-600 dark:text-zinc-400"
                >
                    Confirmou a palavra-passe. Reveja e guarde outra vez.
                </p>

                <!-- Nome -->
                <div>
                    <label for="package-name" class="field-label">Nome</label>
                    <input
                        id="package-name"
                        v-model="form.name"
                        type="text"
                        required
                        maxlength="80"
                        autocomplete="off"
                        class="mt-2 block form-input"
                        :aria-invalid="form.errors.name ? 'true' : undefined"
                        :aria-describedby="
                            form.errors.name ? 'package-name-error' : undefined
                        "
                    />
                    <FormError
                        id="package-name-error"
                        :message="form.errors.name"
                    />
                </div>

                <!-- Código -->
                <div>
                    <label for="package-code" class="field-label">Código</label>
                    <input
                        id="package-code"
                        :value="form.code"
                        type="text"
                        :readonly="editing !== null"
                        :required="editing === null"
                        maxlength="48"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        class="mt-2 block form-input font-mono"
                        :aria-invalid="form.errors.code ? 'true' : undefined"
                        :aria-describedby="
                            describedBy(
                                !!form.errors.code && 'package-code-error',
                                'package-code-hint',
                            )
                        "
                        @input="onCodeInput"
                    />
                    <p id="package-code-hint" class="field-hint">
                        <template v-if="editing === null"
                            >Minúsculas, algarismos e hífenes. Sugerido a partir
                            do nome. Não muda depois de criado.</template
                        >
                        <template v-else
                            >O código não muda depois de criado.</template
                        >
                    </p>
                    <FormError
                        id="package-code-error"
                        :message="form.errors.code"
                    />
                </div>

                <!-- Resumo -->
                <div>
                    <label for="package-summary" class="field-label"
                        >Resumo
                        <span
                            class="font-normal text-zinc-500 dark:text-zinc-400"
                            >(opcional)</span
                        ></label
                    >
                    <textarea
                        id="package-summary"
                        v-model="form.summary"
                        rows="3"
                        maxlength="500"
                        class="mt-2 block form-input resize-y"
                        :aria-invalid="form.errors.summary ? 'true' : undefined"
                        :aria-describedby="
                            describedBy(
                                !!form.errors.summary &&
                                    'package-summary-error',
                                summaryCounterVisible && 'package-summary-hint',
                            )
                        "
                    />
                    <p
                        v-if="summaryCounterVisible"
                        id="package-summary-hint"
                        class="field-hint numeric"
                    >
                        {{ form.summary.length }} de 500 caracteres
                    </p>
                    <FormError
                        id="package-summary-error"
                        :message="form.errors.summary"
                    />
                </div>

                <!-- Períodos e preços -->
                <div
                    ref="periodsGroup"
                    role="group"
                    tabindex="-1"
                    aria-labelledby="package-periods-label"
                    class="@container min-w-0 rounded-2xl bg-zinc-900/[0.04] p-4 focus-ring dark:bg-white/[0.04]"
                    :aria-describedby="
                        describedBy(
                            !!periodsError && 'package-periods-error',
                            'package-periods-hint',
                        )
                    "
                >
                    <p id="package-periods-label" class="field-label">
                        Períodos e preços
                    </p>
                    <p id="package-periods-hint" class="field-hint">
                        Ligue os períodos em que o pacote se pode comprar e
                        indique o preço de cada um.
                    </p>

                    <ul class="mt-3 grid gap-4">
                        <li
                            v-for="row in form.periods"
                            :key="row.interval"
                            class="grid gap-x-4 gap-y-2 @lg:grid-cols-[10rem_12rem_minmax(0,1fr)] @lg:items-start"
                        >
                            <ToggleSwitch
                                :id="`package-period-${row.interval}-switch`"
                                v-model="row.enabled"
                            >
                                <template #label>{{
                                    intervalLabel(row.interval)
                                }}</template>
                                <template #description>{{
                                    row.enabled ? 'Disponível' : 'Desligado'
                                }}</template>
                            </ToggleSwitch>

                            <div class="min-w-0">
                                <label
                                    :for="`package-period-${row.interval}-amount`"
                                    class="sr-only"
                                    >Preço
                                    {{
                                        intervalLabel(
                                            row.interval,
                                        ).toLowerCase()
                                    }}, em {{ currencySymbol }}</label
                                >
                                <span
                                    class="field-adorned"
                                    :style="{
                                        '--field-adorn-end':
                                            currencySymbol.length > 2
                                                ? '3.25rem'
                                                : '2.5rem',
                                    }"
                                >
                                    <input
                                        :id="`package-period-${row.interval}-amount`"
                                        v-model="row.amount"
                                        type="text"
                                        inputmode="decimal"
                                        autocomplete="off"
                                        :disabled="!row.enabled"
                                        :placeholder="row.enabled ? '0' : ''"
                                        class="block form-input text-end numeric"
                                        :aria-invalid="
                                            periodError(row.interval)
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            describedBy(
                                                !!periodError(row.interval) &&
                                                    `package-period-${row.interval}-error`,
                                                !!rowNote(row) &&
                                                    `package-period-${row.interval}-note`,
                                            )
                                        "
                                        @input="onAmountInput(row.interval)"
                                    />
                                    <span class="field-adornment-end">{{
                                        currencySymbol
                                    }}</span>
                                </span>
                                <FormError
                                    :id="`package-period-${row.interval}-error`"
                                    :message="periodError(row.interval)"
                                />
                            </div>

                            <p
                                v-if="rowNote(row)"
                                :id="`package-period-${row.interval}-note`"
                                :class="[
                                    'min-w-0 text-xs/5 @lg:pt-2.5',
                                    rowNote(row)?.warning
                                        ? 'font-medium text-orange-700 dark:text-orange-300'
                                        : 'text-zinc-600 dark:text-zinc-400',
                                ]"
                            >
                                <TriangleAlert
                                    v-if="rowNote(row)?.warning"
                                    class="me-1 inline size-3.5 align-text-bottom"
                                    aria-hidden="true"
                                />
                                <span class="numeric">{{
                                    rowNote(row)?.text
                                }}</span>
                            </p>
                        </li>
                    </ul>

                    <FormError
                        id="package-periods-error"
                        :message="periodsError"
                    />
                </div>

                <!-- O que inclui -->
                <fieldset class="min-w-0">
                    <legend class="field-label">O que inclui</legend>
                    <p id="package-features-hint" class="field-hint">
                        Uma linha por vantagem. Pode colar várias linhas de uma
                        vez.
                    </p>

                    <ul v-if="form.features.length > 0" class="mt-3 grid gap-2">
                        <li
                            v-for="(line, index) in form.features"
                            :key="featureIds[index]"
                            class="@container"
                        >
                            <div
                                class="flex flex-wrap items-start gap-x-1 gap-y-1 @md:flex-nowrap"
                            >
                                <div
                                    class="min-w-0 basis-full @md:flex-1 @md:basis-0"
                                >
                                    <label
                                        :for="featureInputId(featureIds[index])"
                                        class="sr-only"
                                        >Linha {{ index + 1 }}</label
                                    >
                                    <input
                                        :id="featureInputId(featureIds[index])"
                                        v-model="form.features[index]"
                                        type="text"
                                        maxlength="160"
                                        autocomplete="off"
                                        enterkeyhint="next"
                                        class="block form-input"
                                        :aria-invalid="
                                            errorFor(`features.${index}`)
                                                ? 'true'
                                                : undefined
                                        "
                                        :aria-describedby="
                                            errorFor(`features.${index}`)
                                                ? `${featureInputId(featureIds[index])}-error`
                                                : 'package-features-hint'
                                        "
                                        @keydown.enter="
                                            onFeatureEnter($event, index)
                                        "
                                        @paste="onFeaturePaste($event, index)"
                                    />
                                </div>
                                <div
                                    class="flex shrink-0 items-center gap-0.5 @max-md:ms-auto"
                                >
                                    <button
                                        :id="`${featureInputId(featureIds[index])}-up`"
                                        type="button"
                                        :class="iconButton"
                                        :disabled="index === 0"
                                        @click="moveFeature(index, -1)"
                                    >
                                        <span class="sr-only"
                                            >Subir a linha {{ index + 1 }}</span
                                        >
                                        <ChevronUp
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <button
                                        :id="`${featureInputId(featureIds[index])}-down`"
                                        type="button"
                                        :class="iconButton"
                                        :disabled="
                                            index === form.features.length - 1
                                        "
                                        @click="moveFeature(index, 1)"
                                    >
                                        <span class="sr-only"
                                            >Descer a linha
                                            {{ index + 1 }}</span
                                        >
                                        <ChevronDown
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                    </button>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        @click="removeFeature(index)"
                                    >
                                        <span class="sr-only"
                                            >Remover linha {{ index + 1 }}</span
                                        >
                                        <X class="size-4" aria-hidden="true" />
                                    </button>
                                </div>
                            </div>
                            <FormError
                                :id="`${featureInputId(featureIds[index])}-error`"
                                :message="errorFor(`features.${index}`)"
                            />
                        </li>
                    </ul>

                    <div
                        class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2"
                    >
                        <button
                            id="package-feature-add"
                            type="button"
                            :class="buttonOutlineSmall"
                            :aria-disabled="!canAddFeature"
                            @click="addFeature()"
                        >
                            <Plus class="size-3.5" aria-hidden="true" />
                            Adicionar linha
                        </button>
                        <p
                            v-if="featureCounterVisible"
                            class="numeric text-xs text-zinc-600 dark:text-zinc-400"
                        >
                            {{ form.features.length }} de {{ MAXIMUM_FEATURES }}
                            linhas
                        </p>
                    </div>
                    <FormError :message="errorFor('features')" />
                    <p class="sr-only" role="status" aria-live="polite">
                        {{ featureNotice }}
                    </p>
                </fieldset>

                <fieldset class="flex flex-col gap-3">
                    <legend class="field-label">
                        Acesso às funcionalidades
                    </legend>
                    <p class="field-hint">
                        Estas opções controlam o acesso real. A lista descritiva
                        acima não concede permissões. Alterações aplicam-se às
                        assinaturas deste pacote.
                    </p>
                    <label
                        v-for="feature in featureOptions"
                        :key="feature.value"
                        class="flex min-h-11 items-center gap-3"
                    >
                        <input
                            v-model="form.entitlements"
                            type="checkbox"
                            :value="feature.value"
                            :disabled="feature.value === 'invoicing'"
                            class="form-checkbox"
                        />
                        <span>{{ feature.label }}</span>
                    </label>
                    <FormError :message="errorFor('entitlements')" />
                </fieldset>

                <!-- Experiência, moeda, ordem -->
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="package-trial" class="field-label"
                            >Dias de experiência</label
                        >
                        <input
                            id="package-trial"
                            v-model="form.trial_days"
                            readonly
                            type="text"
                            inputmode="numeric"
                            maxlength="2"
                            autocomplete="off"
                            class="mt-2 block form-input numeric"
                            :aria-invalid="
                                form.errors.trial_days ? 'true' : undefined
                            "
                            :aria-describedby="
                                describedBy(
                                    !!form.errors.trial_days &&
                                        'package-trial-error',
                                    'package-trial-hint',
                                )
                            "
                        />
                        <p id="package-trial-hint" class="field-hint">
                            {{ trialDays }} dias desde a criação do espaço de
                            trabalho. Uma mudança de plano não reinicia a
                            experiência.
                        </p>
                        <FormError
                            id="package-trial-error"
                            :message="form.errors.trial_days"
                        />
                    </div>

                    <div v-if="currencies.length > 1">
                        <label for="package-currency" class="field-label"
                            >Moeda</label
                        >
                        <SelectInput
                            id="package-currency"
                            v-model="form.currency_code"
                            class="mt-2"
                            :options="currencyOptions"
                            :invalid="!!form.errors.currency_code"
                            :describedby="
                                form.errors.currency_code
                                    ? 'package-currency-error'
                                    : undefined
                            "
                        />
                        <FormError
                            id="package-currency-error"
                            :message="form.errors.currency_code"
                        />
                    </div>

                    <div>
                        <label for="package-order" class="field-label"
                            >Ordem</label
                        >
                        <input
                            id="package-order"
                            v-model="form.sort_order"
                            type="text"
                            inputmode="numeric"
                            maxlength="3"
                            autocomplete="off"
                            class="mt-2 block form-input numeric"
                            :aria-invalid="
                                form.errors.sort_order ? 'true' : undefined
                            "
                            :aria-describedby="
                                describedBy(
                                    !!form.errors.sort_order &&
                                        'package-order-error',
                                    'package-order-hint',
                                )
                            "
                        />
                        <p id="package-order-hint" class="field-hint">
                            Os pacotes aparecem por esta ordem, do menor para o
                            maior.
                        </p>
                        <FormError
                            id="package-order-error"
                            :message="form.errors.sort_order"
                        />
                    </div>
                </div>

                <!-- Activo, em destaque -->
                <div
                    class="grid gap-1 rounded-2xl bg-zinc-900/[0.04] px-4 py-2 dark:bg-white/[0.04]"
                >
                    <ToggleSwitch id="package-active" v-model="form.is_active">
                        <template #label>Pacote activo</template>
                        <template #description
                            >Só os pacotes activos aparecem aos
                            clientes.</template
                        >
                    </ToggleSwitch>
                    <FormError :message="form.errors.is_active" />
                    <ToggleSwitch
                        id="package-featured"
                        v-model="form.is_featured"
                    >
                        <template #label>Em destaque</template>
                        <template #description
                            >O pacote em destaque é realçado na página de
                            preços. Só pode haver um.</template
                        >
                    </ToggleSwitch>
                    <FormError :message="form.errors.is_featured" />
                </div>

                <div role="status">
                    <p
                        v-if="priceChangeNote"
                        class="text-sm/6 text-zinc-600 dark:text-zinc-400"
                    >
                        O novo preço só se aplica a novos pagamentos. Quem já
                        pagou mantém o período que comprou.
                    </p>
                </div>

                <div
                    class="flex flex-col-reverse gap-2 pt-1 sm:flex-row sm:justify-end"
                >
                    <button
                        type="button"
                        :class="buttonOutline"
                        @click="requestClose"
                    >
                        Cancelar
                    </button>
                    <button
                        ref="submitButton"
                        type="submit"
                        :aria-describedby="
                            restoredDraft ? 'package-restored-note' : undefined
                        "
                        :disabled="form.processing"
                        :class="buttonGold"
                    >
                        <LoaderCircle
                            v-if="form.processing"
                            class="size-4 animate-spin-delayed"
                            aria-hidden="true"
                        />
                        {{
                            editing === null
                                ? 'Guardar pacote'
                                : 'Guardar alterações'
                        }}
                    </button>
                </div>
            </form>
        </RecordDialog>
    </AppLayout>
</template>
