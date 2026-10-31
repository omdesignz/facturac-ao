<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Analytics\AgingQuery;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MobileCollectionsController
{
    public function __construct(private readonly AgingQuery $aging) {}

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $rows = $this->aging->byCustomer($legalEntity);

        return response()->json(['data' => [
            'currency_code' => $legalEntity->currency_code,
            'outstanding_minor' => array_sum(array_column($rows, 'outstanding_minor')),
            'overdue_minor' => array_sum(array_column($rows, 'overdue_minor')),
            'customers' => array_map(fn (array $row): array => [
                'customer_public_id' => $row['customer_public_id'],
                'name' => $row['name'],
                'outstanding_minor' => $row['outstanding_minor'],
                'overdue_minor' => $row['overdue_minor'],
                'document_count' => $row['document_count'],
                'oldest_days_past_due' => $row['oldest_days_past_due'],
                'over_limit' => $row['over_limit'],
                'last_opened_by_customer_at' => $row['last_opened_by_customer_at'],
            ], $rows),
        ]]);
    }
}
