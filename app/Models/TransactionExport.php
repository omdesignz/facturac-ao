<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransactionExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $public_id
 * @property int $workspace_id
 * @property int $legal_entity_id
 * @property int $user_id
 * @property string $status
 * @property array<string, mixed> $filters
 * @property list<int> $document_ids
 * @property int $processed_documents
 * @property string|null $failure_message
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['workspace_id', 'legal_entity_id', 'user_id', 'status', 'filters', 'document_ids', 'processed_documents', 'failure_message', 'expires_at'])]
class TransactionExport extends Model
{
    /** @use HasFactory<TransactionExportFactory> */
    use HasFactory, HasUlids;

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function storagePath(): string
    {
        return 'transaction-exports/'.$this->public_id.'.xlsx';
    }

    /** @phpstan-impure */
    public function requesterHasAccess(): bool
    {
        return WorkspaceMembership::query()->where('workspace_id', $this->workspace_id)
            ->where('user_id', $this->user_id)->where('is_active', true)->exists();
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'id' => $this->public_id,
            'title' => $this->filters['title'],
            'status' => $this->expires_at->isPast() ? 'expired' : $this->status,
            'processed' => $this->processed_documents,
            'total' => count($this->document_ids),
            'failure' => $this->failure_message,
            'created_at' => $this->created_at->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String(),
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['filters' => 'array', 'document_ids' => 'array', 'processed_documents' => 'integer', 'expires_at' => 'immutable_datetime'];
    }
}
