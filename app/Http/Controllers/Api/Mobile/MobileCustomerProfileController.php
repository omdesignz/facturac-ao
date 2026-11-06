<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Analytics\ReceivablesQuery;
use App\Analytics\WhatsAppNumber;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * One customer on the phone: what they owe, how quickly they usually pay, and a
 * year of what they were billed against what they paid. The figures come from
 * the queries the web's customer page uses.
 */
class MobileCustomerProfileController
{
    private const int MONTHS = 12;

    public function __construct(private readonly ReceivablesQuery $receivables) {}

    public function __invoke(Request $request, string $customerPublicId): JsonResponse
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $customer = $legalEntity->customers()->where('public_id', $customerPublicId)->firstOrFail();

        Gate::authorize('view', $customer);

        $issued = fn (): Builder => ReceivablesQuery::issued(
            $customer->fiscalDocuments()->where('currency_code', $legalEntity->currency_code)->getQuery(),
        );
        $summary = $this->receivables->summarise($issued());

        return response()->json(['data' => [
            'public_id' => $customer->public_id,
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'whatsapp_to' => WhatsAppNumber::from($customer->phone),
            'address_line' => $customer->address_line,
            'payment_terms_days' => $customer->payment_terms_days,
            'can_edit' => Gate::allows('update', $customer),
            'currency_code' => $legalEntity->currency_code,
            'outstanding_minor' => $summary['outstanding_minor'],
            'overdue_minor' => $summary['overdue_minor'],
            'average_days_to_settle' => $this->receivables->averageDaysToSettle($issued()),
            'history' => $this->history($issued(), CarbonImmutable::now('Africa/Luanda')),
        ]]);
    }

    /**
     * Billed and paid per month, oldest first, with quiet months at zero.
     *
     * @param  Builder<FiscalDocument>  $query
     * @return list<array{month: string, billed_minor: int, paid_minor: int}>
     */
    private function history(Builder $query, CarbonImmutable $now): array
    {
        $first = $now->subMonthsNoOverflow(self::MONTHS - 1)->startOfMonth();
        $inWindow = fn (array $daily): array => array_filter(
            $daily,
            fn (string $day): bool => $day >= $first->toDateString(),
            ARRAY_FILTER_USE_KEY,
        );
        $billed = $this->byMonth($inWindow($this->receivables->dailyBilled(
            $query->clone()->where('document_date', '>=', $first->toDateString()),
        )));
        $paid = $this->byMonth($inWindow($this->receivables->dailyPaid($query)));

        return array_map(function (int $offset) use ($first, $billed, $paid): array {
            $month = $first->addMonthsNoOverflow($offset)->format('Y-m');

            return ['month' => $month, 'billed_minor' => $billed[$month] ?? 0, 'paid_minor' => $paid[$month] ?? 0];
        }, range(0, self::MONTHS - 1));
    }

    /**
     * @param  array<string, int>  $daily
     * @return array<string, int>
     */
    private function byMonth(array $daily): array
    {
        $monthly = [];

        foreach ($daily as $day => $total) {
            $month = substr($day, 0, 7);
            $monthly[$month] = ($monthly[$month] ?? 0) + $total;
        }

        return $monthly;
    }
}
