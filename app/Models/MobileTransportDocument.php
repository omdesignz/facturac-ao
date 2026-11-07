<?php

namespace App\Models;

use Database\Factories\MobileTransportDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a request from the phone to the transport guide it issued.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $transport_document_id
 * @property string $client_key
 * @property int $created_by_user_id
 * @property-read TransportDocument $transportDocument
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'transport_document_id',
    'client_key',
    'created_by_user_id',
])]
class MobileTransportDocument extends Model
{
    /** @use HasFactory<MobileTransportDocumentFactory> */
    use HasFactory;

    /** Not a column: whether this instance is a replay of an earlier request. */
    public bool $replayed = false;

    /** @return BelongsTo<TransportDocument, $this> */
    public function transportDocument(): BelongsTo
    {
        return $this->belongsTo(TransportDocument::class);
    }
}
