<?php

namespace App\Fiscal\Pos;

use App\AgtConnectionStatus;
use App\FiscalDocumentType;
use App\FiscalSeriesContingency;
use App\FiscalSeriesStatus;
use App\Models\Establishment;
use App\Models\FiscalSeries;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which Factura/Recibo series a sale at an establishment will be numbered from.
 *
 * The constraints are the invoice editor's own (open or in use, normal
 * contingency, software invoicing, numbers left, a verified AGT connection on
 * schema 2.0) narrowed to FR, to one establishment and to the series of the
 * current fiscal year, since the issuing step refuses any other year.
 */
final class PosSeriesResolver
{
    public function forEstablishment(LegalEntity $legalEntity, Establishment $establishment): ?FiscalSeries
    {
        return $this->eligible($legalEntity, $establishment)
            ->orderByDesc('series_year')
            ->orderBy('series_code')
            ->first();
    }

    public function exists(LegalEntity $legalEntity, Establishment $establishment): bool
    {
        return $this->eligible($legalEntity, $establishment)->exists();
    }

    /** @return Builder<FiscalSeries> */
    private function eligible(LegalEntity $legalEntity, Establishment $establishment): Builder
    {
        return FiscalSeries::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('establishment_id', $establishment->id)
            ->where('document_type', FiscalDocumentType::InvoiceReceipt)
            ->where('series_year', (int) now('Africa/Luanda')->format('Y'))
            ->whereIn('status', [FiscalSeriesStatus::Open, FiscalSeriesStatus::InUse])
            ->where('contingency_indicator', FiscalSeriesContingency::Normal)
            ->where('invoicing_method', 'FESF')
            ->whereColumn('next_number', '<=', 'last_authorized_number')
            ->whereHas('agtConnection', fn (Builder $query) => $query
                ->where('status', AgtConnectionStatus::Verified)
                ->where('schema_version', '2.0'));
    }
}
