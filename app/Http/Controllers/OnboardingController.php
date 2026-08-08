<?php

namespace App\Http\Controllers;

use App\Actions\SaveCompanyProfile;
use App\AgtConnectionStatus;
use App\AgtEnvironment;
use App\Http\Requests\UpdateOnboardingRequest;
use App\Models\Workspace;
use App\Province;
use App\TaxRegime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class OnboardingController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        Gate::authorize('view', $workspace);

        $legalEntity = $workspace->legalEntities()
            ->with(['establishments' => fn ($query) => $query->orderByDesc('is_head_office')->oldest('id')])
            ->oldest('id')
            ->first();
        $establishment = $legalEntity?->establishments->firstWhere('is_head_office', true)
            ?? $legalEntity?->establishments->first();

        $company = [
            'legal_name' => '',
            'trade_name' => '',
            'tax_identification_number' => '',
            'tax_regime' => TaxRegime::General->value,
            'main_cae_code' => '',
            'status' => 'draft',
            'status_label' => 'Rascunho',
            'establishment_code' => 'SEDE',
            'establishment_name' => 'Sede',
            'address_line' => '',
            'municipality' => '',
            'province_code' => Province::Luanda->value,
            'province_label' => Province::Luanda->label(),
            'has_logo' => false,
            'logo_updated_at' => null,
        ];

        if ($legalEntity !== null) {
            $company = [
                ...$company,
                'legal_name' => $legalEntity->legal_name,
                'trade_name' => $legalEntity->trade_name ?? '',
                'tax_identification_number' => $legalEntity->tax_identification_number ?? '',
                'tax_regime' => $legalEntity->tax_regime->value,
                'main_cae_code' => $legalEntity->main_cae_code ?? '',
                'status' => $legalEntity->status->value,
                'status_label' => $legalEntity->status->label(),
            ];
        }

        if ($establishment !== null) {
            $company = [
                ...$company,
                'establishment_code' => $establishment->code,
                'establishment_name' => $establishment->name,
                'address_line' => $establishment->address_line,
                'municipality' => $establishment->municipality ?? '',
                'province_code' => $establishment->province_code,
                'province_label' => Province::tryFrom($establishment->province_code)?->label()
                    ?? $establishment->province_code,
                'has_logo' => $legalEntity->logo_path !== null,
                // Part of the URL so the browser fetches the new mark rather
                // than the one it cached a moment ago.
                'logo_updated_at' => $legalEntity->updated_at?->timestamp,
            ];
        }
        $agtConnection = $legalEntity?->agtConnections()
            ->where('environment', AgtEnvironment::Homologation)
            ->first();

        return Inertia::render('Onboarding', [
            'company' => $company,
            'taxRegimes' => array_map(
                fn (TaxRegime $taxRegime): array => [
                    'value' => $taxRegime->value,
                    'label' => $taxRegime->label(),
                ],
                TaxRegime::cases(),
            ),
            'provinces' => Province::options(),
            'canUpdate' => $legalEntity === null
                ? Gate::allows('update', $workspace)
                : Gate::allows('update', $legalEntity),
            'agtConnection' => [
                'configured' => $agtConnection !== null,
                'verified' => $agtConnection?->status === AgtConnectionStatus::Verified,
                'status_label' => $agtConnection?->status->label() ?? 'Não configurada',
            ],
        ]);
    }

    public function update(
        UpdateOnboardingRequest $request,
        SaveCompanyProfile $saveCompanyProfile,
    ): RedirectResponse {
        /** @var Workspace $workspace */
        $workspace = $request->attributes->get('currentWorkspace');
        $saveCompanyProfile->execute($workspace, $request->companyProfile());

        return redirect()
            ->route('onboarding')
            ->with('success', 'Dados da empresa guardados com segurança.');
    }
}
