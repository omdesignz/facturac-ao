<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ArchivedPdfFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The PDF of a fiscal document exactly as it was issued.
 *
 * Rendered once and kept, so a later template, font or library change can
 * never alter what a customer was given. The hash is what proves the bytes
 * served today are the bytes stored then.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $fiscal_document_id
 * @property string $disk
 * @property string $path
 * @property string $sha256
 * @property int $byte_size
 * @property string $renderer
 * @property CarbonImmutable $rendered_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'fiscal_document_id',
    'disk',
    'path',
    'sha256',
    'byte_size',
    'renderer',
    'rendered_at',
])]
class ArchivedPdf extends Model
{
    /** @use HasFactory<ArchivedPdfFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new DomainException('An archived document PDF is evidence and cannot be changed.');
        });

        static::deleting(function (): never {
            throw new DomainException('An archived document PDF cannot be deleted.');
        });
    }

    /** @return BelongsTo<FiscalDocument, $this> */
    public function fiscalDocument(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'rendered_at' => 'immutable_datetime',
        ];
    }
}
