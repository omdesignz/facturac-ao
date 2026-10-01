<?php

namespace App\Fiscal\Agt\Contracts;

use App\Fiscal\Agt\Data\AgtInvoiceStatusResult;
use App\Fiscal\Agt\Data\AgtProbeResult;
use App\Fiscal\Agt\Data\AgtRegistrationResult;
use App\Fiscal\Agt\Data\AgtSeriesListResult;
use App\Fiscal\Agt\Data\AgtSeriesRequestResult;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\Models\AgtConnection;
use App\Models\LegalEntity;

interface AgtGateway
{
    public function probeListSeries(AgtConnection $connection, LegalEntity $legalEntity): AgtProbeResult;

    public function listSeries(AgtConnection $connection, LegalEntity $legalEntity): AgtSeriesListResult;

    public function requestSeries(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        FiscalDocumentType $documentType,
        int $seriesYear,
        FiscalSeriesContingency $contingency,
        string $submissionUuid,
    ): AgtSeriesRequestResult;

    public function registerInvoice(AgtConnection $connection, string $requestBody): AgtRegistrationResult;

    public function queryInvoiceStatus(
        AgtConnection $connection,
        LegalEntity $legalEntity,
        string $requestId,
    ): AgtInvoiceStatusResult;
}
