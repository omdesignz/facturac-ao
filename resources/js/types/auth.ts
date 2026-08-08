export type User = {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    two_factor_enabled: boolean;
    is_support_staff: boolean;
};

export type WorkspaceNavigation = {
    public_id: string;
    name: string;
    role: string;
    role_label: string;
    current: boolean;
};

export type LegalEntitySummary = {
    public_id: string;
    legal_name: string;
    trade_name: string | null;
    masked_nif: string | null;
    status: string;
    status_label: string;
    establishment_name: string | null;
};

export type CurrentWorkspace = {
    public_id: string;
    name: string;
    role: string;
    role_label: string;
    requires_mfa: boolean;
    legal_entity: LegalEntitySummary | null;
};

export type Auth = {
    user: User | null;
    workspaces: WorkspaceNavigation[];
};

export type FlashMessages = {
    status: string | null;
    success: string | null;
    error: string | null;
};
