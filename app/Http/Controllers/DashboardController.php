<?php

namespace App\Http\Controllers;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Analytics\DashboardQuery;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private DashboardQuery $dashboard) {}

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace->legalEntities()->oldest('id')->first();
        $companyReadiness = $legalEntity === null
            ? [
                'configured' => false,
                'status' => 'draft',
                'status_label' => 'Por configurar',
            ]
            : [
                'configured' => $legalEntity->onboarding_completed_at !== null,
                'status' => $legalEntity->status->value,
                'status_label' => $legalEntity->status->label(),
            ];
        $agtConnection = $legalEntity?->agtConnections()
            ->where('environment', AgtEnvironment::Homologation)
            ->first();

        $now = CarbonImmutable::now();
        $kpis = $legalEntity instanceof LegalEntity
            ? $this->dashboard->kpis($legalEntity, $now)
            : null;

        return Inertia::render('Dashboard', [
            'companyReadiness' => $companyReadiness,
            'agtReadiness' => [
                'configured' => $agtConnection !== null,
                'verified' => $agtConnection?->status === AgtConnectionStatus::Verified,
                'status' => $agtConnection?->status->value ?? 'draft',
                'status_label' => $agtConnection?->status->label() ?? 'Por configurar',
            ],
            // The server's clock, in the application's timezone, so the
            // greeting and "today" agree with the figures beside them even when
            // the browser is somewhere else.
            'now' => $now->toIso8601String(),
            'currencyCode' => $legalEntity->currency_code ?? 'AOA',
            'kpis' => $kpis,
            'focus' => $legalEntity instanceof LegalEntity
                ? $this->dashboard->focus($legalEntity, $now)
                : null,
            'review' => $legalEntity instanceof LegalEntity && $kpis !== null
                ? $this->dashboard->review($legalEntity, $kpis['overdue_count'])
                : null,
            // Ageing walks every open invoice, so it arrives after first paint.
            'collections' => Inertia::defer(fn (): ?array => $legalEntity instanceof LegalEntity
                ? $this->dashboard->collections($legalEntity)
                : null),
        ]);
    }
}
