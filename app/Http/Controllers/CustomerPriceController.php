<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerPriceRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\CustomerPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The prices agreed with one customer, kept on that customer's page.
 */
class CustomerPriceController extends Controller
{
    public function store(
        StoreCustomerPriceRequest $request,
        Customer $customer,
    ): RedirectResponse {
        Gate::authorize('update', $customer);

        $item = CatalogueItem::query()
            ->where('legal_entity_id', $customer->legal_entity_id)
            ->where('public_id', (string) $request->validated('catalogue_item'))
            ->firstOrFail();

        // Agreeing a new price for the same article replaces the old one rather
        // than stacking a second row that silently never applies.
        CustomerPrice::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'catalogue_item_id' => $item->id,
            ],
            [
                'workspace_id' => $customer->workspace_id,
                'legal_entity_id' => $customer->legal_entity_id,
                'unit_price_minor' => $request->unitPriceMinor(),
                'currency_code' => $item->currency_code,
                'note' => $request->validated('note'),
            ],
        );

        return back()->with('success', 'Preço acordado guardado.');
    }

    public function destroy(Customer $customer, CustomerPrice $price): RedirectResponse
    {
        Gate::authorize('update', $customer);
        abort_unless($price->customer_id === $customer->id, 404);

        $price->delete();

        return back()->with('success', 'Preço acordado removido. Volta a valer o preço de tabela.');
    }
}
