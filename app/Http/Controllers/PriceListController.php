<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePriceListItemRequest;
use App\Http\Requests\StorePriceListRequest;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\LegalEntity;
use App\Models\PriceList;
use App\Models\PriceListItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tabelas de preços — the named lists customers buy on.
 */
class PriceListController extends Controller
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

        $lists = PriceList::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->withCount(['items', 'customers'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        $selected = $this->selected($request, $lists);

        return Inertia::render('PriceLists/Index', [
            'lists' => array_values($lists
                ->map(fn (PriceList $list): array => [
                    'public_id' => $list->public_id,
                    'name' => $list->name,
                    'description' => $list->description,
                    'is_default' => $list->is_default,
                    'is_active' => $list->is_active,
                    'item_count' => $list->items_count,
                    'customer_count' => $list->customers_count,
                ])->all()),
            'selected' => $selected === null ? null : [
                'public_id' => $selected->public_id,
                'name' => $selected->name,
                'description' => $selected->description,
                'is_default' => $selected->is_default,
                'is_active' => $selected->is_active,
                'rows' => $this->rows($legalEntity, $selected),
                'customers' => array_values($selected->customers()
                    ->orderBy('name')
                    ->get()
                    ->map(fn (Customer $customer): array => [
                        'public_id' => $customer->public_id,
                        'name' => $customer->name,
                    ])->all()),
            ],
            'currencyCode' => $legalEntity->currency_code,
            'canManage' => Gate::allows('create', Customer::class),
        ]);
    }

    public function store(StorePriceListRequest $request): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        Gate::authorize('create', Customer::class);

        $list = PriceList::query()->create([
            'workspace_id' => $legalEntity->workspace_id,
            'legal_entity_id' => $legalEntity->id,
            'name' => (string) $request->validated('name'),
            'description' => $request->validated('description'),
            'currency_code' => $legalEntity->currency_code,
            'is_active' => (bool) $request->validated('is_active'),
            'is_default' => false,
        ]);

        if ((bool) $request->validated('is_default')) {
            $list->makeDefault();
        }

        return redirect()
            ->route('price-lists.index', ['tabela' => $list->public_id])
            ->with('success', "Tabela “{$list->name}” criada. Defina os preços abaixo.");
    }

    public function update(
        StorePriceListRequest $request,
        PriceList $priceList,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($priceList->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        $priceList->fill([
            'name' => (string) $request->validated('name'),
            'description' => $request->validated('description'),
            'is_active' => (bool) $request->validated('is_active'),
        ])->save();

        if ((bool) $request->validated('is_default')) {
            $priceList->makeDefault();
        }

        return back()->with('success', 'Tabela actualizada.');
    }

    /**
     * Replaces the prices on one tabela.
     *
     * A row submitted blank is a removal, not a zero: charging nothing and not
     * being on the list are different answers, and only one of them is what
     * clearing a field means.
     */
    public function storePrices(
        StorePriceListItemRequest $request,
        PriceList $priceList,
    ): RedirectResponse {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($priceList->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        $items = CatalogueItem::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->whereIn(
                'public_id',
                array_column($request->prices(), 'catalogue_item'),
            )
            ->get()
            ->keyBy('public_id');

        DB::transaction(function () use ($request, $priceList, $items): void {
            foreach ($request->prices() as $row) {
                $item = $items->get($row['catalogue_item']);

                if (! $item instanceof CatalogueItem) {
                    continue;
                }

                if ($row['unit_price_minor'] === null) {
                    $priceList->items()
                        ->where('catalogue_item_id', $item->id)
                        ->delete();

                    continue;
                }

                PriceListItem::query()->updateOrCreate(
                    [
                        'price_list_id' => $priceList->id,
                        'catalogue_item_id' => $item->id,
                    ],
                    [
                        'workspace_id' => $priceList->workspace_id,
                        'legal_entity_id' => $priceList->legal_entity_id,
                        'unit_price_minor' => $row['unit_price_minor'],
                        'currency_code' => $item->currency_code,
                        'note' => $row['note'],
                    ],
                );
            }
        });

        return back()->with('success', 'Preços da tabela guardados.');
    }

    public function destroy(Request $request, PriceList $priceList): RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);
        abort_unless($priceList->legal_entity_id === $legalEntity->id, 404);
        Gate::authorize('create', Customer::class);

        // Customers keep their invoices; they simply fall back to the
        // catalogue price from here on, which the null foreign key does.
        $priceList->delete();

        return redirect()
            ->route('price-lists.index')
            ->with('success', 'Tabela removida. Os clientes voltam ao preço de tabela do artigo.');
    }

    /**
     * Every active article, with what this tabela charges for it.
     *
     * The whole catalogue is listed rather than only the priced rows, because
     * the question the screen answers is "what do these customers pay for each
     * thing we sell" — and a blank line is a real answer to that.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(LegalEntity $legalEntity, PriceList $list): array
    {
        $priced = $list->items()->get()->keyBy('catalogue_item_id');

        return array_values($legalEntity->catalogueItems()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (CatalogueItem $item) use ($priced): array {
                $row = $priced->get($item->id);

                return [
                    'catalogue_item' => $item->public_id,
                    'code' => $item->code,
                    'name' => $item->name,
                    'unit_of_measure' => $item->unit_of_measure,
                    'list_price_minor' => $item->unit_price_minor,
                    'unit_price' => $row instanceof PriceListItem
                        ? number_format($row->unit_price_minor / 100, 2, '.', '')
                        : '',
                    'note' => $row?->note,
                ];
            })->all());
    }

    /**
     * @param  Collection<int, PriceList>  $lists
     */
    private function selected(Request $request, $lists): ?PriceList
    {
        $requested = $request->string('tabela')->toString();

        $selected = $requested === ''
            ? $lists->first()
            : $lists->firstWhere('public_id', $requested);

        return $selected instanceof PriceList ? $selected : null;
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
