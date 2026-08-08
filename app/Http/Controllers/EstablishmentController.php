<?php

namespace App\Http\Controllers;

use App\Exceptions\BillingActionRefused;
use App\Http\Requests\StoreEstablishmentRequest;
use App\Models\Customer;
use App\Models\Establishment;
use App\Models\LegalEntity;
use App\Models\Workspace;
use App\Province;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The places a company trades from.
 *
 * Onboarding raises the head office; everything after it — a second shop, a
 * warehouse, a stand at a fair — belongs here. Each one carries its own AGT
 * series and its own stock, which is why a document has to say where it was
 * issued and not only who issued it.
 */
class EstablishmentController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', Customer::class);

        $establishments = $legalEntity->establishments()
            ->withCount(['fiscalSeries', 'fiscalDocuments', 'stockLevels'])
            ->orderByDesc('is_head_office')
            ->orderBy('name')
            ->get();

        return Inertia::render('Establishments/Index', [
            'establishments' => array_values($establishments
                ->map(fn (Establishment $establishment): array => [
                    'public_id' => $establishment->public_id,
                    'code' => $establishment->code,
                    'name' => $establishment->name,
                    'address_line' => $establishment->address_line,
                    'municipality' => $establishment->municipality ?? '',
                    'province_code' => $establishment->province_code,
                    'province_label' => Province::tryFrom($establishment->province_code)?->label()
                        ?? $establishment->province_code,
                    'is_head_office' => $establishment->is_head_office,
                    'is_active' => $establishment->is_active,
                    'series_count' => $establishment->fiscal_series_count,
                    'document_count' => $establishment->fiscal_documents_count,
                    'stock_count' => $establishment->stock_levels_count,
                    // Nothing points here yet, so removing it loses nothing.
                    'can_delete' => ! $establishment->is_head_office
                        && ! $establishment->isReferenced(),
                ])->all()),
            'provinces' => Province::options(),
            'canManage' => Gate::allows('create', Customer::class),
        ]);
    }

    public function store(StoreEstablishmentRequest $request): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('create', Customer::class);

        $establishment = new Establishment;
        $this->fill($establishment, $legalEntity, $request);
        $establishment->is_head_office = false;
        $establishment->save();

        if ((bool) $request->validated('is_head_office')) {
            $establishment->makeHeadOffice();
        }

        return back()->with(
            'success',
            "Estabelecimento “{$establishment->name}” criado. Abra uma série da AGT para começar a emitir daqui.",
        );
    }

    public function update(
        StoreEstablishmentRequest $request,
        Establishment $establishment,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($establishment->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        try {
            $this->guardHeadOffice($establishment, $request);
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $wasHeadOffice = $establishment->is_head_office;

        $this->fill($establishment, $legalEntity, $request);
        $establishment->save();

        if ((bool) $request->validated('is_head_office') && ! $wasHeadOffice) {
            $establishment->makeHeadOffice();
        }

        return back()->with('success', 'Estabelecimento actualizado.');
    }

    /**
     * Removes a place, or stands it down when documents already point at it.
     *
     * A place that has issued anything is never deleted: the documents have to
     * keep resolving where they came from, and the AGT has already been told.
     */
    public function destroy(Request $request, Establishment $establishment): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($establishment->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        if ($establishment->is_head_office) {
            return back()->with(
                'error',
                'A sede não pode ser removida. Designe outro estabelecimento como sede primeiro.',
            );
        }

        if ($establishment->isReferenced()) {
            $establishment->forceFill(['is_active' => false])->save();

            return back()->with(
                'success',
                'Estabelecimento desactivado. Deixa de aparecer ao emitir; os documentos já emitidos continuam a apontar para ele.',
            );
        }

        $establishment->delete();

        return back()->with('success', 'Estabelecimento removido.');
    }

    /**
     * Stops the head office being switched off from under the company.
     *
     * Every document names somewhere it was issued, and the AGT payload needs
     * an address for the taxpayer — there has to be one at all times.
     */
    private function guardHeadOffice(
        Establishment $establishment,
        StoreEstablishmentRequest $request,
    ): void {
        if (! $establishment->is_head_office) {
            return;
        }

        if (! (bool) $request->validated('is_active')) {
            throw BillingActionRefused::because(
                'A sede não pode ser desactivada. Designe outra sede primeiro.',
            );
        }

        if (! (bool) $request->validated('is_head_office')) {
            throw BillingActionRefused::because(
                'Uma empresa precisa de uma sede. Marque outro estabelecimento como sede em vez de desmarcar este.',
            );
        }
    }

    private function fill(
        Establishment $establishment,
        LegalEntity $legalEntity,
        StoreEstablishmentRequest $request,
    ): void {
        $municipality = $request->validated('municipality');

        $establishment->fill([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'code' => (string) $request->validated('code'),
            'name' => (string) $request->validated('name'),
            'address_line' => (string) $request->validated('address_line'),
            'municipality' => $municipality === '' ? null : $municipality,
            'province_code' => (string) $request->validated('province_code'),
            'timezone' => $establishment->timezone ?? 'Africa/Luanda',
            'is_active' => (bool) $request->validated('is_active'),
        ]);
    }

    private function legalEntity(Request $request): ?LegalEntity
    {
        $workspace = $request->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }
}
