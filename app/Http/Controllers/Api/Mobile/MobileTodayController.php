<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Analytics\DashboardQuery;
use App\Analytics\TodayDecisions;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MobileTodayController
{
    public function __construct(
        private readonly DashboardQuery $dashboard,
        private readonly TodayDecisions $decisions,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $request->attributes->get('currentLegalEntity');
        $now = CarbonImmutable::now();

        $kpis = $this->dashboard->kpis($legalEntity, $now);
        $collections = $this->dashboard->collections($legalEntity);
        $lowStock = Gate::allows('viewAny', CatalogueItem::class) ? $this->dashboard->lowStock($legalEntity) : null;

        return response()->json(['data' => [
            'now' => $now->toIso8601String(),
            'currency_code' => $legalEntity->currency_code,
            'receivables' => [
                'outstanding_minor' => $kpis['outstanding_minor'],
                'overdue_minor' => $kpis['overdue_minor'],
                'overdue_count' => $kpis['overdue_count'],
            ],
            'sales_today' => [
                'billed_minor' => $kpis['billed_today_minor'],
                'issued_count' => $kpis['issued_today'],
            ],
            'billing_trend' => $this->dashboard->billingTrend($legalEntity, $now),
            'debtors' => array_map(fn (array $customer): array => [
                'customer_public_id' => $customer['customer_public_id'],
                'name' => $customer['name'],
                'outstanding_minor' => $customer['outstanding_minor'],
            ], $collections['customers']),
            'decisions' => $this->decisions->for($this->dashboard->focus($legalEntity, $now), $collections, $lowStock),
        ]]);
    }
}
