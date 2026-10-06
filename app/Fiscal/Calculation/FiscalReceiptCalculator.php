<?php

namespace App\Fiscal\Calculation;

use App\Models\FiscalDocument;
use App\Models\FiscalDocumentSettlement;
use Illuminate\Validation\ValidationException;

final readonly class FiscalReceiptCalculator
{
    public function __construct(private FiscalCalculator $calculator) {}

    /**
     * Cumulative allocation gives the last partial payment every remaining cent.
     * Issued snapshots are never recalculated when another receipt is created.
     *
     * @return array{
     *     net_total_minor: int, tax_payable_minor: int, gross_total_minor: int,
     *     allocations: list<array{settled_document_id: int, settled_document_no: string, amount_minor: int, net_amount_minor: int, tax_amount_minor: int, withholding_allocations: list<array{type: string, base_minor: int, rate_basis_points: int, amount_minor: int}>}>,
     *     withholdings: list<array{type: string, base_minor: int, rate_basis_points: int, amount_minor: int}>
     * }
     */
    public function calculate(FiscalDocument $receipt): array
    {
        $receipt->loadMissing([
            'settlements.settledDocument.withholdings',
            'settlements.settledDocument.settledBy.receipt',
        ]);
        $netTotal = 0;
        $taxTotal = 0;
        $allocations = [];
        $withholdings = [];

        foreach ($receipt->settlements->values() as $index => $settlement) {
            $source = $settlement->settledDocument;
            $errorKey = "settlements.{$index}.amount";

            if ($source->isMutable()
                || $source->workspace_id !== $receipt->workspace_id
                || $source->legal_entity_id !== $receipt->legal_entity_id
                || $source->currency_code !== $receipt->currency_code
                || $source->customer_tax_identification_number !== $receipt->customer_tax_identification_number
                || $source->customer_country_code !== $receipt->customer_country_code
                || $source->document_type->isReceipt()
                || $source->gross_total_minor <= 0
                || $source->net_total_minor + $source->tax_payable_minor !== $source->gross_total_minor) {
                throw ValidationException::withMessages([
                    $errorKey => 'O recibo deve liquidar documentos emitidos do mesmo cliente, empresa e moeda, com totais válidos.',
                ]);
            }

            $prior = $source->settledBy->filter(fn (FiscalDocumentSettlement $payment): bool => $payment->fiscal_document_id !== $receipt->id && ! $payment->receipt->isMutable()
            );
            $paidBefore = (int) $prior->sum('amount_minor');
            $paidNow = $settlement->amount_minor;

            if ($paidNow <= 0 || $paidBefore > $source->gross_total_minor - $paidNow) {
                throw ValidationException::withMessages([
                    $errorKey => 'O montante excede o saldo ainda disponível para liquidação.',
                ]);
            }

            $legacyPaid = (int) $prior->whereNull('tax_amount_minor')->sum('amount_minor');
            $taxBefore = (int) $prior->sum('tax_amount_minor')
                + $this->calculator->apportionedAmount($source->tax_payable_minor, $legacyPaid, $source->gross_total_minor);
            $taxNow = $this->calculator->apportionedAmount(
                $source->tax_payable_minor, $paidBefore + $paidNow, $source->gross_total_minor,
            ) - $taxBefore;

            if ($taxNow < 0 || $taxNow > $paidNow) {
                throw ValidationException::withMessages([$errorKey => 'A repartição do imposto não pôde ser validada.']);
            }

            $factor = $source->document_type->reducesReceivable() ? -1 : 1;
            $sourceWithholdings = [];

            foreach ($source->withholdings as $withholding) {
                $type = $withholding->withholding_type->value;
                $legacyWithheldPaid = 0;
                $baseBefore = 0;
                $amountBefore = 0;

                foreach ($prior as $payment) {
                    if ($payment->withholding_allocations === null) {
                        $legacyWithheldPaid += $payment->amount_minor;

                        continue;
                    }

                    foreach ($payment->withholding_allocations as $entry) {
                        if ($entry['type'] === $type) {
                            $baseBefore += $entry['base_minor'];
                            $amountBefore += $entry['amount_minor'];
                        }
                    }
                }

                $baseBefore += $this->calculator->apportionedAmount($withholding->base_minor, $legacyWithheldPaid, $source->gross_total_minor);
                $amountBefore += $this->calculator->apportionedAmount($withholding->amount_minor, $legacyWithheldPaid, $source->gross_total_minor);
                $entry = [
                    'type' => $type,
                    'base_minor' => $this->calculator->apportionedAmount($withholding->base_minor, $paidBefore + $paidNow, $source->gross_total_minor) - $baseBefore,
                    'rate_basis_points' => $withholding->rate_basis_points,
                    'amount_minor' => $this->calculator->apportionedAmount($withholding->amount_minor, $paidBefore + $paidNow, $source->gross_total_minor) - $amountBefore,
                ];
                $sourceWithholdings[] = $entry;
                $withholdings[$type] ??= ['type' => $type, 'base_minor' => 0, 'rate_basis_points' => $entry['rate_basis_points'], 'amount_minor' => 0];
                $withholdings[$type]['base_minor'] += $factor * $entry['base_minor'];
                $withholdings[$type]['amount_minor'] += $factor * $entry['amount_minor'];

                if ($withholdings[$type]['rate_basis_points'] !== $entry['rate_basis_points']) {
                    $withholdings[$type]['rate_basis_points'] = 0;
                }
            }

            $netNow = $paidNow - $taxNow;
            $netTotal += $factor * $netNow;
            $taxTotal += $factor * $taxNow;
            $allocations[] = [
                'settled_document_id' => $source->id,
                'settled_document_no' => $settlement->settled_document_no,
                'amount_minor' => $paidNow,
                'net_amount_minor' => $netNow,
                'tax_amount_minor' => $taxNow,
                'withholding_allocations' => $sourceWithholdings,
            ];
        }

        if ($allocations === [] || $netTotal < 0 || $taxTotal < 0 || $netTotal + $taxTotal <= 0
            || collect($withholdings)->contains(fn (array $entry): bool => $entry['amount_minor'] < 0 || $entry['base_minor'] < 0)) {
            throw ValidationException::withMessages(['settlements' => 'Este fluxo exige uma liquidação positiva, não um reembolso.']);
        }

        ksort($withholdings);

        return [
            'net_total_minor' => $netTotal,
            'tax_payable_minor' => $taxTotal,
            'gross_total_minor' => $netTotal + $taxTotal,
            'allocations' => $allocations,
            'withholdings' => array_values($withholdings),
        ];
    }
}
