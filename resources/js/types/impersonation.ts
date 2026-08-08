export type Impersonation = {
    subject_name: string;
    subject_email: string;
    impersonator_name: string;
    reason: string;
    remaining_seconds: number;
    writes: number;
};
