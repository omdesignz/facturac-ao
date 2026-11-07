<?php

namespace App\Fiscal\Documents;

use App\Analytics\AgingQuery;
use App\Analytics\ReceivablesQuery;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * A customer's conta corrente as a PDF, to send them with what they owe.
 *
 * Not a fiscal document: nothing is signed or numbered, so it is drawn from the
 * company and the customer as they are today. The figures are the customer
 * page's own (`AgingQuery::statement`, `ReceivablesQuery::summarise`), in the
 * company's currency, so the PDF and the page can never disagree. Rendered on
 * demand and never stored.
 */
class CustomerStatementPdf
{
    /** Layouts are versioned like the fiscal ones: change one by adding the next. */
    public const string LAYOUT = 'v1';

    public function __construct(
        private ReceivablesQuery $receivables,
        private AgingQuery $aging,
        private PdfSheet $sheet,
    ) {}

    public function render(Customer $customer): string
    {
        $legalEntity = $customer->legalEntity;
        $issued = fn (): Builder => ReceivablesQuery::issued(
            $customer->fiscalDocuments()->where('currency_code', $legalEntity->currency_code)->getQuery(),
        );

        /** @var view-string $view */
        $view = 'business-documents.pdf.'.self::LAYOUT.'.statement';

        return $this->sheet->render($view, [
            'company' => BusinessDocumentIssuer::of($legalEntity),
            'logo' => $this->sheet->logo($legalEntity),
            'customer' => [
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'address_line' => $customer->address_line,
                'email' => $customer->email,
            ],
            'currency' => $legalEntity->currency_code === 'AOA' ? 'Kz' : $legalEntity->currency_code,
            'summary' => $this->receivables->summarise($issued()),
            'entries' => $this->aging->statement($issued()),
            'generatedOn' => CarbonImmutable::now('Africa/Luanda'),
        ], [
            'title' => 'Extracto de conta — '.$customer->name,
            'author' => $legalEntity->legal_name,
            'subject' => 'Extracto de conta corrente',
            'engine' => BusinessDocumentIssuer::ENGINE,
        ]);
    }

    public function filename(Customer $customer): string
    {
        return 'Extracto-'.BusinessDocumentIssuer::slug($customer->name).'-'.now('Africa/Luanda')->format('Y-m-d').'.pdf';
    }
}
