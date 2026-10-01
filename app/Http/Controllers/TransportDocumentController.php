<?php

namespace App\Http\Controllers;

use App\Actions\SaveTransportDocumentDraft;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Documents\TransportDocumentPresenter;
use App\Fiscal\Documents\TransportDocumentRegister;
use App\Http\Requests\IndexTransportDocumentsRequest;
use App\Http\Requests\StoreTransportDocumentRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\TransportDocument;
use App\Models\Workspace;
use App\TransportDocumentType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TransportDocumentController extends Controller
{
    public function __construct(private TransportDocumentPresenter $presenter) {}

    public function index(
        IndexTransportDocumentsRequest $request,
        TransportDocumentRegister $register,
    ): Response|RedirectResponse {
        $legalEntity = $request->currentLegalEntity();

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Inertia::encryptHistory();

        return Inertia::render('TransportDocuments/Index', $register->for(
            $legalEntity,
            $request->filters(),
            $request->user(),
        ));
    }

    public function create(Request $request): Response|RedirectResponse
    {
        Gate::authorize('create', TransportDocument::class);

        return $this->form($request, null);
    }

    public function store(
        StoreTransportDocumentRequest $request,
        SaveTransportDocumentDraft $save,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        abort_unless($legalEntity instanceof LegalEntity, 404);

        try {
            $document = $save->execute($legalEntity, $request->user(), $request->profile());
        } catch (BillingActionRefused $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('transport-documents.edit', $document)
            ->with('success', 'Rascunho da guia guardado.');
    }

    public function edit(Request $request, TransportDocument $transportDocument): Response
    {
        $this->assertCurrentLegalEntity($request, $transportDocument);
        Gate::authorize('view', $transportDocument);

        return $this->form($request, $transportDocument);
    }

    public function update(
        StoreTransportDocumentRequest $request,
        TransportDocument $transportDocument,
        SaveTransportDocumentDraft $save,
    ): RedirectResponse {
        $legalEntity = $request->currentLegalEntity();
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($transportDocument->legal_entity_id === $legalEntity->id, 404);

        try {
            $save->execute($legalEntity, $request->user(), $request->profile(), $transportDocument);
        } catch (BillingActionRefused $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Guia actualizada.');
    }

    private function form(
        Request $request,
        ?TransportDocument $transportDocument,
    ): Response|RedirectResponse {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        $customers = $legalEntity->customers()
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return Inertia::render('TransportDocuments/Form', [
            'transportDocument' => $transportDocument === null
                ? null
                : [
                    ...$this->presenter->forForm($transportDocument),
                    'is_editable' => $request->user()?->can('update', $transportDocument) === true,
                    'can_issue' => $request->user()?->can('issue', $transportDocument) === true,
                    'can_cancel' => $request->user()?->can('cancel', $transportDocument) === true,
                ],
            'types' => array_map(fn (TransportDocumentType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'short_label' => $type->shortLabel(),
            ], TransportDocumentType::cases()),
            'establishments' => array_values($legalEntity->establishments()
                ->where('is_active', true)
                ->orderByDesc('is_head_office')
                ->orderBy('name')
                ->get()
                ->map(fn (Establishment $establishment): array => [
                    'value' => $establishment->public_id,
                    'label' => $establishment->name,
                    'code' => $establishment->code,
                    'address' => $establishment->address_line,
                    'city' => $establishment->municipality ?? '',
                    'province' => $establishment->province_code,
                    'country_code' => 'AO',
                ])->all()),
            'customers' => array_values($customers->map(fn (Customer $customer): array => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
                'country_code' => $customer->country_code,
                'address' => $customer->address_line ?? '',
                'city' => '',
                'province' => '',
            ])->all()),
            'catalogueItems' => array_values($legalEntity->catalogueItems()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (CatalogueItem $item): array => [
                    'public_id' => $item->public_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'description' => $item->description,
                    'unit_of_measure' => $item->unit_of_measure,
                    'unit_price' => number_format($item->unit_price_minor / 100, 2, '.', ''),
                    'tracks_stock' => $item->tracks_stock,
                ])->all()),
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }

    private function assertCurrentLegalEntity(
        Request $request,
        TransportDocument $transportDocument,
    ): void {
        $legalEntity = $this->legalEntity($request);
        abort_unless(
            $legalEntity instanceof LegalEntity
                && $transportDocument->legal_entity_id === $legalEntity->id,
            404,
        );
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}
