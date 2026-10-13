import type { Auth, CurrentWorkspace, FlashMessages } from '@/types/auth';
import type { Impersonation } from '@/types/impersonation';
import type { LegalState } from '@/types/legal';
import type { NotificationState } from '@/types/notification';
import type { WorkSessionSettings } from '@/types/work-session';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            currentWorkspace: CurrentWorkspace | null;
            subscriptionAccess: {
                status: 'active' | 'trial' | 'expired';
                plan_name: string | null;
                trial_ends_at: string | null;
                features: Record<string, boolean>;
            } | null;
            assistant: { url: string } | null;
            flash: FlashMessages;
            workSession: WorkSessionSettings | null;
            impersonation: Impersonation | null;
            legal: LegalState;
            notifications: NotificationState | null;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
