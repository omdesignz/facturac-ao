<?php

namespace App\Analytics;

use App\Fiscal\BillingSummaryCommand;
use App\Fiscal\DocumentReadContext;
use App\Fiscal\SensitiveReadQuery;
use Illuminate\Support\Facades\DB;

class BillingSummaryQuery
{
    public const CURRENCIES = ['AOA', 'BRL', 'CNY', 'EUR', 'GBP', 'NAD', 'USD', 'ZAR'];

    public const METRIC_VERSION = 'recorded-billing-v1';

    public const FIELDS = ['metric_version', 'date_basis', 'timezone', 'as_of', 'period', 'currencies'];

    public const METRICS = ['billed_document_count', 'credit_note_count', 'invoiced_gross_minor', 'invoiced_net_minor', 'invoiced_tax_minor', 'credit_gross_minor', 'credit_net_minor', 'credit_tax_minor', 'after_credits_gross_minor', 'after_credits_net_minor', 'after_credits_tax_minor'];

    /** @return array{sql: string, bindings: list<int|string>} */
    public function statement(DocumentReadContext $context, BillingSummaryCommand $command): array
    {
        $types = BillingDefinition::billableTypes();
        sort($types);
        if ($types !== ['FR', 'FT', 'GF', 'ND']) {
            throw new \RuntimeException('Unsupported billing definition.');
        }
        $clock = match (DB::getDriverName()) {
            'pgsql' => "to_char(statement_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS') || '+00:00'",
            'sqlite' => "strftime('%Y-%m-%dT%H:%M:%S', 'now') || '+00:00'",
            default => throw new \RuntimeException('Unsupported billing database.'),
        };
        $select = [];
        $bindings = [$context->workspaceId(), $context->legalEntityId(), $context->environment()->value, $command->from, $command->toExclusive];
        $keyPredicates = 'workspace_id = ? AND legal_entity_id = ? AND environment = ?
            AND document_date >= ? AND document_date < ?';
        $candidates = 'SELECT id, document_date FROM fiscal_documents WHERE '.$keyPredicates.'
            ORDER BY document_date, id LIMIT 100001';
        if (DB::getDriverName() === 'pgsql') {
            $candidates = 'WITH first_keys AS MATERIALIZED (
                SELECT id, document_date FROM fiscal_documents WHERE '.$keyPredicates.'
                ORDER BY document_date, id LIMIT 50000
            ), boundary AS (
                SELECT id, document_date FROM first_keys ORDER BY document_date DESC, id DESC LIMIT 1
            ) SELECT id, document_date FROM first_keys
            UNION ALL (
                SELECT id, document_date FROM fiscal_documents WHERE '.$keyPredicates.'
                    AND (document_date, id) > (SELECT document_date, id FROM boundary)
                ORDER BY document_date, id LIMIT 50001
            ) ORDER BY document_date, id LIMIT 100001';
        }
        foreach (['invoiced' => $types, 'credit' => ['NC']] as $family => $codes) {
            foreach (['gross' => 'gross_total_minor', 'net' => 'net_total_minor', 'tax' => 'tax_payable_minor'] as $name => $column) {
                $expression = BillingDefinition::sum($column, $codes);
                $select[] = $expression['sql']." AS {$family}_{$name}_minor";
                array_push($bindings, ...$expression['bindings']);
            }
        }
        $payload = match (DB::getDriverName()) {
            'pgsql' => 'LEFT JOIN LATERAL (
                SELECT document_type, status, document_no, issued_at, frozen_at, currency_code,
                    gross_total_minor, net_total_minor, tax_payable_minor
                FROM fiscal_documents WHERE id = c.id
                    AND workspace_id = ? AND legal_entity_id = ? AND environment = ? LIMIT 1
            ) p ON TRUE',
            default => 'LEFT JOIN fiscal_documents p ON p.id = c.id
                AND p.workspace_id = ? AND p.legal_entity_id = ? AND p.environment = ?',
        };
        $sql = "WITH candidates AS (
            $candidates
        ), checked AS (
            SELECT CASE WHEN p.status <> 'draft' THEN p.document_type ELSE NULL END AS document_type,
                CASE WHEN p.currency_code IN ('AOA','BRL','CNY','EUR','GBP','NAD','USD','ZAR')
                    THEN p.currency_code ELSE NULL END AS currency_code,
                p.gross_total_minor, p.net_total_minor, p.tax_payable_minor,
                CASE WHEN p.status = 'draft' THEN
                    CASE WHEN p.document_no IS NOT NULL OR p.issued_at IS NOT NULL OR p.frozen_at IS NOT NULL THEN 1 ELSE 0 END
                WHEN p.status IS NULL OR p.status NOT IN ('issued','received','processing','valid','invalid','contingency')
                    OR p.document_type IS NULL OR p.document_type NOT IN ('FT','FR','GF','ND','NC','RG')
                    OR p.currency_code IS NULL OR p.currency_code NOT IN ('AOA','BRL','CNY','EUR','GBP','NAD','USD','ZAR')
                    OR p.document_no IS NULL OR TRIM(p.document_no) = '' OR p.issued_at IS NULL OR p.frozen_at IS NULL
                    OR p.gross_total_minor IS NULL OR p.net_total_minor IS NULL OR p.tax_payable_minor IS NULL
                    OR p.gross_total_minor < 0 OR p.net_total_minor < 0 OR p.tax_payable_minor < 0
                    OR p.gross_total_minor - p.net_total_minor != p.tax_payable_minor THEN 1 ELSE 0 END AS invalid_row
            FROM candidates c $payload
        ), folded AS (
            SELECT d.currency_code, COUNT(*) AS candidate_count, MAX(d.invalid_row) AS invalid_count,
                SUM(CASE WHEN d.document_type IN ('FT','FR','GF','ND') THEN 1 ELSE 0 END) AS billed_document_count,
                SUM(CASE WHEN d.document_type = 'NC' THEN 1 ELSE 0 END) AS credit_note_count,
                ".implode(',', $select)."
            FROM checked d GROUP BY d.currency_code
        ) SELECT folded.currency_code, folded.billed_document_count, folded.credit_note_count,
            folded.invoiced_gross_minor, folded.invoiced_net_minor, folded.invoiced_tax_minor,
            folded.credit_gross_minor, folded.credit_net_minor, folded.credit_tax_minor,
            COALESCE(SUM(folded.candidate_count) OVER (),0) AS candidate_count,
            COALESCE(MAX(folded.invalid_count) OVER (),0) AS invalid_count,
            e.timezone AS entity_timezone, $clock AS as_of
            FROM legal_entities e LEFT JOIN folded ON TRUE WHERE e.id = ? AND e.workspace_id = ?";
        $keyBindings = array_slice($bindings, 0, 5);
        if (DB::getDriverName() === 'pgsql') {
            array_push($keyBindings, ...$keyBindings);
        }
        $bindings = [...$keyBindings, $context->workspaceId(), $context->legalEntityId(),
            $context->environment()->value, ...array_slice($bindings, 5), $context->legalEntityId(), $context->workspaceId()];

        return compact('sql', 'bindings');
    }

    /** @return array<string, mixed> */
    public function read(DocumentReadContext $context, BillingSummaryCommand $command): array
    {
        $connection = DB::connection();
        abort_if($connection->transactionLevel() !== 0, 503);
        $statement = $this->statement($context, $command);
        $rows = SensitiveReadQuery::run(function () use ($connection, $statement): array {
            return $connection->transaction(function () use ($connection, $statement): array {
                if ($connection->getDriverName() === 'pgsql') {
                    $connection->statement('SET TRANSACTION READ ONLY');
                    $connection->statement("SET LOCAL statement_timeout = '2000ms'");
                    $connection->statement("SET LOCAL lock_timeout = '250ms'");
                }

                return $connection->select($statement['sql'], $statement['bindings'], useReadPdo: false);
            }, attempts: 1);
        });
        abort_if($rows === [], 503);
        $buckets = [];
        foreach (self::CURRENCIES as $currency) {
            $buckets[$currency] = ['currency_code' => $currency, 'minor_unit_scale' => 2, 'billed_document_count' => 0, 'credit_note_count' => 0];
            foreach (array_slice(self::METRICS, 2) as $field) {
                $buckets[$currency][$field] = '0';
            }
        }
        foreach ($rows as $row) {
            abort_if((int) $row->candidate_count > 100000 || (int) $row->invalid_count !== 0 || $row->entity_timezone !== 'Africa/Luanda', 503);
            if ($row->currency_code === null) {
                continue;
            }
            abort_unless(isset($buckets[$row->currency_code]), 503);
            $bucket = &$buckets[$row->currency_code];
            foreach (['billed_document_count', 'credit_note_count'] as $field) {
                $value = $this->minor($row->{$field});
                abort_if(strlen($value) > 6 || (int) $value > 100000, 503);
                $bucket[$field] = (int) $value;
            }
            foreach (['gross', 'net', 'tax'] as $name) {
                $invoice = $this->minor($row->{'invoiced_'.$name.'_minor'});
                $credit = $this->minor($row->{'credit_'.$name.'_minor'});
                $bucket['invoiced_'.$name.'_minor'] = $invoice;
                $bucket['credit_'.$name.'_minor'] = $credit;
                $bucket['after_credits_'.$name.'_minor'] = (string) ((int) $invoice - (int) $credit);
            }
            unset($bucket);
        }
        $asOf = (string) $rows[0]->as_of;
        abort_unless(preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $asOf) === 1, 503);
        $data = ['metric_version' => self::METRIC_VERSION, 'date_basis' => 'document_date', 'timezone' => 'Africa/Luanda', 'as_of' => $asOf,
            'period' => ['month' => $command->month, 'from' => $command->from, 'to_exclusive' => $command->toExclusive], 'currencies' => array_values($buckets)];
        json_encode($data, JSON_THROW_ON_ERROR);

        return $data;
    }

    private function minor(mixed $value): string
    {
        abort_unless(is_int($value) || is_string($value), 503);
        $value = (string) $value;
        abort_unless(preg_match('/\A(0|[1-9][0-9]*)\z/', $value) === 1 && (strlen($value) < 19 || (strlen($value) === 19 && strcmp($value, '9223372036854775807') <= 0)), 503);

        return $value;
    }
}
