<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class FirstWorksheetImport implements WithMultipleSheets
{
    public function __construct(
        private readonly StagedWorksheetImport $worksheetImport,
    ) {}

    /** @return array<int, StagedWorksheetImport> */
    public function sheets(): array
    {
        return [0 => $this->worksheetImport];
    }
}
