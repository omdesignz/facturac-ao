<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\DB;

final class IntegrationSchemaGuards
{
    /**
     * @param  list<string>  $columns
     * @param  list<string>  $nullableRelationships
     */
    public static function immutable(string $table, array $columns, array $nullableRelationships = [], bool $terminal = false): void
    {
        self::drop($table);
        $name = $table.'_identity_guard';
        if (DB::getDriverName() === 'pgsql') {
            $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.$column IS DISTINCT FROM OLD.$column", $columns));
            foreach ($nullableRelationships as $column) {
                $condition .= " OR (NEW.$column IS NOT NULL AND NEW.$column IS DISTINCT FROM OLD.$column)";
            }
            if ($terminal) {
                $condition .= ' OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at)';
            }
            DB::statement("CREATE FUNCTION $name() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN IF $condition THEN RAISE EXCEPTION 'Immutable identity'; END IF; RETURN NEW; END; \$\$");
            DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW EXECUTE FUNCTION $name()");
        } elseif (DB::getDriverName() === 'sqlite') {
            $condition = implode(' OR ', array_map(fn (string $column): string => "NEW.$column IS NOT OLD.$column", $columns));
            foreach ($nullableRelationships as $column) {
                $condition .= " OR (NEW.$column IS NOT NULL AND NEW.$column IS NOT OLD.$column)";
            }
            if ($terminal) {
                $condition .= ' OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS NOT OLD.revoked_at)';
            }
            DB::statement("CREATE TRIGGER $name BEFORE UPDATE ON $table WHEN $condition BEGIN SELECT RAISE(ABORT, 'Immutable identity'); END");
        } else {
            throw new \RuntimeException('Integration integrity requires PostgreSQL or SQLite.');
        }
    }

    public static function drop(string $table): void
    {
        $name = $table.'_identity_guard';
        DB::statement(DB::getDriverName() === 'pgsql' ? "DROP TRIGGER IF EXISTS $name ON $table" : "DROP TRIGGER IF EXISTS $name");
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("DROP FUNCTION IF EXISTS $name()");
        }
    }

    public static function uuid(string $table, string $column): void
    {
        $valid = DB::getDriverName() === 'pgsql'
            ? "$column::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'"
            : "length($column) = 36 AND substr($column,9,1) = '-' AND substr($column,14,1) = '-' AND substr($column,19,1) = '-' AND substr($column,24,1) = '-' AND substr($column,15,1) = '4' AND substr($column,20,1) IN ('8','9','a','b') AND length(replace($column,'-','')) = 32 AND replace($column,'-','') NOT GLOB '*[^0-9a-f]*'";
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_{$column}_shape");
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_{$column}_shape CHECK ($valid)");
        } else {
            DB::statement("DROP TRIGGER IF EXISTS {$table}_{$column}_shape");
            DB::statement("CREATE TRIGGER {$table}_{$column}_shape BEFORE INSERT ON $table WHEN NEW.$column IS NULL OR NOT (".str_replace($column, 'NEW.'.$column, $valid).") BEGIN SELECT RAISE(ABORT, 'Invalid attribution identity'); END");
        }
    }
}
