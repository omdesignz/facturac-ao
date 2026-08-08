<?php

namespace App\Http\Controllers;

use App\Analytics\AgingQuery;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão de dívidas: who owes what, and for how long.
 */
class DebtController extends Controller
{
    public function __construct(private AgingQuery $aging) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $workspace = $request->attributes->get('currentWorkspace');
        $legalEntity = $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', Customer::class);

        $rows = $this->aging->byCustomer($legalEntity);

        return Inertia::render('Debts/Index', [
            'customers' => $rows,
            'totals' => $this->aging->totals($rows),
            'buckets' => AgingQuery::buckets(),
            'summary' => [
                'outstanding_minor' => array_sum(array_column($rows, 'outstanding_minor')),
                'overdue_minor' => array_sum(array_column($rows, 'overdue_minor')),
                'customer_count' => count($rows),
                'over_limit_count' => count(array_filter(
                    $rows,
                    fn (array $row): bool => $row['over_limit'] === true,
                )),
            ],
            'currencyCode' => $legalEntity->currency_code,
        ]);
    }
}
