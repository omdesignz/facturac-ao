<?php

namespace App\Http\Controllers;

use App\CatalogueItemType;
use App\Fiscal\HumanServiceCommandContext;
use App\Fiscal\ServiceCommands;
use App\Fiscal\ServiceCreateInput;
use App\Http\Requests\StoreCatalogueItemRequest;
use App\Models\CatalogueItem;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CatalogueItemController extends Controller
{
    /** Matches the default on catalogue_items.stock_scale. */
    private const STOCK_SCALE = 3;

    public function index(Request $request): Response|RedirectResponse
    {
        $legalEntity = $this->legalEntity($request);

        if (! $legalEntity instanceof LegalEntity) {
            return redirect()
                ->route('onboarding')
                ->with('error', 'Conclua primeiro a identidade fiscal da empresa.');
        }

        Gate::authorize('viewAny', CatalogueItem::class);

        $search = trim((string) $request->string('search'));
        $type = (string) $request->string('type');

        $items = $legalEntity->catalogueItems()
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when(
                in_array($type, ['product', 'service'], true),
                fn ($query) => $query->where('type', $type),
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Catalogue/Index', [
            'items' => [
                'data' => $items->getCollection()
                    ->map(fn (CatalogueItem $item): array => $this->present($item))
                    ->values()
                    ->all(),
                'links' => $items->linkCollection()->all(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
            'filters' => ['search' => $search, 'type' => $type],
            'types' => array_map(
                fn (CatalogueItemType $case): array => [
                    'value' => $case->value,
                    'label' => $case->label(),
                ],
                CatalogueItemType::cases(),
            ),
            'currencyCode' => $legalEntity->currency_code,
            'canManage' => Gate::allows('create', CatalogueItem::class),
        ]);
    }

    public function store(StoreCatalogueItemRequest $request): RedirectResponse
    {
        Gate::authorize('create', CatalogueItem::class);
        $legalEntity = $this->legalEntity($request);
        abort_unless($legalEntity instanceof LegalEntity, 404);

        if ($request->validated('type') === 'service') {
            $values = $request->validated();
            $barcode = $values['barcode'] ?? null;
            // The service command accepts a fixed set of fields; the barcode
            // is added to the article once it exists.
            unset($values['type'], $values['tracks_stock'], $values['reorder_level'], $values['barcode']);
            $item = app(ServiceCommands::class)->create(
                HumanServiceCommandContext::resolve($request->user(), $legalEntity),
                ServiceCreateInput::human($values),
            );

            if ($barcode !== null) {
                $item->update(['barcode' => $barcode]);
            }
        } else {
            $legalEntity->catalogueItems()->create([
                ...$this->attributes($request),
                'workspace_id' => $legalEntity->workspace_id,
                'currency_code' => $legalEntity->currency_code,
            ]);
        }

        return back()->with('success', 'Artigo guardado.');
    }

    public function update(
        StoreCatalogueItemRequest $request,
        CatalogueItem $catalogueItem,
    ): RedirectResponse {
        Gate::authorize('update', $catalogueItem);
        $catalogueItem->update($this->attributes($request));

        return back()->with('success', 'Artigo actualizado.');
    }

    /**
     * Issued lines keep a copy of the price, but the item itself is retained
     * and deactivated so historical documents stay explainable.
     */
    public function destroy(Request $request, CatalogueItem $catalogueItem): RedirectResponse
    {
        Gate::authorize('delete', $catalogueItem);
        $catalogueItem->update(['is_active' => false]);

        return back()->with('success', 'Artigo desactivado. Deixa de aparecer em novas facturas.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(StoreCatalogueItemRequest $request): array
    {
        $validated = $request->validated();
        $reorder = $validated['reorder_level'] ?? null;
        unset($validated['unit_price'], $validated['reorder_level']);

        return [
            ...$validated,
            'unit_price_minor' => $request->unitPriceMinor(),
            // Only meaningful when the item is actually tracked, so switching
            // tracking off clears the threshold rather than leaving a stale one.
            'reorder_level_units' => $validated['tracks_stock'] && $reorder !== null
                ? $request->scaledUnits((string) $reorder, self::STOCK_SCALE)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CatalogueItem $item): array
    {
        return [
            'public_id' => $item->public_id,
            'code' => $item->code,
            'barcode' => $item->barcode,
            'type' => $item->type->value,
            'type_label' => $item->type->label(),
            'name' => $item->name,
            'description' => $item->description,
            'unit_of_measure' => $item->unit_of_measure,
            'unit_price_minor' => $item->unit_price_minor,
            'unit_price' => number_format($item->unit_price_minor / 100, 2, '.', ''),
            'currency_code' => $item->currency_code,
            'tax_type' => $item->tax_type,
            'tracks_stock' => $item->tracks_stock,
            'reorder_level' => $item->reorder_level_units === null
                ? ''
                : rtrim(rtrim(number_format(
                    $item->reorder_level_units / (10 ** $item->stock_scale),
                    $item->stock_scale,
                    '.',
                    '',
                ), '0'), '.'),
            'tax_code' => $item->tax_code,
            'tax_percentage' => $item->tax_percentage,
            'tax_exemption_code' => $item->tax_exemption_code,
            'is_active' => $item->is_active,
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
