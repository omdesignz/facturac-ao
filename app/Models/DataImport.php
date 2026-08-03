<?php

namespace App\Models;

use App\DataImportSource;
use App\DataImportStatus;
use App\DataImportType;
use Carbon\CarbonImmutable;
use Database\Factories\DataImportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int|null $uploaded_by_user_id
 * @property DataImportType $type
 * @property DataImportSource $source
 * @property DataImportStatus $status
 * @property string $original_name
 * @property string $storage_disk
 * @property string|null $storage_path
 * @property string $file_extension
 * @property string $mime_type
 * @property int $file_size
 * @property string $sha256
 * @property list<string>|null $headers
 * @property array<string, string>|null $column_mapping
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $imported_rows
 * @property int $created_rows
 * @property int $updated_rows
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property CarbonImmutable|null $mapped_at
 * @property CarbonImmutable|null $validated_at
 * @property CarbonImmutable|null $committed_at
 * @property CarbonImmutable|null $cancelled_at
 */
#[Fillable([
    'workspace_id',
    'legal_entity_id',
    'uploaded_by_user_id',
    'type',
    'source',
    'status',
    'original_name',
    'storage_disk',
    'storage_path',
    'file_extension',
    'mime_type',
    'file_size',
    'sha256',
    'headers',
    'column_mapping',
    'total_rows',
    'valid_rows',
    'invalid_rows',
    'imported_rows',
    'created_rows',
    'updated_rows',
    'failure_code',
    'failure_message',
    'mapped_at',
    'validated_at',
    'committed_at',
    'cancelled_at',
])]
#[Hidden(['storage_disk', 'storage_path'])]
class DataImport extends Model
{
    /** @use HasFactory<DataImportFactory> */
    use HasFactory, HasUlids, LogsActivity;

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<LegalEntity, $this> */
    public function legalEntity(): BelongsTo
    {
        return $this->belongsTo(LegalEntity::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /** @return HasMany<DataImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(DataImportRow::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('data-import')
            ->logOnly([
                'type',
                'source',
                'status',
                'original_name',
                'sha256',
                'total_rows',
                'valid_rows',
                'invalid_rows',
                'imported_rows',
                'created_rows',
                'updated_rows',
                'failure_code',
            ])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DataImportType::class,
            'source' => DataImportSource::class,
            'status' => DataImportStatus::class,
            'headers' => 'array',
            'column_mapping' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'imported_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'mapped_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'committed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
