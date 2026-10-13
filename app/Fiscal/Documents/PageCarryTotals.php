<?php

namespace App\Fiscal\Documents;

use LogicException;
use Mpdf\Mpdf;

/** Cumulative amounts from the pages mPDF actually laid out, in integer cêntimos. */
class PageCarryTotals
{
    public function __construct(private PrintFormat $format) {}

    /**
     * @param  list<int>  $amounts
     * @return array<int, array{brought: int, carried: int}>
     */
    public function apply(Mpdf $pdf, array $amounts, string $currency): array
    {
        $lastPage = $pdf->page;
        if ($lastPage < 2) {
            return [];
        }

        $pageAmounts = array_fill(1, $lastPage, 0);
        foreach ($amounts as $index => $amount) {
            $anchor = $pdf->internallink['fiscal-row-'.$index] ?? null;
            if (! is_array($anchor) || ! isset($anchor['PAGE'], $pageAmounts[$anchor['PAGE']])) {
                throw new LogicException('A fiscal row could not be located on the printed pages.');
            }
            $pageAmounts[$anchor['PAGE']] += $amount;
        }

        $cumulative = 0;
        $totals = [];
        foreach ($pageAmounts as $page => $amount) {
            $totals[$page] = ['brought' => $cumulative, 'carried' => $cumulative + $amount];
            $pdf->page = $page;
            if ($page > 1) {
                $this->draw($pdf, 'Valor transportado:', $cumulative, $currency, (int) $pdf->tMargin - 9);
            }
            $cumulative += $amount;
            if ($page < $lastPage) {
                $this->draw($pdf, 'Valor a transportar:', $cumulative, $currency, 264);
            }
        }
        $pdf->page = $lastPage;

        return $totals;
    }

    private function draw(Mpdf $pdf, string $label, int $amount, string $currency, int $y): void
    {
        $value = e($this->format->money($amount).' '.$currency);
        $pdf->WriteFixedPosHTML(
            '<div style="font-family: hankengrotesk; font-size: 9pt; text-align: right; border-top: 0.4pt solid #777; padding-top: 3pt;">'
                .$label.' <b>'.$value.'</b></div>',
            10, $y, 190, 7,
        );
    }
}
