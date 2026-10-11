<?php

namespace App\Http\Controllers;

use App\Actions\ResolveCustomerPrices;
use App\Fiscal\Pos\FinalConsumer;
use App\Fiscal\Pos\PosCatalogue;
use App\Fiscal\Pos\PosPaymentMethods;
use App\Fiscal\Pos\PosPresenter;
use App\Fiscal\Pos\PosSeriesResolver;
use App\Fiscal\Pos\PosSessionSummary;
use App\Http\Controllers\Concerns\ResolvesPosCompany;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\PosSession;
use App\PosSessionStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Ponto de venda: the till itself.
 *
 * One page carries everything a shift needs, so the cashier never waits on the
 * server between one customer and the next: the catalogue, the customers and
 * what each of them pays are sent once, and only the sale itself goes back.
 */
class PosController extends Controller
{
    use ResolvesPosCompany;

    public function __construct(
        private PosSessionSummary $summary,
        private PosCatalogue $catalogue,
        private PosPresenter $presenter,
        private PosSeriesResolver $series,
        private ResolveCustomerPrices $resolvePrices,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', PosSession::class);
        Inertia::encryptHistory();

        $viewer = $request->user();
        abort_unless($viewer !== null, 403);

        $session = PosSession::query()
            ->with(['register', 'establishment'])
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('opened_by_user_id', $viewer->id)
            ->where('status', PosSessionStatus::Open)
            ->first();

        $customers = $session instanceof PosSession
            ? $legalEntity->customers()->where('is_active', true)->orderBy('name')->limit(250)->get()
            : collect();

        return Inertia::render('Pos/Index', [
            'company' => [
                'legal_name' => $legalEntity->legal_name,
                'trade_name' => $legalEntity->trade_name,
                'currency_code' => $legalEntity->currency_code,
            ],
            'canManageRegisters' => Gate::allows('create', PosRegister::class),
            'canSell' => Gate::allows('create', FiscalDocument::class),
            'paymentMethods' => PosPaymentMethods::options(),
            'finalConsumer' => FinalConsumer::props(),
            'registers' => $this->registers($legalEntity, $viewer->id),
            'session' => $session instanceof PosSession ? $this->sessionProps($legalEntity, $session) : null,
            'catalogue' => $session instanceof PosSession ? $this->catalogue->forSession($session) : [],
            'customers' => array_values($customers->map(fn (Customer $customer): array => [
                'public_id' => $customer->public_id,
                'name' => $customer->name,
                'tax_identification_number' => $customer->tax_identification_number,
            ])->all()),
            'agreedPrices' => $this->agreedPrices($customers),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function registers(LegalEntity $legalEntity, int $viewerId): array
    {
        $registers = $legalEntity->posRegisters()
            ->with(['establishment', 'openSession.openedBy'])
            ->orderBy('name')
            ->get();

        /** @var array<int, bool> $hasSeries */
        $hasSeries = [];

        return array_values($registers->map(function (PosRegister $register) use ($legalEntity, $viewerId, &$hasSeries): array {
            $hasSeries[$register->establishment_id] ??= $this->series->exists($legalEntity, $register->establishment);
            $open = $register->openSession;

            return [
                'public_id' => $register->public_id,
                'name' => $register->name,
                'is_active' => $register->is_active,
                'establishment' => [
                    'public_id' => $register->establishment->public_id,
                    'name' => $register->establishment->name,
                ],
                'has_series' => $hasSeries[$register->establishment_id],
                'open_session' => $open instanceof PosSession
                    ? $this->presenter->openSessionOf($open, $viewerId)
                    : null,
            ];
        })->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionProps(LegalEntity $legalEntity, PosSession $session): array
    {
        $recentSales = $session->sales()
            ->with('fiscalDocument')
            ->latest('id')
            ->limit(10)
            ->get();

        return [
            'public_id' => $session->public_id,
            'opened_at' => $session->opened_at->toIso8601String(),
            'opening_float_minor' => $session->opening_float_minor,
            'currency_code' => $session->currency_code,
            'register' => [
                'public_id' => $session->register->public_id,
                'name' => $session->register->name,
            ],
            'establishment' => [
                'public_id' => $session->establishment->public_id,
                'name' => $session->establishment->name,
            ],
            'series_available' => $this->series->exists($legalEntity, $session->establishment),
            'summary' => $this->presenter->tillSummary($this->summary->forSession($session)),
            'cash_movements' => array_values($session->cashMovements()
                ->latest('id')
                ->get()
                ->map(fn ($movement): array => $this->presenter->cashMovement($movement))
                ->all()),
            'recent_sales' => $this->presenter->sales($recentSales),
        ];
    }

    /**
     * What each saved customer pays, article by article, in the shape the
     * invoice editor reads: decimal strings keyed by the article's public id.
     *
     * @param  iterable<int, Customer>  $customers
     * @return array<string, array<string, string>> keyed by customer public id
     */
    private function agreedPrices(iterable $customers): array
    {
        $collection = collect($customers);
        $resolved = $this->resolvePrices->forCustomers($collection);
        $prices = [];

        foreach ($collection as $customer) {
            $prices[$customer->public_id] = array_map(
                fn (int $minor): string => number_format($minor / 100, 2, '.', ''),
                $resolved[$customer->public_id] ?? [],
            );
        }

        return $prices;
    }
}
