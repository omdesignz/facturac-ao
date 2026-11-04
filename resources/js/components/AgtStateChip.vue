<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { show as agtConnection } from '@/routes/agt/connection';

/**
 * Whether the AGT link is live, always in view. Green when verified, gold when
 * it still needs checking; either way it opens the connection page, where the
 * state is explained. On a phone it is the one chip the header keeps.
 */
const agt = computed(() => usePage().props.currentWorkspace?.legal_entity?.agt);
</script>

<template>
    <Link
        v-if="agt"
        :href="agtConnection.url()"
        class="flex h-9 shrink-0 items-center justify-center gap-2 rounded-full bg-zinc-900/[0.04] px-3 text-xs font-semibold text-zinc-800 focus-ring transition hover:bg-zinc-900/[0.07] max-[30rem]:w-9 max-[30rem]:px-0 dark:bg-white/[0.08] dark:text-zinc-100 dark:hover:bg-white/15 pointer-coarse:max-[30rem]:size-11"
    >
        <span
            :class="[
                'size-2 rounded-full',
                agt.verified ? 'bg-emerald-500' : 'bg-accent-400',
            ]"
            aria-hidden="true"
        />
        <!-- On a narrow phone the dot alone says it, so the header keeps its room. -->
        <span class="max-[30rem]:sr-only">{{
            agt.verified ? 'AGT ligada' : 'AGT por verificar'
        }}</span>
    </Link>
</template>
