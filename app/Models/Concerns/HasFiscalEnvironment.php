<?php

namespace App\Models\Concerns;

use App\Models\AgtConnection;
use DomainException;

trait HasFiscalEnvironment
{
    protected static function bootHasFiscalEnvironment(): void
    {
        static::creating(function (self $record): void {
            $connection = AgtConnection::query()->findOrFail($record->agt_connection_id);
            $environment = $record->getAttributes()['environment'] ?? null;
            if ($record->workspace_id !== $connection->workspace_id || $record->legal_entity_id !== $connection->legal_entity_id
                || ($environment !== null && $environment !== $connection->environment->value)) {
                throw new DomainException('Fiscal environment must match its scoped connection.');
            }
            $record->environment = $connection->environment->value;
        });
        static::updating(function (self $record): void {
            if ($record->isDirty(['environment', 'agt_connection_id', 'workspace_id', 'legal_entity_id'])) {
                throw new DomainException('Bound fiscal environment identity is immutable.');
            }
        });
    }
}
