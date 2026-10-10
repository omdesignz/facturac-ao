<?php

namespace App\Http\Controllers;

use App\Analytics\AgingQuery;
use App\Analytics\ReceivablesQuery;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use App\Models\FiscalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Everything about one customer on one page: what they were billed, what they
 * paid, what they still owe, and the shape of that over time.
 */
class CustomerProfileController extends Controller
{
    public function __construct(
        private ReceivablesQuery $receivables,
        private AgingQuery $aging,
    ) {}

    public function show(Request $request, Customer $customer): Response
    {
        Gate::authorize('view', $customer);

        $issued = fn (): Builder => ReceivablesQuery::issued(
            $customer->fiscalDocuments()->where('currency_code', $customer->legalEntity->currency_code)->getQuery(),
        );

        // The summary, the trend and the mix are each one grouped query. Only
        // the page of documents actually on screen is loaded as models.
        $summary = $this->receivables->summarise($issued());

        $documents = $this->receivables
            ->withBalances($issued())
            ->orderByDesc('document_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Customers/Show', [
            'customer' => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'country_code' => $customer->country_code,
                'address_line' => $customer->address_line,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'is_active' => $customer->is_active,
                'created_at' => $customer->created_at?->toIso8601String(),
                'payment_terms_days' => $customer->payment_terms_days,
                'credit_limit_minor' => $customer->credit_limit_minor,
                'price_list' => $customer->priceList === null ? null : [
                    'public_id' => $customer->priceList->public_id,
                    'name' => $customer->priceList->name,
                ],
                'auto_send_documents' => $customer->auto_send_documents,
            ],
            'statement' => $this->aging->statement($issued()),
            'agreedPrices' => $this->agreedPrices($customer),
            'catalogueOptions' => $this->catalogueOptions($customer),
            'summary' => [
                ...$summary,
                'average_days_to_settle' => $this->receivables->averageDaysToSettle($issued()),
            ],
            'trend' => $this->monthlyTrend($issued()),
            'documents' => [
                'data' => $this->presentDocuments($documents->getCollection()),
                'links' => $documents->linkCollection()->all(),
                'total' => $documents->total(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
            'typeMix' => $this->typeMix($issued()),
            'currencyCode' => $customer->legalEntity->currency_code,
        ]);
    }

    /**
     * The prices agreed with this customer, newest first.
     *
     * @return list<array<string, mixed>>
     */
    private function agreedPrices(Customer $customer): array
    {
        return array_values($customer->prices()
            ->with('catalogueItem')
            ->get()
            ->sortBy(fn (CustomerPrice $price): string => $price->catalogueItem->name)
            ->map(fn (CustomerPrice $price): array => [
                'id' => $price->id,
                'item' => $price->catalogueItem->name,
                'code' => $price->catalogueItem->code,
                'unit_price_minor' => $price->unit_price_minor,
                'list_price_minor' => $price->catalogueItem->unit_price_minor,
                'note' => $price->note,
            ])
            ->values()
            ->all());
    }

    /**
     * Articles that can still have a price agreed — the ones that do not
     * already, so the picker cannot silently overwrite an existing agreement.
     *
     * @return list<array{value: string, label: string}>
     */
    private function catalogueOptions(Customer $customer): array
    {
        $agreed = $customer->prices()->pluck('catalogue_item_id')->all();

        return array_values(CatalogueItem::query()
            ->where('legal_entity_id', $customer->legal_entity_id)
            ->where('is_active', true)
            ->whereNotIn('id', $agreed)
            ->orderBy('name')
            ->get()
            ->map(fn (CatalogueItem $item): array => [
                'value' => $item->public_id,
                'label' => "{$item->code} — {$item->name}",
            ])
            ->all());
    }

    /**
     * Billing per month for the last 12, against the same months a year before.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{label: string, value: int, comparison: int}>
     */
    private function monthlyTrend(Builder $query): array
    {
        $now = CarbonImmutable::now('Africa/Luanda');
        $monthly = [];

        // Grouped by day in SQL, then folded into months here: at most a couple
        // of years of dates rather than every document ever issued.
        foreach ($this->receivables->dailyBilled(
            $query->clone()->where('document_date', '>=', $now->subMonths(23)->startOfMonth()->toDateString()),
        ) as $date => $total) {
            $key = substr($date, 0, 7);
            $monthly[$key] = ($monthly[$key] ?? 0) + $total;
        }

        $points = [];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $now->subMonths($offset)->startOfMonth();

            $points[] = [
                'label' => $month->translatedFormat('M'),
                'value' => $monthly[$month->format('Y-m')] ?? 0,
                'comparison' => $monthly[$month->subYear()->format('Y-m')] ?? 0,
            ];
        }

        return $points;
    }

    /**
     * @param  Collection<int, FiscalDocument>  $documents
     * @return list<array<string, mixed>>
     */
    private function presentDocuments(Collection $documents): array
    {
        return array_values($documents->map(function (FiscalDocument $document): array {
            $outstanding = $this->receivables->outstandingMinor($document);

            return [
                'public_id' => $document->public_id,
                'document_no' => $document->document_no,
                'document_type' => $document->document_type->value,
                'document_type_label' => $document->document_type->label(),
                'document_date' => $document->document_date->toIso8601String(),
                // A receipt has nothing to fall due: showing it a payment date
                // would invite the reader to chase money already received.
                'due_date' => $this->receivables->isBillable($document->document_type)
                    ? $document->due_date?->toIso8601String()
                    : null,
                'status' => $document->status->value,
                'status_label' => $document->status->label(),
                'gross_total_minor' => $document->gross_total_minor,
                'paid_minor' => $this->receivables->paidMinor($document),
                'outstanding_minor' => $outstanding,
                'is_billable' => $this->receivables->isBillable($document->document_type),
                'is_credit' => $document->document_type === FiscalDocumentType::CreditNote,
                'overdue' => $this->receivables->isOverdue($document),
                'currency_code' => $document->currency_code,
                // Only an issued document can be emailed; a draft has no number
                // to send and nothing filed behind it.
                'can_send' => Gate::allows('deliver', $document),
                'sent_at' => $document->sent_to_customer_at?->toIso8601String(),
                'send_count' => $document->send_count,
            ];
        })->all());
    }

    /**
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{label: string, value: int, detail: string, slot: int}>
     */
    private function typeMix(Builder $query): array
    {
        $totals = $this->receivables->totalsByType($query);
        $mix = [];
        $slot = 0;

        foreach (FiscalDocumentType::issuable() as $type) {
            $slot++;
            $entry = $totals[$type->value] ?? null;

            // A receipt carries no value of its own — it settles an invoice.
            // Plotting it at zero on a value chart invites the reader to think
            // the receipts were worth nothing rather than that they are not
            // measured here.
            if ($entry === null || $entry['total_minor'] <= 0) {
                continue;
            }

            $mix[] = [
                'label' => $type->label(),
                'value' => $entry['total_minor'],
                'detail' => $entry['document_count'].'×',
                'slot' => min(5, $slot),
            ];
        }

        return $mix;
    }
}
