<?php

namespace App\Http\Controllers;

use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
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

        return Inertia::render('Dashboard', [
            'companyReadiness' => $companyReadiness,
            'agtReadiness' => [
                'configured' => $agtConnection !== null,
                'verified' => $agtConnection?->status === AgtConnectionStatus::Verified,
                'status' => $agtConnection?->status->value ?? 'draft',
                'status_label' => $agtConnection?->status->label() ?? 'Por configurar',
            ],
        ]);
    }
}
