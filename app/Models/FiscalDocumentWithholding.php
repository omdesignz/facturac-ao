<?php

namespace App\Models;

use App\WithholdingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One tax the buyer keeps back on one document.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property WithholdingType $withholding_type
 * @property int $base_minor
 * @property int $rate_basis_points
 * @property int $amount_minor
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'withholding_type',
    'base_minor',
    'rate_basis_points',
    'amount_minor',
])]
class FiscalDocumentWithholding extends Model
{
    /** @return BelongsTo<FiscalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'withholding_type' => WithholdingType::class,
            'base_minor' => 'integer',
            'rate_basis_points' => 'integer',
            'amount_minor' => 'integer',
        ];
    }
}
