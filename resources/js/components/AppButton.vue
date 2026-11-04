<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { buttonClass } from '@/lib/button';
import type { ButtonSize, ButtonVariant } from '@/lib/button';

const props = withDefaults(
    defineProps<{
        variant?: ButtonVariant;
        size?: ButtonSize;
        /** Makes it a link. Inertia's, unless `external`. */
        href?: string;
        /** A plain link: a download, a PDF, another site. */
        external?: boolean;
        type?: 'button' | 'submit' | 'reset';
    }>(),
    { variant: 'outline', size: 'md', href: undefined, type: 'button' },
);

const element = computed(() => {
    if (props.href === undefined) {
        return 'button';
    }

    return props.external ? 'a' : Link;
});
</script>

<template>
    <component
        :is="element"
        :href="href"
        :type="href === undefined ? type : undefined"
        :class="buttonClass(variant, size)"
    >
        <slot />
    </component>
</template>
