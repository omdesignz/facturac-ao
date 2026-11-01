<?php

namespace App\Models;

use Database\Factories\MobileInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a request from the phone to the document it produced.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property string $client_key
 * @property int $created_by_user_id
 * @property-read FiscalDocument $fiscalDocument
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'client_key',
    'created_by_user_id',
])]
class MobileInvoice extends Model
{
    /** @use HasFactory<MobileInvoiceFactory> */
    use HasFactory;

    /** Not a column: whether this instance is a replay of an earlier request. */
    public bool $replayed = false;

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
