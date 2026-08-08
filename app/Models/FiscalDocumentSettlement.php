<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One invoice paid off by one receipt, for the amount stated on that receipt.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property int $settled_document_id
 * @property string $settled_document_no
 * @property int $amount_minor
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'settled_document_id',
    'settled_document_no',
    'amount_minor',
])]
class FiscalDocumentSettlement extends Model
{
    /** @return BelongsTo<FiscalDocument, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'fiscal_document_id');
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function settledDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class, 'settled_document_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer'];
    }
}
