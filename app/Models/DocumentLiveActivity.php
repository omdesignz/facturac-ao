<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document followed on a phone's lock screen.
 *
 * @property int $id
 * @property int $user_id
 * @property int $personal_access_token_id
 * @property int $fiscal_document_id
 * @property string $token
 * @property string $environment
 * @property CarbonImmutable|null $ended_at
 */
#[Fillable(['user_id', 'personal_access_token_id', 'fiscal_document_id', 'token', 'environment', 'ended_at'])]
class DocumentLiveActivity extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['ended_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
