<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\Workspace;
use App\WithholdingType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CustomerController extends Controller
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

        $search = trim((string) $request->string('search'));

        $customers = $legalEntity->customers()
            ->with('priceList:id,public_id,name')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('tax_identification_number', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => [
                'data' => $customers->getCollection()
                    ->map(fn (Customer $customer): array => $this->present($customer))
                    ->values()
                    ->all(),
                'links' => $customers->linkCollection()->all(),
                'total' => $customers->total(),
                'from' => $customers->firstItem(),
                'to' => $customers->lastItem(),
            ],
            'filters' => ['search' => $search],
            'priceLists' => array_values(PriceList::query()
                ->where('legal_entity_id', $legalEntity->id)
                ->active()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (PriceList $list): array => [
                    'value' => $list->public_id,
                    'label' => $list->is_default
                        ? "{$list->name} (por omissão)"
                        : $list->name,
                ])->all()),
            'withholdingTypes' => WithholdingType::options(),
            'canManage' => Gate::allows('create', Customer::class),
        ]);
    }

    public function store(StoreCustomerRequest $request): RedirectResponse
    {
        Gate::authorize('create', Customer::class);
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);

        $attributes = $this->attributes($request);

        // Someone who does not pick a tabela joins the house one, which is the
        // point of marking a list default — otherwise every new customer would
        // silently start at catalogue price.
        $attributes['price_list_id'] ??= PriceList::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_default', true)
            ->value('id');

        $legalEntity->customers()->create([
            ...$attributes,
            'workspace_id' => $legalEntity->workspace_id,
        ]);

        return back()->with('success', 'Cliente guardado.');
    }

    public function update(StoreCustomerRequest $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('update', $customer);
        $customer->update($this->attributes($request));

        return back()->with('success', 'Cliente actualizado.');
    }

    /**
     * Customers are referenced by issued documents, so they are deactivated
     * rather than deleted — a fiscal document must keep resolving its client.
     */
    public function destroy(Request $request, Customer $customer): RedirectResponse
    {
        Gate::authorize('delete', $customer);
        $customer->update(['is_active' => false]);

        return back()->with('success', 'Cliente desactivado. Continua visível nas facturas já emitidas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Customer $customer): array
    {
        return [
            'public_id' => $customer->public_id,
            'name' => $customer->name,
            'tax_identification_number' => $customer->tax_identification_number,
            'country_code' => $customer->country_code,
            'address_line' => $customer->address_line,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'is_active' => $customer->is_active,
            'payment_terms_days' => $customer->payment_terms_days,
            'credit_limit' => $customer->credit_limit_minor === null
                ? ''
                : number_format($customer->credit_limit_minor / 100, 2, '.', ''),
            'price_list' => $customer->priceList->public_id ?? '',
            'auto_send_documents' => $customer->auto_send_documents,
            'withholding_type' => $customer->withholding_type->value ?? '',
            'withholding_rate' => $customer->withholding_rate_basis_points === null
                ? ''
                : number_format($customer->withholding_rate_basis_points / 100, 2, '.', ''),
        ];
    }

    /**
     * The stored shape of the form: the typed credit limit becomes minor units,
     * and the field itself does not exist on the model.
     *
     * @return array<string, mixed>
     */
    private function attributes(StoreCustomerRequest $request): array
    {
        $validated = $request->validated();
        unset($validated['credit_limit'], $validated['price_list'], $validated['withholding_rate']);

        return [
            ...$validated,
            'credit_limit_minor' => $request->creditLimitMinor(),
            'price_list_id' => $request->priceListId(),
            // Blank or absent means this buyer withholds nothing, so the rate
            // goes with the tax rather than lingering without one.
            'withholding_type' => ($validated['withholding_type'] ?? null) ?: null,
            'withholding_rate_basis_points' => $request->withholdingRateBasisPoints(),
        ];
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
