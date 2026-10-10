<?php

namespace App\Analytics;

use App\FiscalDocumentType;

final class BillingDefinition
{
    /** @return list<string> */
    public static function billableTypes(): array
    {
        return array_values(array_map(
            fn (FiscalDocumentType $type): string => $type->value,
            array_filter(FiscalDocumentType::issuable(), fn (FiscalDocumentType $type): bool => $type->requiresLines() && ! $type->reducesReceivable()),
        ));
    }

    /** @param list<string> $types
     * @return array{sql: literal-string, bindings: list<string>}
     */
    public static function sum(string $column, array $types): array
    {
        $column = match ($column) {
            'gross_total_minor' => 'd.gross_total_minor',
            'net_total_minor' => 'd.net_total_minor',
            'tax_payable_minor' => 'd.tax_payable_minor',
            default => throw new \LogicException('Unsupported billing aggregate.'),
        };
        $placeholders = match (count($types)) {
            1 => '?',
            4 => '?,?,?,?',
            default => throw new \LogicException('Unsupported billing type set.'),
        };

        return ['sql' => 'COALESCE(SUM(CASE WHEN d.document_type IN ('.$placeholders.') THEN '.$column.' ELSE 0 END),0)', 'bindings' => $types];
    }
}
