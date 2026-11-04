<script setup lang="ts">
/**
 * A real app screen inside a phone. The frame draws what a phone draws around
 * an app (status bar, camera and gesture bar), so the screenshot under it is
 * captured at the height that leaves room for them and nothing is cut off.
 *
 * Everything is sized in device points through `--u`, so a phone stays in
 * proportion at any width its parent gives it.
 */
defineProps<{
    kind: 'iphone' | 'android';
    src: string;
    alt: string;
    width: number;
    height: number;
    /** The clock in the status bar, to agree with the screen inside. */
    time: string;
    loading?: 'lazy' | 'eager';
    fetchpriority?: 'high' | 'auto';
}>();
</script>

<template>
    <div class="device" :class="`device-${kind}`">
        <div class="device-rim">
            <template v-if="kind === 'iphone'">
                <span class="device-button device-action" aria-hidden="true" />
                <span
                    class="device-button device-volume-up"
                    aria-hidden="true"
                />
                <span
                    class="device-button device-volume-down"
                    aria-hidden="true"
                />
                <span class="device-button device-power" aria-hidden="true" />
            </template>
            <template v-else>
                <span class="device-button device-power" aria-hidden="true" />
                <span class="device-button device-volume" aria-hidden="true" />
            </template>

            <div class="device-bezel">
                <div class="device-screen">
                    <div class="device-status" aria-hidden="true">
                        <span class="device-time">{{ time }}</span>
                        <span class="device-camera" />
                        <span class="device-indicators">
                            <!-- Mobile signal -->
                            <svg viewBox="0 0 18 12" fill="currentColor">
                                <rect
                                    x="0"
                                    y="8"
                                    width="3"
                                    height="4"
                                    rx="0.8"
                                />
                                <rect
                                    x="5"
                                    y="5.5"
                                    width="3"
                                    height="6.5"
                                    rx="0.8"
                                />
                                <rect
                                    x="10"
                                    y="3"
                                    width="3"
                                    height="9"
                                    rx="0.8"
                                />
                                <rect
                                    x="15"
                                    y="0"
                                    width="3"
                                    height="12"
                                    rx="0.8"
                                />
                            </svg>
                            <!-- Wi-Fi -->
                            <svg viewBox="0 0 17 12" fill="currentColor">
                                <path
                                    d="M8.5 2.3c2.4 0 4.6.9 6.2 2.5.1.1.3.1.4 0l1.2-1.2c.2-.2.2-.4 0-.5C14.2 1 11.5 0 8.5 0S2.8 1 .7 3.1c-.2.1-.2.3 0 .5l1.2 1.2c.1.1.3.1.4 0 1.6-1.6 3.8-2.5 6.2-2.5Z"
                                />
                                <path
                                    d="M8.5 6.2c1.3 0 2.5.5 3.5 1.4.1.1.3.1.4 0l1.2-1.2c.2-.2.2-.4 0-.5-1.4-1.3-3.2-2.1-5.1-2.1s-3.7.8-5.1 2.1c-.2.1-.2.3 0 .5l1.2 1.2c.1.1.3.1.4 0 1-.9 2.2-1.4 3.5-1.4Z"
                                />
                                <path
                                    d="M10.9 9c.1-.1.1-.3 0-.5C10.2 7.900 9.400 7.600 8.500 7.600s-1.700.3-2.400.9c-.1.2-.1.4 0 .5l2.100 2.100c.2.2.4.2.6 0L10.900 9Z"
                                />
                            </svg>
                            <!-- Battery -->
                            <svg
                                v-if="kind === 'iphone'"
                                viewBox="0 0 27 12"
                                fill="none"
                            >
                                <rect
                                    x="0.5"
                                    y="0.5"
                                    width="22"
                                    height="11"
                                    rx="3.2"
                                    stroke="currentColor"
                                    opacity="0.4"
                                />
                                <rect
                                    x="2"
                                    y="2"
                                    width="17"
                                    height="8"
                                    rx="1.9"
                                    fill="currentColor"
                                />
                                <path
                                    d="M24 4v4c.8-.3 1.400-1.100 1.400-2S24.800 4.300 24 4Z"
                                    fill="currentColor"
                                    opacity="0.45"
                                />
                            </svg>
                            <svg v-else viewBox="0 0 8 13" fill="currentColor">
                                <path
                                    d="M2.800 0h2.400v1.300h1.600c.6 0 1.200.5 1.200 1.200v9.300c0 .6-.5 1.200-1.200 1.200H1.200C.5 13 0 12.500 0 11.800V2.500c0-.7.5-1.200 1.200-1.200h1.600V0Z"
                                />
                            </svg>
                        </span>
                    </div>
                    <img
                        :src="src"
                        :alt="alt"
                        :width="width"
                        :height="height"
                        :loading="loading ?? 'lazy'"
                        :fetchpriority="fetchpriority"
                        decoding="async"
                        class="block h-auto w-full"
                    />
                    <div class="device-gesture" aria-hidden="true">
                        <span />
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
