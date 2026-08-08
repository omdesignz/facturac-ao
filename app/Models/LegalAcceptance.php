<?php

namespace App\Models;

use App\LegalDocumentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A record that someone was shown a specific version and answered.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $legal_document_id
 * @property LegalDocumentType $type
 * @property int $version
 * @property array<string, bool>|null $choices
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $accepted_at
 */
#[Fillable([
    'user_id',
    'legal_document_id',
    'type',
    'version',
    'choices',
    'ip_address',
    'user_agent',
    'accepted_at',
])]
class LegalAcceptance extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<LegalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LegalDocumentType::class,
            'version' => 'integer',
            'choices' => 'array',
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
