export type LegalLink = {
    label: string;
    slug: string;
};

export type LegalState = {
    cookie_consent_required: boolean;
    cookie_policy_version: number;
    terms_reacceptance_required: boolean;
    links: LegalLink[];
};
