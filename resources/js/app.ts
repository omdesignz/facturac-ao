import { createInertiaApp } from '@inertiajs/vue3';
import {
    applyAppearance,
    getStoredAppearance,
    watchSystemAppearance,
} from '@/lib/appearance';

const appName = import.meta.env.VITE_APP_NAME || 'VAP Fatura';

applyAppearance(getStoredAppearance());
watchSystemAppearance();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#4B5563',
    },
});
