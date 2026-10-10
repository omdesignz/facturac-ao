<?php

namespace App\Fiscal;

/** CM1 storage mapping. This is not connected to assistant admission. */
final class TenantAiCapabilities
{
    public const COLUMNS = ['searchCustomers' => 'customer_search_enabled', 'getCustomer' => 'customer_detail_enabled',
        'getFiscalDocumentSummary' => 'documents_enabled', 'getQualifiedAgtStatus' => 'agt_enabled', 'getMonthlyRecordedBilling' => 'billing_enabled'];

    public static function column(string $tool): ?string
    {
        return self::COLUMNS[$tool] ?? null;
    }
}
