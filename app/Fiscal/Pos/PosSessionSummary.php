<?php

namespace App\Fiscal\Pos;

use App\Models\PosSession;
use App\PaymentMethod;
use App\PosCashMovementType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What a shift has taken, worked out from the documents it issued.
 *
 * Nothing here comes from the till. The totals are summed from the issued
 * Factura/Recibo rows reached through the sales that link them to the shift, so
 * the figure the cashier counts the drawer against is the figure the books hold.
 * Integer minor units throughout.
 *
 * @phpstan-type MethodTotal array{method: string, label: string, count: int, total_minor: int}
 * @phpstan-type Summary array{
 *     sales_count: int,
 *     gross_total_minor: int,
 *     net_total_minor: int,
 *     tax_total_minor: int,
 *     by_method: list<MethodTotal>,
 *     cash_sales_minor: int,
 *     cash_in_minor: int,
 *     cash_out_minor: int,
 *     expected_cash_minor: int,
 *     first_document_no: string|null,
 *     last_document_no: string|null
 * }
 */
final class PosSessionSummary
{
    /**
     * @return Summary
     */
    public function forSession(PosSession $session): array
    {
        $rows = $this->sales($session)
            ->groupBy('pos_sales.payment_method')
            ->get([
                'pos_sales.payment_method',
                DB::raw('count(*) as sales_count'),
                DB::raw('coalesce(sum(fiscal_documents.gross_total_minor), 0) as gross_minor'),
                DB::raw('coalesce(sum(fiscal_documents.net_total_minor), 0) as net_minor'),
                DB::raw('coalesce(sum(fiscal_documents.tax_payable_minor), 0) as tax_minor'),
            ]);

        $salesCount = 0;
        $gross = 0;
        $net = 0;
        $tax = 0;
        /** @var array<string, array{count: int, total_minor: int}> $perMethod */
        $perMethod = [];

        foreach ($rows as $row) {
            $salesCount += (int) $row->sales_count;
            $gross += (int) $row->gross_minor;
            $net += (int) $row->net_minor;
            $tax += (int) $row->tax_minor;
            $perMethod[(string) $row->payment_method] = [
                'count' => (int) $row->sales_count,
                'total_minor' => (int) $row->gross_minor,
            ];
        }

        $byMethod = [];

        foreach ($this->methodOrder() as $method) {
            if (! isset($perMethod[$method->value])) {
                continue;
            }

            $byMethod[] = [
                'method' => $method->value,
                'label' => $method->label(),
                'count' => $perMethod[$method->value]['count'],
                'total_minor' => $perMethod[$method->value]['total_minor'],
            ];
            unset($perMethod[$method->value]);
        }

        // Any method outside the till's own list (a sale imported or amended
        // by other means) still counts rather than vanishing from the total.
        foreach ($perMethod as $value => $figures) {
            $byMethod[] = [
                'method' => $value,
                'label' => PaymentMethod::tryFrom($value)?->label() ?? $value,
                'count' => $figures['count'],
                'total_minor' => $figures['total_minor'],
            ];
        }

        $cashSales = 0;

        foreach ($byMethod as $row) {
            if ($row['method'] === PaymentMethod::Cash->value) {
                $cashSales = $row['total_minor'];
            }
        }

        $cashIn = $this->cashMovements($session, PosCashMovementType::In);
        $cashOut = $this->cashMovements($session, PosCashMovementType::Out);

        return [
            'sales_count' => $salesCount,
            'gross_total_minor' => $gross,
            'net_total_minor' => $net,
            'tax_total_minor' => $tax,
            'by_method' => $byMethod,
            'cash_sales_minor' => $cashSales,
            'cash_in_minor' => $cashIn,
            'cash_out_minor' => $cashOut,
            'expected_cash_minor' => $session->opening_float_minor + $cashSales + $cashIn - $cashOut,
            'first_document_no' => $this->sales($session)->orderBy('pos_sales.id')->value('fiscal_documents.document_no'),
            'last_document_no' => $this->sales($session)->orderByDesc('pos_sales.id')->value('fiscal_documents.document_no'),
        ];
    }

    /**
     * The cash that should be in the drawer right now.
     */
    public function expectedCash(PosSession $session): int
    {
        return $this->forSession($session)['expected_cash_minor'];
    }

    /** @return list<PaymentMethod> */
    private function methodOrder(): array
    {
        return PosPaymentMethods::all();
    }

    private function sales(PosSession $session): Builder
    {
        return DB::table('pos_sales')
            ->join('fiscal_documents', 'fiscal_documents.id', '=', 'pos_sales.fiscal_document_id')
            ->where('pos_sales.pos_session_id', $session->id);
    }

    private function cashMovements(PosSession $session, PosCashMovementType $type): int
    {
        return (int) DB::table('pos_cash_movements')
            ->where('pos_session_id', $session->id)
            ->where('type', $type->value)
            ->sum('amount_minor');
    }
}
