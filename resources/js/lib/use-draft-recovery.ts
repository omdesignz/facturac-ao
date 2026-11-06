import {
    computed,
    nextTick,
    onMounted,
    onBeforeUnmount,
    ref,
    watch,
} from 'vue';
import {
    matchesRecoveryShape,
    readRecovery,
    recoveryKey,
    writeRecovery,
} from './draft-recovery';
import type { RecoveryCopy } from './draft-recovery';

/** Recovery never posts a form; the person reviews and saves through the normal flow. */
export function useDraftRecovery<T extends Record<string, unknown>>(options: {
    scope: () => (string | number)[];
    document: () => string;
    version: () => string;
    data: () => T;
    restore: (data: T) => void;
    enabled?: () => boolean;
}) {
    const pending = ref<RecoveryCopy<T> | null>(null);
    const message = ref('');
    const scope = JSON.stringify(options.scope());
    let key = recoveryKey([...options.scope(), options.document()]);
    let version = options.version();
    let baseline = JSON.stringify(options.data());
    let storage: Storage | null = null;
    const restoring = ref(false);
    let submitted = '';
    const active = () =>
        JSON.stringify(options.scope()) === scope &&
        (options.enabled?.() ?? true);
    const hasConflict = computed(
        () =>
            pending.value !== null &&
            pending.value.version !== options.version(),
    );

    function persist(): void {
        if (!storage || !active() || restoring.value || pending.value) {
            return;
        }

        try {
            if (JSON.stringify(options.data()) === baseline) {
                storage.removeItem(key);
                message.value = '';

                return;
            }

            writeRecovery(storage, key, version, options.data());
            message.value =
                'Cópia guardada neste separador. Use Guardar para a guardar na empresa.';
        } catch {
            message.value =
                'Não foi possível guardar a cópia neste separador. Guarde o rascunho antes de sair.';
        }
    }

    onMounted(() => {
        if (!active()) {
            return;
        }

        try {
            storage = window.sessionStorage;
            pending.value = readRecovery<T>(storage, key);

            if (
                pending.value &&
                !matchesRecoveryShape(pending.value.data, options.data())
            ) {
                discard();
                message.value =
                    'A cópia guardada não é compatível com este formulário. Os dados da empresa não foram alterados.';
            }

            if (
                pending.value &&
                JSON.stringify(pending.value.data) === baseline
            ) {
                discard();
            }
        } catch {
            message.value =
                'A recuperação neste separador não está disponível. Guarde o rascunho antes de sair.';
        }
    });

    watch(options.data, persist, { deep: true, flush: 'sync' });
    onBeforeUnmount(persist);

    function discard(): void {
        try {
            storage?.removeItem(key);
        } catch {
            /* The copy can still expire. */
        }

        pending.value = null;
        message.value = '';
    }

    async function restore(): Promise<void> {
        if (!active() || !pending.value || hasConflict.value) {
            return;
        }

        restoring.value = true;

        try {
            options.restore(
                JSON.parse(JSON.stringify(pending.value.data)) as T,
            );
            pending.value = null;
            message.value =
                'Trabalho recuperado. Reveja os dados antes de guardar.';
        } finally {
            await nextTick();
            restoring.value = false;
        }
    }

    function beforeSave(): void {
        submitted = JSON.stringify(options.data());
        persist();
    }

    function saved(): void {
        discard();
        baseline = submitted;
        key = recoveryKey([...options.scope(), options.document()]);
        version = options.version();
        persist();
    }

    function finished(): void {
        persist();
    }

    return {
        restoring,
        pending,
        message,
        hasConflict,
        restore,
        discard,
        beforeSave,
        saved,
        finished,
    };
}
