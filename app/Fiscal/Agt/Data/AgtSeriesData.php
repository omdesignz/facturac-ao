<?php

namespace App\Fiscal\Agt\Data;

use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;

final readonly class AgtSeriesData
{
    public function __construct(
        public string $seriesCode,
        public int $seriesYear,
        public FiscalDocumentType $documentType,
        public FiscalSeriesStatus $status,
        public ?string $creationDate,
        public string $firstDocumentApproved,
        public string $lastDocumentApproved,
        public ?string $firstDocumentCreated,
        public ?string $lastDocumentCreated,
        public string $invoicingMethod,
        public FiscalSeriesContingency $contingency,
    ) {}
}
