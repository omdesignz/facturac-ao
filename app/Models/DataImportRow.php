<?php

namespace App\Models;

use App\DataImportRowStatus;
use Database\Factories\DataImportRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $data_import_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $row_number
 * @property DataImportRowStatus $status
 * @property array<string, mixed> $source_payload
 * @property array<string, mixed>|null $normalized_payload
 * @property array<string, list<string>>|null $validation_errors
 * @property string|null $target_type
 * @property int|null $target_id
 */
#[Fillable([
    'data_import_id',
    'workspace_id',
    'legal_entity_id',
    'row_number',
    'status',
    'source_payload',
    'normalized_payload',
    'validation_errors',
    'target_type',
    'target_id',
])]
#[Hidden(['source_payload', 'normalized_payload', 'validation_errors'])]
class DataImportRow extends Model
{
    /** @use HasFactory<DataImportRowFactory> */
    use HasFactory;

    /** @return BelongsTo<DataImport, $this> */
    public function dataImport(): BelongsTo
    {
        return $this->belongsTo(DataImport::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DataImportRowStatus::class,
            'source_payload' => 'encrypted:array',
            'normalized_payload' => 'encrypted:array',
            'validation_errors' => 'encrypted:array',
        ];
    }
}
