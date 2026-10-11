<?php

namespace App\Fiscal\Pos;

use App\Models\PosCashMovement;
use App\Models\PosSale;
use App\Models\PosSession;
use Illuminate\Support\Collection;

/**
 * The shapes the till and the shift report read, in one place so the page, the
 * sale response and the report cannot drift apart.
 *
 * @phpstan-import-type Summary from PosSessionSummary
 */
final class PosPresenter
{
    /**
     * The part of a summary the till shows while selling.
     *
     * @param  Summary  $summary
     * @return array{
     *     sales_count: int,
     *     gross_total_minor: int,
     *     by_method: list<array{method: string, label: string, count: int, total_minor: int}>,
     *     cash_sales_minor: int,
     *     cash_in_minor: int,
     *     cash_out_minor: int,
     *     expected_cash_minor: int
     * }
     */
    public function tillSummary(array $summary): array
    {
        return [
            'sales_count' => $summary['sales_count'],
            'gross_total_minor' => $summary['gross_total_minor'],
            'by_method' => $summary['by_method'],
            'cash_sales_minor' => $summary['cash_sales_minor'],
            'cash_in_minor' => $summary['cash_in_minor'],
            'cash_out_minor' => $summary['cash_out_minor'],
            'expected_cash_minor' => $summary['expected_cash_minor'],
        ];
    }

    /**
     * @return array{
     *     public_id: string,
     *     document_public_id: string,
     *     document_no: string|null,
     *     customer_name: string,
     *     total_minor: int,
     *     payment_method: string,
     *     payment_method_label: string,
     *     issued_at: string|null,
     *     receipt_url: string
     * }
     */
    public function sale(PosSale $sale): array
    {
        $document = $sale->fiscalDocument;

        return [
            'public_id' => $sale->public_id,
            'document_public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'customer_name' => $document->customer_name,
            'total_minor' => $sale->total_minor,
            'payment_method' => $sale->payment_method->value,
            'payment_method_label' => $sale->payment_method->label(),
            'issued_at' => $document->issued_at?->toIso8601String(),
            'receipt_url' => route('pos.sales.receipt', $sale, false),
        ];
    }

    /**
     * @param  Collection<int, PosSale>  $sales
     * @return list<array<string, mixed>>
     */
    public function sales(Collection $sales): array
    {
        return array_values($sales->map(fn (PosSale $sale): array => $this->sale($sale))->all());
    }

    /**
     * @return array{public_id: string, type: string, type_label: string, amount_minor: int, reason: string, created_at: string|null}
     */
    public function cashMovement(PosCashMovement $movement): array
    {
        return [
            'public_id' => $movement->public_id,
            'type' => $movement->type->value,
            'type_label' => $movement->type->label(),
            'amount_minor' => $movement->amount_minor,
            'reason' => $movement->reason,
            'created_at' => $movement->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{public_id: string, opened_by_name: string, opened_at: string, is_mine: bool}
     */
    public function openSessionOf(PosSession $session, int $viewerId): array
    {
        return [
            'public_id' => $session->public_id,
            'opened_by_name' => $session->openedBy->name,
            'opened_at' => $session->opened_at->toIso8601String(),
            'is_mine' => $session->opened_by_user_id === $viewerId,
        ];
    }
}
