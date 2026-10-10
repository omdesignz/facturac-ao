<?php

namespace App\Fiscal;

use App\Analytics\BillingSummaryQuery;
use App\Fiscal\Documents\QualifiedAgtStatusRead;

/** Only these five explicit calls can be reached by a validated plan. */
final readonly class AssistantTools
{
    public function __construct(private CustomerCapabilities $customers, private DocumentCapabilities $documents, private AnalyticsCapabilities $analytics) {}

    /** @param array{tool: string, arguments: array<string, string>} $call
     * @return array<string, mixed>
     */
    public function read(ExecutionContext $context, array $call): array
    {
        $data = match ($call['tool']) {
            'searchCustomers' => $this->customers->searchCustomers($context, CustomerSearchCommand::fromInput($call['arguments']['q'], $call['arguments']['status'])),
            'getCustomer' => $this->customers->readCustomer($context, CustomerReadCommand::fromPublicId($call['arguments']['public_id'])),
            'getFiscalDocumentSummary' => $this->documentSummary($context, $call['arguments']['public_id']),
            'getQualifiedAgtStatus' => $this->documents->readQualifiedAgtStatus($context, DocumentReadCommand::fromPublicId($call['arguments']['public_id'])),
            'getMonthlyRecordedBilling' => $this->analytics->billingSummary($context, BillingSummaryCommand::fromMonth($call['arguments']['month'])),
            default => abort(503),
        };
        self::validate($call['tool'], $data);

        return $data;
    }

    /** @return array<string, mixed> */
    private function documentSummary(ExecutionContext $context, string $publicId): array
    {
        $source = $this->documents->readDocument($context, DocumentReadCommand::fromPublicId($publicId));
        $data = ['public_id' => $source['public_id'], 'document_no' => $source['document_no'],
            'document_type' => $source['document_type'], 'environment' => $source['environment'],
            'revision' => $source['revision'], 'workflow_state' => $source['issue_status']];

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function validate(string $tool, array $data): void
    {
        match ($tool) {
            'searchCustomers' => self::search($data),
            'getCustomer' => self::customer($data),
            'getFiscalDocumentSummary' => self::document($data),
            'getQualifiedAgtStatus' => self::qualified($data),
            'getMonthlyRecordedBilling' => self::billing($data),
            default => abort(503),
        };
        json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $data */
    private static function customer(array $data): void
    {
        AssistantJson::keys($data, ['public_id', 'name', 'country_code', 'is_active'], 503);
        self::publicId($data['public_id']);
        self::text($data['name'], 255);
        abort_unless(is_string($data['country_code']) && preg_match('/\A[A-Z]{2}\z/', $data['country_code']) === 1 && is_bool($data['is_active']), 503);
    }

    /** @param array<string, mixed> $data */
    private static function search(array $data): void
    {
        AssistantJson::keys($data, ['items', 'has_more'], 503);
        abort_unless(is_array($data['items']) && array_is_list($data['items']) && count($data['items']) <= 10 && is_bool($data['has_more']), 503);
        foreach ($data['items'] as $item) {
            abort_unless(is_array($item), 503);
            self::customer($item);
        }
    }

    /** @param array<string, mixed> $data */
    private static function document(array $data): void
    {
        AssistantJson::keys($data, ['public_id', 'document_no', 'document_type', 'environment', 'revision', 'workflow_state'], 503);
        self::publicId($data['public_id']);
        if ($data['document_no'] !== null) {
            self::text($data['document_no'], 255);
        }
        abort_unless(in_array($data['document_type'], ['FA', 'FT', 'FR', 'FG', 'GF', 'AC', 'AR', 'TV', 'RC', 'RG', 'RE', 'ND', 'NC', 'AF', 'RP', 'RA', 'CS', 'LD'], true)
            && $data['environment'] === 'production' && is_int($data['revision']) && $data['revision'] >= 0
            && in_array($data['workflow_state'], ['draft', 'issued', 'received', 'processing', 'valid', 'invalid', 'contingency'], true), 503);
    }

    /** @param array<string, mixed> $data */
    private static function qualified(array $data): void
    {
        AssistantJson::keys($data, QualifiedAgtStatusRead::FIELDS, 503);
        self::publicId($data['document_public_id']);
        $enums = ['knowledge' => ['not_applicable', 'known', 'unknown', 'insufficient_evidence', 'conflicting_evidence'],
            'reported_state' => [null, 'valid', 'invalid', 'processing', 'processing_cancelled'], 'synchronization' => ['not_applicable', 'idle', 'pending', 'failed', 'unknown'],
            'freshness' => ['not_applicable', 'recent_observation', 'stale', 'unverified'], 'provenance' => ['not_applicable', 'agt_observation', 'workflow_only', 'unverified'],
            'explanation_code' => QualifiedAgtStatusRead::EXPLANATIONS];
        foreach ($enums as $key => $values) {
            abort_unless(in_array($data[$key], $values, true), 503);
        }
        self::timestamp($data['as_of']);
        foreach (['observed_at', 'last_successful_sync_at'] as $key) {
            if ($data[$key] !== null) {
                self::timestamp($data[$key]);
                abort_if($data[$key] > $data['as_of'], 503);
            }
        }
        abort_unless($data['effective_at'] === null && is_bool($data['reconciliation_required']), 503);
        if ($data['knowledge'] === 'known') {
            abort_unless($data['reported_state'] !== null && $data['observed_at'] !== null && $data['provenance'] === 'agt_observation'
                && ! $data['reconciliation_required'] && in_array($data['freshness'], ['recent_observation', 'stale'], true), 503);
        } else {
            abort_unless($data['reported_state'] === null && $data['observed_at'] === null, 503);
        }
        abort_if(in_array($data['reported_state'], ['valid', 'invalid', 'processing_cancelled'], true) && $data['synchronization'] !== 'idle', 503);
    }

    /** @param array<string, mixed> $data */
    private static function billing(array $data): void
    {
        AssistantJson::keys($data, BillingSummaryQuery::FIELDS, 503);
        abort_unless($data['metric_version'] === 'recorded-billing-v1' && $data['date_basis'] === 'document_date' && $data['timezone'] === 'Africa/Luanda'
            && is_array($data['period']) && is_array($data['currencies']) && array_is_list($data['currencies']) && count($data['currencies']) === 8, 503);
        self::timestamp($data['as_of']);
        AssistantJson::keys($data['period'], ['month', 'from', 'to_exclusive'], 503);
        abort_unless(is_string($data['period']['month']), 503);
        $command = BillingSummaryCommand::fromMonth($data['period']['month']);
        abort_unless($data['period']['from'] === $command->from && $data['period']['to_exclusive'] === $command->toExclusive, 503);
        foreach ($data['currencies'] as $index => $bucket) {
            abort_unless(is_array($bucket), 503);
            AssistantJson::keys($bucket, ['currency_code', 'minor_unit_scale', ...BillingSummaryQuery::METRICS], 503);
            abort_unless($bucket['currency_code'] === BillingSummaryQuery::CURRENCIES[$index] && $bucket['minor_unit_scale'] === 2, 503);
            foreach (BillingSummaryQuery::METRICS as $metric) {
                $value = $bucket[$metric];
                if (str_ends_with($metric, '_count')) {
                    abort_unless(is_int($value) && $value >= 0 && $value <= 100000, 503);
                } else {
                    abort_unless(is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]{0,18})\z/', $value) === 1, 503);
                    $magnitude = ltrim($value, '-');
                    abort_if(strlen($magnitude) === 19 && strcmp($magnitude, '9223372036854775807') > 0, 503);
                    abort_if(! str_starts_with($metric, 'after_credits_') && str_starts_with($value, '-'), 503);
                }
            }
        }
    }

    private static function publicId(mixed $value): void
    {
        abort_unless(AssistantJson::publicId($value, 503) === $value, 503);
    }

    private static function text(mixed $value, int $maximum): void
    {
        abort_unless(is_string($value) && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) >= 1 && mb_strlen($value) <= $maximum, 503);
    }

    private static function timestamp(mixed $value): void
    {
        abort_unless(is_string($value) && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\z/', $value) === 1, 503);
    }
}
