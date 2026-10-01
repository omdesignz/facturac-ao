<?php

namespace App\Fiscal\Documents;

use App\Fiscal\Calculation\FiscalCalculator;
use App\FiscalTaxType;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\PlatformSetting;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\URL;

/**
 * A fiscal document in the shape a person reads it.
 *
 * The figures are taken from the row rather than recomputed. An issued
 * document is frozen and was signed as it stands; a printed copy that differs
 * from the signed one by a cêntimo of rounding is worse than no copy at all.
 */
class FiscalDocumentPresenter
{
    public function __construct(private FiscalCalculator $calculator) {}

    /**
     * How long a customer's link stays good.
     *
     * Long enough to be useful after the email sits unread for a while, short
     * enough that a forwarded link does not stay open on the customer's ledger
     * indefinitely.
     */
    public const LINK_DAYS = 90;

    /**
     * @return array<string, mixed>
     */
    public function forPrint(FiscalDocument $document): array
    {
        $document->loadMissing([
            'lines.taxes',
            'legalEntity',
            'establishment',
            'settlements',
            'withholdings',
        ]);

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'agt_accepted' => $document->agt_document_status === 'V',
            'document_date' => $document->document_date->toIso8601String(),
            'due_date' => $document->due_date?->toIso8601String(),
            'issued_at' => $document->issued_at?->toIso8601String(),
            'currency_code' => $document->currency_code,
            /*
             * Kwanzas per one unit of the document's currency, on the AGT's
             * currency block. Shown to four places because that is what the
             * printed sheet has room for; the figure the arithmetic uses keeps
             * six and is not rounded on the way through.
             */
            'exchange_rate' => number_format($document->exchange_rate_micro / 1_000_000, 4, ',', ' '),
            'is_foreign_currency' => $document->isForeignCurrency(),
            'base_currency_code' => (string) config('fiscal.saft.currency_code', 'AOA'),
            'notes' => $document->notes,
            'payment_method_label' => $document->payment_method?->label(),

            'company' => [
                'legal_name' => $document->legalEntity->legal_name,
                'trade_name' => $document->legalEntity->trade_name,
                'tax_identification_number' => $document->legalEntity->tax_identification_number,
                'establishment' => $document->establishment->name,
                'address_line' => $document->establishment->address_line,
                'municipality' => $document->establishment->municipality,
                'province_code' => $document->establishment->province_code,
            ],

            'customer' => [
                'name' => $document->customer_name,
                'tax_identification_number' => $document->customer_tax_identification_number,
                'address_line' => $document->customer_address,
                'country_code' => $document->customer_country_code,
            ],

            'lines' => array_values($document->lines
                ->map(fn (FiscalDocumentLine $line): array => [
                    'line_number' => $line->line_number,
                    'operation_date' => $line->operation_date?->toDateString(),
                    'product_code' => $line->product_code,
                    'product_description' => $line->product_description,
                    'unit_of_measure' => $line->unit_of_measure,
                    'quantity' => $this->decimal($line->quantity_units, $line->quantity_scale),
                    'unit_price_minor' => $line->unit_price_base_minor,
                    'discount_rate' => $this->decimal($line->discount_rate_basis_points, 2),
                    'net_amount_minor' => $line->net_amount_minor,
                    'tax_amount_minor' => $line->tax_amount_minor,
                    'gross_amount_minor' => $line->gross_amount_minor,
                    'tax_rate' => $this->decimal(
                        $line->taxes->first()->tax_rate_basis_points ?? 0,
                        2,
                    ),
                    'tax_exemption_code' => $line->taxes->first()->tax_exemption_code ?? null,
                    /*
                     * The AGT sheet gives excise, VAT and stamp duty a column
                     * each rather than one "tax" figure, because a line can
                     * carry more than one and the reader has to see which.
                     */
                    'taxes_by_type' => $this->lineTaxesByType($line),
                    'operation_type' => $line->operation_type->value,
                ])->all()),

            'tax_summary' => $this->taxSummary($document),
            'taxes_by_type' => $this->documentTaxesByType($document),
            /*
             * Withholding retained at source by the buyer. The AGT sheet keeps
             * it apart from the totals on purpose — it is informational and is
             * not part of what the customer pays on this document.
             */
            'withholdings' => $this->withholdings($document),
            'withholding_total_minor' => (int) $document->withholdings->sum('amount_minor'),
            'discount_total_minor' => $this->discountTotal($document),

            'totals' => [
                'net_minor' => $document->net_total_minor,
                'tax_minor' => $document->tax_payable_minor,
                'gross_minor' => $document->gross_total_minor,
                'settled_minor' => $document->settlement_total_minor,
                // The same total in kwanzas, for the currency block. Equal to
                // the gross on the documents that never left the kwanza.
                'gross_base_minor' => $this->calculator->convertedAmount(
                    $document->gross_total_minor,
                    $document->exchange_rate_micro,
                ),
            ],

            /*
             * What makes the printed copy verifiable. The AGT expects a
             * validated program to say so on the document, and the digest is
             * what ties this paper to the record that was filed.
             */
            'authenticity' => [
                'software_validation_number' => $document->software_validation_number,
                'software_product_id' => $document->software_product_id,
                'digest' => $this->shortDigest($document),
                'full_digest' => $document->document_payload_sha256,
                'verification_url' => $this->agtVerificationUrl($document),
                'qr_svg' => $this->qrCode($document),
                // mPDF cannot use the raw markup the web page inlines, but it
                // parses SVG behind a data URI.
                'qr_data_uri' => $this->qrDataUri($document),
            ],

            'support_email' => PlatformSetting::get('support_email'),
        ];
    }

    /**
     * What the buyer keeps back, as the AGT sheet lists it.
     *
     * The base travels with each row. A reader checking the arithmetic needs to
     * see which figure the rate was charged on, because captive VAT and a
     * retention on the same document are charged on different ones.
     *
     * @return list<array<string, mixed>>
     */
    private function withholdings(FiscalDocument $document): array
    {
        $rows = [];

        foreach ($document->withholdings as $withholding) {
            $rows[] = [
                'type' => $withholding->withholding_type->label(),
                'tax' => $withholding->withholding_type->taxLabel(),
                'rate' => $this->decimal($withholding->rate_basis_points, 2),
                'base_minor' => $withholding->base_minor,
                'amount_minor' => $withholding->amount_minor,
            ];
        }

        return $rows;
    }

    /**
     * The documents a receipt pays off, which is what its table lists.
     *
     * An RC has no goods on it — the columns are the invoices it settles, with
     * the tax that was on each.
     *
     * @return list<array<string, mixed>>
     */
    public function settlements(FiscalDocument $document): array
    {
        $rows = [];

        foreach ($document->settlements()->with('settledDocument')->get() as $settlement) {
            $settled = $settlement->settledDocument;

            // The foreign key restricts on delete, so a settlement always has
            // the document it paid off.
            $rows[] = [
                'document_no' => $settlement->settled_document_no,
                'document_type_label' => $settled->document_type->label(),
                'net_minor' => $settled->net_total_minor,
                'taxes_by_type' => $this->documentTaxesByType($settled),
                'discount_minor' => $this->discountTotal($settled),
                'total_minor' => $settlement->amount_minor,
            ];
        }

        return $rows;
    }

    /**
     * The three columns the AGT sheet prints, whether or not a line uses them.
     *
     * @return array<string, int>
     */
    private function emptyTaxColumns(): array
    {
        return [
            FiscalTaxType::ExciseDuty->value => 0,
            FiscalTaxType::Vat->value => 0,
            FiscalTaxType::StampDuty->value => 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function lineTaxesByType(FiscalDocumentLine $line): array
    {
        $columns = $this->emptyTaxColumns();

        foreach ($line->taxes as $tax) {
            $key = $tax->tax_type->value;

            if (array_key_exists($key, $columns)) {
                $columns[$key] += $tax->tax_contribution_minor;
            }
        }

        return $columns;
    }

    /**
     * @return array<string, int>
     */
    private function documentTaxesByType(FiscalDocument $document): array
    {
        $columns = $this->emptyTaxColumns();

        foreach ($document->lines as $line) {
            foreach ($this->lineTaxesByType($line) as $type => $amount) {
                $columns[$type] += $amount;
            }
        }

        return $columns;
    }

    /** The link a customer can open without an account. */
    public function signedUrl(FiscalDocument $document): string
    {
        return URL::temporarySignedRoute(
            'documents.print',
            now()->addDays(self::LINK_DAYS),
            ['fiscalDocument' => $document->public_id],
        );
    }

    /**
     * The four characters printed on the document.
     *
     * A reader compares these against the record rather than a 64-character
     * hash they would never actually check.
     */
    private function shortDigest(FiscalDocument $document): ?string
    {
        $digest = $document->document_payload_sha256;

        return $digest === null ? null : strtoupper(substr($digest, 0, 8));
    }

    /**
     * The QR a phone can read to verify the document with AGT.
     *
     * Rendered as SVG so it stays sharp at any print size, and inline so the
     * page needs nothing from the network to be printed.
     */
    private function qrCode(FiscalDocument $document): ?string
    {
        if ($document->document_no === null) {
            return null;
        }

        $writer = new Writer(new ImageRenderer(
            new RendererStyle(350, 0),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(
            $this->agtVerificationUrl($document),
            'UTF-8',
            ErrorCorrectionLevel::M(),
        );
    }

    private function agtVerificationUrl(FiscalDocument $document): ?string
    {
        if ($document->document_no === null) {
            return null;
        }

        $baseUrl = rtrim((string) config('agt.qr.verification_url'), '?&');
        $query = http_build_query([
            'emissor' => $document->legalEntity->tax_identification_number,
            'document' => $document->document_no,
        ], '', '&', PHP_QUERY_RFC3986);

        return $baseUrl.'?'.$query;
    }

    /** The same QR, wrapped so a PDF <img> can carry it. */
    private function qrDataUri(FiscalDocument $document): ?string
    {
        $svg = $this->qrCode($document);

        return $svg === null
            ? null
            : 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Tax gathered by rate, which is what the document has to show.
     *
     * @return list<array{rate: string, base_minor: int, tax_minor: int, exemption_code: string|null}>
     */
    private function taxSummary(FiscalDocument $document): array
    {
        $rows = [];

        foreach ($document->lines as $line) {
            foreach ($line->taxes as $tax) {
                $key = $tax->tax_rate_basis_points.':'.($tax->tax_exemption_code ?? '');

                $rows[$key] ??= [
                    'rate' => $this->decimal($tax->tax_rate_basis_points, 2),
                    'base_minor' => 0,
                    'tax_minor' => 0,
                    'exemption_code' => $tax->tax_exemption_code,
                ];

                $rows[$key]['base_minor'] += $line->net_amount_minor;
                $rows[$key]['tax_minor'] += $tax->tax_contribution_minor;
            }
        }

        return array_values($rows);
    }

    /**
     * What the discounts took off, which the AGT summary shows on its own line.
     */
    private function discountTotal(FiscalDocument $document): int
    {
        $total = 0;

        foreach ($document->lines as $line) {
            $gross = intdiv($line->quantity_units * $line->unit_price_base_minor, 1000);
            $total += max(0, $gross - $line->net_amount_minor);
        }

        return $total;
    }

    /** Scaled integers back to the decimal string a person reads. */
    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }
}
