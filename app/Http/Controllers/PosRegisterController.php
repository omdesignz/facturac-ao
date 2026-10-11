<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Http\Requests\StorePosRegisterRequest;
use App\Models\Establishment;
use App\Models\PosRegister;
use App\Models\PosSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tills a company has, and where each one stands.
 *
 * A register that has ever run a shift is never deleted: the shifts, and the
 * documents behind them, keep pointing at it. It is switched off instead.
 */
class PosRegisterController extends Controller
{
    use ResolvesPosCompany;

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if ($legalEntity === null) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', PosRegister::class);

        $registers = $legalEntity->posRegisters()
            ->with(['establishment', 'openSession.openedBy'])
            ->withCount('sessions')
            ->orderBy('name')
            ->get();

        return Inertia::render('Pos/Registers/Index', [
            'registers' => array_values($registers->map(fn (PosRegister $register): array => [
                'public_id' => $register->public_id,
                'name' => $register->name,
                'is_active' => $register->is_active,
                'establishment' => [
                    'public_id' => $register->establishment->public_id,
                    'name' => $register->establishment->name,
                ],
                'sessions_count' => $register->sessions_count,
                'open_session' => $register->openSession instanceof PosSession
                    ? [
                        'public_id' => $register->openSession->public_id,
                        'opened_by_name' => $register->openSession->openedBy->name,
                        'opened_at' => $register->openSession->opened_at->toIso8601String(),
                        'is_mine' => $register->openSession->opened_by_user_id === $request->user()?->id,
                    ]
                    : null,
            ])->all()),
            'establishments' => array_values($legalEntity->establishments()
                ->where('is_active', true)
                ->orderByDesc('is_head_office')
                ->orderBy('name')
                ->get()
                ->map(fn (Establishment $establishment): array => [
                    'public_id' => $establishment->public_id,
                    'name' => $establishment->name,
                ])->all()),
            'canManage' => Gate::allows('create', PosRegister::class),
        ]);
    }

    public function store(StorePosRegisterRequest $request): RedirectResponse
    {
        $legalEntity = $this->legalEntityOrFail($request);

        $register = new PosRegister;
        $this->fill($register, $legalEntity->workspace_id, $legalEntity->id, $request);
        $register->save();

        return back()->with('success', "Caixa «{$register->name}» criada.");
    }

    public function update(StorePosRegisterRequest $request, PosRegister $posRegister): RedirectResponse
    {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posRegister->legal_entity_id === $legalEntity->id, 404);

        $this->fill($posRegister, $legalEntity->workspace_id, $legalEntity->id, $request);
        $posRegister->save();

        return back()->with('success', 'Caixa actualizada.');
    }

    public function destroy(Request $request, PosRegister $posRegister): RedirectResponse
    {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posRegister->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('delete', $posRegister);

        if ($posRegister->sessions()->exists()) {
            return back()->with('error', 'Esta caixa já teve turnos. Desactive-a em vez de a apagar.');
        }

        $posRegister->delete();

        return back()->with('success', 'Caixa removida.');
    }

    private function fill(
        PosRegister $register,
        int $workspaceId,
        int $legalEntityId,
        StorePosRegisterRequest $request,
    ): void {
        $establishment = Establishment::query()
            ->where('workspace_id', $workspaceId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('public_id', (string) $request->validated('establishment_public_id'))
            ->firstOrFail();

        $register->fill([
            'workspace_id' => $workspaceId,
            'legal_entity_id' => $legalEntityId,
            'establishment_id' => $establishment->id,
            'name' => (string) $request->validated('name'),
            'is_active' => (bool) $request->validated('is_active'),
        ]);
    }
}
