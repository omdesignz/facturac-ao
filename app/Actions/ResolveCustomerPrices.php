<?php

namespace App\Actions;

use App\Models\Customer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What each customer actually pays, article by article.
 *
 * One place decides, because the answer has three possible sources and the
 * order between them is the whole rule: a price agreed with this one buyer
 * beats the tabela they are on, and the tabela beats the catalogue. Spreading
 * that across the invoice screen and the quote screen is how the two come to
 * disagree.
 *
 * The catalogue price is deliberately absent from the result. A line that
 * finds nothing here is already filled from the article itself, so repeating
 * it would only invite the two to drift.
 */
class ResolveCustomerPrices
{
    /**
     * @return array<string, int> minor units, keyed by catalogue item public id
     */
    public function forCustomer(Customer $customer): array
    {
        /** @var Collection<int, Customer> $one */
        $one = collect([$customer]);

        return $this->forCustomers($one)[$customer->public_id] ?? [];
    }

    /**
     * The same answer for many customers, in two queries rather than two per
     * customer — the invoice screen asks for every customer at once.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<string, array<string, int>> keyed by customer public id
     */
    public function forCustomers(Collection $customers): array
    {
        if ($customers->isEmpty()) {
            return [];
        }

        $resolved = [];

        foreach ($customers as $customer) {
            $resolved[$customer->public_id] = [];
        }

        $listPrices = $this->pricesByList($customers);

        foreach ($customers as $customer) {
            if ($customer->price_list_id !== null) {
                $resolved[$customer->public_id] = $listPrices[$customer->price_list_id] ?? [];
            }
        }

        // Overrides land last so the price agreed with one buyer wins over the
        // list they happen to share with everyone else.
        foreach ($this->overridesByCustomer($customers) as $customerPublicId => $prices) {
            $resolved[$customerPublicId] = [
                ...$resolved[$customerPublicId] ?? [],
                ...$prices,
            ];
        }

        return $resolved;
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<int, array<string, int>> keyed by price list id
     */
    private function pricesByList(Collection $customers): array
    {
        $listIds = $customers
            ->pluck('price_list_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($listIds === []) {
            return [];
        }

        $rows = DB::table('price_list_items')
            ->join(
                'catalogue_items',
                'catalogue_items.id',
                '=',
                'price_list_items.catalogue_item_id',
            )
            ->whereIn('price_list_items.price_list_id', $listIds)
            ->get([
                'price_list_items.price_list_id',
                'price_list_items.unit_price_minor',
                'catalogue_items.public_id as item_public_id',
            ]);

        $prices = [];

        foreach ($rows as $row) {
            $prices[(int) $row->price_list_id][(string) $row->item_public_id]
                = (int) $row->unit_price_minor;
        }

        return $prices;
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @return array<string, array<string, int>> keyed by customer public id
     */
    private function overridesByCustomer(Collection $customers): array
    {
        $rows = DB::table('customer_prices')
            ->join(
                'catalogue_items',
                'catalogue_items.id',
                '=',
                'customer_prices.catalogue_item_id',
            )
            ->join('customers', 'customers.id', '=', 'customer_prices.customer_id')
            ->whereIn('customer_prices.customer_id', $customers->pluck('id')->all())
            ->get([
                'customers.public_id as customer_public_id',
                'customer_prices.unit_price_minor',
                'catalogue_items.public_id as item_public_id',
            ]);

        $prices = [];

        foreach ($rows as $row) {
            $prices[(string) $row->customer_public_id][(string) $row->item_public_id]
                = (int) $row->unit_price_minor;
        }

        return $prices;
    }
}
