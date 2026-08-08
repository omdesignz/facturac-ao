export interface WorkSessionSettings {
    enabled: boolean;
    /** Full length of a work block. */
    total_seconds: number;
    /** What is left of it, measured by the server on this response. */
    remaining_seconds: number;
    /** How long before the end the user is warned. */
    warning_seconds: number;
}
