/**
 * The shapes the point of sale reads from the server, in one place so the
 * till, the shift report and the receipt cannot drift from each other.
 * They mirror `App\Fiscal\Pos\PosPresenter` and the controllers beside it.
 */

export interface PosPaymentMethod {
    value: string;
    label: string;
}

export interface PosMethodTotal {
    method: string;
    label: string;
    count: number;
    total_minor: number;
}

/** What the till shows while selling; the full report adds the fields below. */
export interface PosTillSummary {
    sales_count: number;
    gross_total_minor: number;
    by_method: PosMethodTotal[];
    cash_sales_minor: number;
    cash_in_minor: number;
    cash_out_minor: number;
    expected_cash_minor: number;
}

export interface PosShiftSummary extends PosTillSummary {
    net_total_minor: number;
    tax_total_minor: number;
    first_document_no: string | null;
    last_document_no: string | null;
}

export interface PosCashMovement {
    public_id: string;
    type: 'in' | 'out';
    type_label: string;
    amount_minor: number;
    reason: string;
    created_at: string | null;
}

export interface PosSaleRow {
    public_id: string;
    document_public_id: string;
    document_no: string | null;
    customer_name: string;
    total_minor: number;
    payment_method: string;
    payment_method_label: string;
    issued_at: string | null;
    receipt_url: string;
}

export interface PosOpenSessionOf {
    public_id: string;
    opened_by_name: string;
    opened_at: string;
    is_mine: boolean;
}

export interface PosRegisterChoice {
    public_id: string;
    name: string;
    is_active: boolean;
    establishment: { public_id: string; name: string };
    has_series: boolean;
    open_session: PosOpenSessionOf | null;
}

export interface PosSessionProps {
    public_id: string;
    opened_at: string;
    opening_float_minor: number;
    currency_code: string;
    register: { public_id: string; name: string };
    establishment: { public_id: string; name: string };
    series_available: boolean;
    summary: PosTillSummary;
    cash_movements: PosCashMovement[];
    recent_sales: PosSaleRow[];
}

/** One article as the till is sent it. */
export interface PosCatalogueItem {
    public_id: string;
    code: string;
    barcode: string | null;
    name: string;
    type: string;
    unit_of_measure: string;
    unit_price_minor: number;
    tax_type: string;
    tax_code: string | null;
    tax_percentage: string;
    tax_exemption_code: string | null;
    tracks_stock: boolean;
    quantity_on_hand: string | null;
}

export interface PosCustomer {
    public_id: string;
    name: string;
    tax_identification_number: string;
}

export interface PosFinalConsumer {
    name: string;
    tax_identification_number: string;
    country_code: string;
}

/** Who the sale is for. «final» is the anonymous consumer. */
export type PosCustomerChoice =
    | { kind: 'final' }
    | { kind: 'saved'; public_id: string; name: string; nif: string }
    | { kind: 'typed'; name: string; nif: string };

export interface PosStockUpdate {
    catalogue_item_public_id: string;
    quantity_on_hand: string;
}

export interface PosSaleResponse {
    sale: PosSaleRow & {
        tendered_minor: number;
        change_minor: number;
        replayed: boolean;
    };
    summary: PosTillSummary;
    stock: PosStockUpdate[];
}

export interface PosPaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}
