<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\DB;

/**
 * The full signed-bigint interval for one workspace/entity is contiguous in the
 * approved composite index. Keep that tuple ordering so PostgreSQL cannot satisfy
 * discovery by scanning the global primary key and filtering other contexts.
 */
final class CustomerSearchQuery
{
    /** @return array{sql: string, bindings: list<int|string|bool>} */
    public static function statement(DocumentReadContext $context, CustomerSearchCommand $command): array
    {
        $postgres = DB::getDriverName() === 'pgsql';
        abort_unless($postgres || DB::getDriverName() === 'sqlite', 503);
        $filter = ($postgres ? 'strpos' : 'instr').'(c.name, ?) > 0';
        $bindings = [$context->workspaceId(), $context->legalEntityId(), PHP_INT_MIN,
            $context->workspaceId(), $context->legalEntityId(), PHP_INT_MAX,
            $context->workspaceId(), $context->legalEntityId(), $command->search];
        if ($command->status !== 'all') {
            $filter .= ' AND c.is_active = ?';
            $bindings[] = $command->status === 'active';
        }
        $payload = $postgres
            ? 'CROSS JOIN LATERAL (SELECT id, public_id, name, country_code, is_active FROM customers
                WHERE id = k.id AND workspace_id = ? AND legal_entity_id = ? OFFSET 0) c'
            : 'CROSS JOIN customers c ON c.id = k.id AND c.workspace_id = ? AND c.legal_entity_id = ?';
        $sql = 'WITH candidate_keys AS MATERIALIZED (
            SELECT id FROM customers
            WHERE (workspace_id, legal_entity_id, id) >= (?, ?, ?)
                AND (workspace_id, legal_entity_id, id) <= (?, ?, ?)
            ORDER BY workspace_id DESC, legal_entity_id DESC, id DESC LIMIT 10001
        ), admission AS MATERIALIZED (SELECT COUNT(*) > 10000 AS over_cap FROM candidate_keys),
        matches AS MATERIALIZED (
            SELECT c.id, c.public_id, c.name, c.country_code, c.is_active
            FROM admission a CROSS JOIN candidate_keys k '.$payload.'
            WHERE NOT a.over_cap AND '.$filter.' ORDER BY k.id DESC LIMIT 11
        ) SELECT a.over_cap, m.id, m.public_id, m.name, m.country_code, m.is_active
            FROM admission a LEFT JOIN matches m ON TRUE ORDER BY m.id DESC';

        return ['sql' => $sql, 'bindings' => $bindings];
    }
}
