<script setup lang="ts">
import { computed, ref } from 'vue';

/**
 * The one file field: a real `<input type="file">` (so the keyboard, screen
 * readers and the form post all work as the browser built them) laid invisibly
 * over an outline pill «Escolher ficheiro», with the chosen name beside it.
 * Name it with a `<label for>` on the page, or with `ariaLabel`.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        accept?: string;
        multiple?: boolean;
        disabled?: boolean;
        required?: boolean;
        /** Marks the field as failing validation. */
        invalid?: boolean;
        /** The id of the error or hint element that describes this field. */
        describedby?: string;
        ariaLabel?: string;
        label?: string;
        emptyLabel?: string;
    }>(),
    {
        id: undefined,
        name: undefined,
        accept: undefined,
        multiple: false,
        disabled: false,
        required: false,
        invalid: false,
        describedby: undefined,
        ariaLabel: undefined,
        label: 'Escolher ficheiro',
        emptyLabel: 'Nenhum ficheiro escolhido',
    },
);

const emit = defineEmits<{
    change: [event: Event];
}>();

const input = ref<HTMLInputElement | null>(null);
const names = ref<string[]>([]);

const summary = computed(() => {
    if (names.value.length === 0) {
        return props.emptyLabel;
    }

    return names.value.length === 1
        ? names.value[0]
        : `${names.value.length} ficheiros`;
});

function onChange(event: Event): void {
    const files = (event.target as HTMLInputElement).files;

    names.value = files ? Array.from(files, (file) => file.name) : [];
    emit('change', event);
}

/** Forget the chosen file, for a page that uploads at once and starts over. */
function reset(): void {
    if (input.value) {
        input.value.value = '';
    }

    names.value = [];
}

defineExpose({ input, reset });
</script>

<template>
    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-2">
        <span class="relative inline-flex">
            <input
                :id="id"
                ref="input"
                type="file"
                :name="name"
                :accept="accept"
                :multiple="multiple"
                :disabled="disabled"
                :required="required || undefined"
                :aria-label="ariaLabel"
                :aria-invalid="invalid ? 'true' : undefined"
                :aria-describedby="describedby"
                class="peer absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-not-allowed"
                @change="onChange"
            />
            <span
                class="inline-flex h-10 items-center justify-center rounded-full bg-white px-[1.125rem] text-sm font-semibold whitespace-nowrap text-zinc-800 ring-1 ring-zinc-900/10 transition ring-inset peer-hover:bg-zinc-50 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-brand-600 peer-disabled:opacity-50 peer-aria-invalid:ring-2 peer-aria-invalid:ring-rose-500 dark:bg-transparent dark:text-zinc-200 dark:ring-white/15 dark:peer-hover:bg-white/5 dark:peer-focus-visible:outline-brand-300 dark:peer-aria-invalid:ring-rose-400 forced-colors:border forced-colors:border-[ButtonText] pointer-coarse:h-11"
                >{{ label }}</span
            >
        </span>
        <span
            class="min-w-0 truncate text-sm text-zinc-600 dark:text-zinc-400"
            :title="names.length === 1 ? summary : undefined"
            >{{ summary }}</span
        >
    </div>
</template>
