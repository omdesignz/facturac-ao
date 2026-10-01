<?php

namespace App\Fiscal\Documents;

use Carbon\CarbonInterface;

final readonly class FiscalSeriesYearWindow
{
    /** @return list<int> */
    public function allowedYears(CarbonInterface $at): array
    {
        $luanda = $at->clone()->timezone('Africa/Luanda');
        $year = (int) $luanda->format('Y');
        $monthAndDay = (int) $luanda->format('md');

        return $monthAndDay > 1215 ? [$year, $year + 1] : [$year];
    }
}
