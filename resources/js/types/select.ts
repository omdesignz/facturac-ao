export interface SelectOption<V extends string | number = string> {
    value: V;
    label: string;
    /** Optional second line, shown muted under the label. */
    hint?: string;
    disabled?: boolean;
}
