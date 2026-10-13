<?php

use App\Fiscal\Documents\PageCarryTotals;
use Mpdf\Mpdf;

/** Real mPDF pagination, including wrapped rows, negative receipt adjustments and a summary-only page. */
test('carried figures follow actual row pages and accumulate without rounding', function () {
    $pdf = new Mpdf(['tempDir' => storage_path('framework/cache/mpdf'), 'margin_top' => 42, 'margin_bottom' => 35]);
    $amounts = [];
    $html = '<table><thead><tr><th>Descrição</th></tr></thead><tbody>';
    for ($i = 0; $i < 80; $i++) {
        $amounts[] = $i % 5 === 0 ? -103 : 101;
        $html .= '<tr><td><a name="fiscal-row-'.$i.'"></a>'.str_repeat('Descrição longa ', ($i % 4) + 1).'</td></tr>';
    }
    $pdf->WriteHTML($html.'</tbody></table><pagebreak />Resumo final');
    $pages = $pdf->page;
    expect($pages)->toBeGreaterThan(2);
    $expected = array_fill(1, $pages, 0);
    foreach ($amounts as $i => $value) {
        $expected[$pdf->internallink['fiscal-row-'.$i]['PAGE']] += $value;
    }
    $totals = app(PageCarryTotals::class)->apply($pdf, $amounts, 'AOA');
    $carried = 0;
    foreach ($expected as $page => $value) {
        expect($totals[$page])->toBe(['brought' => $carried, 'carried' => $carried + $value]);
        $carried += $value;
    }
    expect($totals[$pages]['brought'])->toBe(array_sum($amounts))
        ->and($pdf->page)->toBe($pages)
        ->and($pdf->OutputBinaryData())->toStartWith('%PDF-');
});
