<?php

namespace App\Fiscal\Documents;

use App\Models\Quote;
use App\Models\QuoteLine;

/**
 * A quote (orçamento) as a PDF, to send the customer before anything is billed.
 *
 * Not a fiscal document, and it says so on the sheet: no number from a fiscal
 * series, no signature, no QR. Drawn from the quote's own stored lines and
 * totals, so it matches what the app and the web show. Rendered on demand and
 * never stored.
 */
class QuotePdf
{
    /** Layouts are versioned like the fiscal ones: change one by adding the next. */
    public const string LAYOUT = 'v1';

    public function __construct(private PdfSheet $sheet) {}

    public function render(Quote $quote): string
    {
        $quote->loadMissing(['lines', 'legalEntity', 'customer', 'establishment']);
        $legalEntity = $quote->legalEntity;

        /** @var view-string $view */
        $view = 'business-documents.pdf.'.self::LAYOUT.'.quote';

        return $this->sheet->render($view, [
            'company' => BusinessDocumentIssuer::of($legalEntity, $quote->establishment),
            'logo' => $this->sheet->logo($legalEntity),
            'quote' => [
                'reference' => $quote->reference,
                'status_label' => $quote->effectiveStatus()->label(),
                'issue_date' => $quote->issue_date,
                'valid_until' => $quote->valid_until,
                'currency' => $quote->currency_code === 'AOA' ? 'Kz' : $quote->currency_code,
                'net_total_minor' => $quote->net_total_minor,
                'tax_total_minor' => $quote->tax_total_minor,
                'gross_total_minor' => $quote->gross_total_minor,
            ],
            'customer' => [
                'name' => $quote->customer_name,
                'tax_identification_number' => $quote->customer_tax_identification_number ?? $quote->customer?->tax_identification_number,
                'address_line' => $quote->customer?->address_line,
            ],
            'lines' => $quote->lines
                ->sortBy('line_number')
                ->map(fn (QuoteLine $line): array => [
                    'code' => $line->product_code,
                    'description' => $line->product_description,
                    'unit' => $line->unit_of_measure,
                    'quantity' => $this->decimal($line->quantity_units, $line->quantity_scale),
                    'unit_price_minor' => $line->unit_price_minor,
                    'discount' => $line->discount_rate_basis_points > 0 ? $this->decimal($line->discount_rate_basis_points, 2) : null,
                    'tax' => $line->tax_percentage,
                    'exemption' => $line->tax_exemption_code,
                    'gross_minor' => $line->gross_amount_minor,
                ])
                ->values()
                ->all(),
        ], [
            'title' => 'Orçamento '.$quote->reference,
            'author' => $legalEntity->legal_name,
            'subject' => 'Orçamento',
            'engine' => BusinessDocumentIssuer::ENGINE,
        ]);
    }

    public function filename(Quote $quote): string
    {
        return 'Orcamento-'.BusinessDocumentIssuer::slug($quote->reference).'.pdf';
    }

    private function decimal(int $units, int $scale): string
    {
        if ($scale <= 0) {
            return (string) $units;
        }

        $sign = $units < 0 ? '-' : '';
        $digits = str_pad((string) abs($units), $scale + 1, '0', STR_PAD_LEFT);

        return $sign.substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
