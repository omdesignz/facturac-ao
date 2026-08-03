import { createInertiaApp } from '@inertiajs/vue3';
import {
    applyAppearance,
    getStoredAppearance,
    watchSystemAppearance,
} from '@/lib/appearance';

const appName = import.meta.env.VITE_APP_NAME || 'facturac.ao';

applyAppearance(getStoredAppearance());
watchSystemAppearance();

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    progress: {
        color: '#F9B233',
    },
});
