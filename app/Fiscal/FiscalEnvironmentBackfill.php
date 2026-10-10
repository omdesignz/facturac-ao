<?php

namespace App\Fiscal;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/** Version one: only explicit, agreeing connection links are evidence. */
final class FiscalEnvironmentBackfill
{
    /** @return array{resolved: int, unresolved: list<int>} */
    public function scan(bool $apply = false): array
    {
        $resolved = 0;
        $unresolved = [];
        foreach (['fiscal_series', 'agt_connection_checks', 'agt_submissions', 'fiscal_documents'] as $table) {
            foreach (DB::table($table)->orderBy('id')->lazyById(500) as $row) {
                $candidates = isset($row->environment) && in_array($row->environment, ['homologation', 'production'], true) ? [$row->environment] : [];
                if ($row->agt_connection_id !== null) {
                    $candidates[] = $this->connection($row, (int) $row->agt_connection_id);
                }
                if ($table === 'fiscal_documents') {
                    if ($row->fiscal_series_id !== null) {
                        $series = DB::table('fiscal_series')->where('id', $row->fiscal_series_id)->first();
                        if ($series === null || $series->workspace_id !== $row->workspace_id || $series->legal_entity_id !== $row->legal_entity_id) {
                            throw new RuntimeException("Invalid fiscal series link for document {$row->id}");
                        }
                        $candidates[] = $this->connection($row, (int) $series->agt_connection_id);
                    }
                    foreach (DB::table('agt_submissions')->where('fiscal_document_id', $row->id)->get() as $submission) {
                        $candidates[] = $this->connection($row, (int) $submission->agt_connection_id);
                    }
                }
                $candidates = array_values(array_unique($candidates));
                if (count($candidates) > 1) {
                    throw new RuntimeException("Conflicting environment evidence for {$table}:{$row->id}");
                }
                $environment = $candidates[0] ?? 'unresolved';
                if ($environment === 'unresolved') {
                    $unresolved[] = (int) $row->id;
                } else {
                    $resolved++;
                }
                if ($apply) {
                    DB::table($table)->where('id', $row->id)->update(['environment' => $environment]);
                }
            }
        }

        return compact('resolved', 'unresolved');
    }

    private function connection(stdClass $row, int $id): string
    {
        $connection = DB::table('agt_connections')->where('id', $id)->first();
        if ($connection === null || $connection->workspace_id !== $row->workspace_id
            || $connection->legal_entity_id !== $row->legal_entity_id
            || ! in_array($connection->environment, ['homologation', 'production'], true)) {
            throw new RuntimeException("Invalid environment evidence at connection {$id}");
        }

        return $connection->environment;
    }
}
