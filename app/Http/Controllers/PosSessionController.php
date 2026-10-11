<?php

namespace App\Http\Controllers;

use App\Actions\OpenPosSession;
use App\Exceptions\BillingActionRefused;
use App\Fiscal\Pos\PosPresenter;
use App\Fiscal\Pos\PosSessionSummary;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Http\Requests\StorePosSessionRequest;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\Models\User;
use App\PosSessionStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Shifts: opening one, and reading back what any of them did.
 */
class PosSessionController extends Controller
{
    use ResolvesPosCompany;

    public function __construct(
        private PosSessionSummary $summary,
        private PosPresenter $presenter,
    ) {}

    /**
     * Opening a shift is the moment of password confirmation. It is the
     * standing authority to sell, which is why selling itself does not ask again.
     */
    public function store(StorePosSessionRequest $request, OpenPosSession $openSession): RedirectResponse
    {
        $legalEntity = $this->legalEntityOrFail($request);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $register = PosRegister::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('public_id', (string) $request->validated('pos_register_public_id'))
            ->firstOrFail();

        try {
            $session = $openSession->execute($register, $user, $request->openingFloatMinor());
        } catch (BillingActionRefused $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('pos.show')
            ->with('success', "Turno aberto na caixa «{$session->register->name}».");
    }

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', PosSession::class);

        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $sessions = PosSession::query()
            ->with(['register', 'establishment', 'openedBy'])
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->when(
                ! Gate::allows('create', PosRegister::class),
                fn ($query) => $query->where('opened_by_user_id', $user->id),
            )
            ->latest('opened_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $totals = DB::table('pos_sales')
            ->join('fiscal_documents', 'fiscal_documents.id', '=', 'pos_sales.fiscal_document_id')
            ->whereIn('pos_sales.pos_session_id', $sessions->getCollection()->pluck('id')->all())
            ->groupBy('pos_sales.pos_session_id')
            ->get([
                'pos_sales.pos_session_id',
                DB::raw('count(*) as sales_count'),
                DB::raw('coalesce(sum(fiscal_documents.gross_total_minor), 0) as gross_minor'),
            ])
            ->keyBy('pos_session_id');

        return Inertia::render('Pos/Sessions/Index', [
            'sessions' => [
                'data' => array_values($sessions->getCollection()->map(fn (PosSession $session): array => [
                    'public_id' => $session->public_id,
                    'register_name' => $session->register->name,
                    'establishment_name' => $session->establishment->name,
                    'opened_by_name' => $session->openedBy->name,
                    'opened_at' => $session->opened_at->toIso8601String(),
                    'closed_at' => $session->closed_at?->toIso8601String(),
                    'status' => $session->status->value,
                    'status_label' => $session->status->label(),
                    'sales_count' => (int) ($totals->get($session->id)->sales_count ?? 0),
                    'gross_total_minor' => (int) ($totals->get($session->id)->gross_minor ?? 0),
                    'cash_difference_minor' => $session->cash_difference_minor,
                ])->all()),
                'links' => $sessions->linkCollection()->all(),
                'total' => $sessions->total(),
                'from' => $sessions->firstItem(),
                'to' => $sessions->lastItem(),
            ],
            'currencyCode' => $legalEntity->currency_code,
            'canManage' => Gate::allows('create', PosRegister::class),
        ]);
    }

    public function show(Request $request, PosSession $posSession): Response
    {
        $legalEntity = $this->legalEntityOrFail($request);
        abort_unless($posSession->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('view', $posSession);
        Inertia::encryptHistory();

        $posSession->load(['register', 'establishment', 'openedBy', 'closedBy']);

        // A closed shift reads what was frozen when it closed; an open one is live.
        $summary = $posSession->status === PosSessionStatus::Closed && is_array($posSession->closing_summary)
            ? $posSession->closing_summary
            : $this->summary->forSession($posSession);

        $sales = $posSession->sales()
            ->with('fiscalDocument')
            ->latest('id')
            ->limit(500)
            ->get();

        return Inertia::render('Pos/Sessions/Show', [
            'session' => [
                'public_id' => $posSession->public_id,
                'status' => $posSession->status->value,
                'status_label' => $posSession->status->label(),
                'register_name' => $posSession->register->name,
                'establishment_name' => $posSession->establishment->name,
                'opened_by_name' => $posSession->openedBy->name,
                'closed_by_name' => $posSession->closedBy?->name,
                'opened_at' => $posSession->opened_at->toIso8601String(),
                'closed_at' => $posSession->closed_at?->toIso8601String(),
                'currency_code' => $posSession->currency_code,
                'opening_float_minor' => $posSession->opening_float_minor,
                'counted_cash_minor' => $posSession->counted_cash_minor,
                'expected_cash_minor' => $posSession->isOpen()
                    ? $summary['expected_cash_minor']
                    : $posSession->expected_cash_minor,
                'cash_difference_minor' => $posSession->cash_difference_minor,
                'closing_notes' => $posSession->closing_notes,
                'can_close' => $posSession->isOpen() && Gate::allows('close', $posSession),
            ],
            'summary' => $summary,
            'cash_movements' => array_values($posSession->cashMovements()
                ->latest('id')
                ->get()
                ->map(fn ($movement): array => $this->presenter->cashMovement($movement))
                ->all()),
            'sales' => $this->presenter->sales($sales),
        ]);
    }
}
