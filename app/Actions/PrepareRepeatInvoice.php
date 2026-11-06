<?php

namespace App\Actions;

use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Documents\CatalogueDocumentLines;
use App\Fiscal\SupportedTaxTreatment;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final readonly class PrepareRepeatInvoice
{
    public function __construct(private CatalogueDocumentLines $catalogueLines) {}

    public static function supports(FiscalDocument $document): bool
    {
        return in_array($document->document_type, [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt], true)
            && ! in_array($document->status, [FiscalDocumentStatus::Draft, FiscalDocumentStatus::Invalid], true)
            && $document->document_no !== null;
    }

    /**
     * @return array{source: FiscalDocument, customer: Customer, items: Collection<int, CatalogueItem>, requested: list<array{catalogue_item_public_id: string, quantity: string}>, lines: list<array<string, mixed>>}
     */
    public function execute(LegalEntity $entity, string $sourcePublicId, bool $wholeQuantities = false, bool $combineItems = false): array
    {
        Gate::authorize('create', FiscalDocument::class);
        $source = FiscalDocument::query()->where('workspace_id', $entity->workspace_id)
            ->where('legal_entity_id', $entity->id)->where('public_id', $sourcePublicId)
            ->with('lines')->firstOrFail();
        Gate::authorize('view', $source);

        if (! self::supports($source) || $source->currency_code !== $entity->currency_code) {
            $this->refuse('Este documento não pode servir de base a uma nova factura nesta moeda.');
        }

        $customer = $entity->customers()->where('workspace_id', $entity->workspace_id)
            ->where('is_active', true)->find($source->customer_id);
        if (! $customer instanceof Customer) {
            $this->refuse('O cliente original não está disponível. Crie uma nova factura e escolha o cliente.');
        }
        if ($source->lines->isEmpty() || $source->lines->count() > 200) {
            $this->refuse('Só é possível reutilizar documentos com 1 a 200 linhas.');
        }

        $items = $entity->catalogueItems()->where('workspace_id', $entity->workspace_id)
            ->where('is_active', true)->where('currency_code', $entity->currency_code)
            ->whereIn('code', $source->lines->pluck('product_code'))->get();
        $byCode = $items->keyBy('code');
        $requested = [];
        foreach ($source->lines as $line) {
            $item = $byCode->get($line->product_code);
            if (! $item instanceof CatalogueItem || $item->unit_of_measure !== $line->unit_of_measure) {
                $this->refuse("O artigo {$line->product_code} já não está disponível com a mesma unidade. Crie uma nova factura e reveja os artigos.");
            }
            if ($line->quantity_units <= 0 || $line->quantity_scale < 0 || $line->quantity_scale > 4) {
                $this->refuse('Uma quantidade original não é compatível com uma nova factura.');
            }
            $quantity = (string) CanonicalNumber::fromScaledInteger($line->quantity_units, $line->quantity_scale);
            if ($wholeQuantities && str_contains($quantity, '.')) {
                $this->refuse('Este documento tem quantidades fraccionadas. Use a versão web para o reutilizar.');
            }
            $requested[] = ['catalogue_item_public_id' => $item->public_id, 'quantity' => $quantity];
        }
        if ($wholeQuantities) {
            $grouped = [];
            foreach ($requested as $line) {
                $key = $line['catalogue_item_public_id'];
                $previous = $grouped[$key] ?? 0;
                if ((int) $line['quantity'] >= PHP_INT_MAX - $previous) {
                    $this->refuse('A quantidade acumulada é demasiado grande. Reveja o documento na versão web.');
                }
                $grouped[$key] = $previous + (int) $line['quantity'];
            }
            $requested = [];
            foreach ($grouped as $key => $quantity) {
                $requested[] = ['catalogue_item_public_id' => $key, 'quantity' => (string) $quantity];
            }
        } elseif ($combineItems) {
            $requested = $this->combineDecimalQuantities($requested);
        }

        $lines = $this->catalogueLines->for($entity, $entity->currency_code, $customer, $requested);
        foreach ($lines as &$line) {
            $treatment = SupportedTaxTreatment::fromComponents($line['tax_type'], $line['tax_code'], $line['tax_percentage'], $line['tax_exemption_code']);
            if ($treatment === null) {
                $this->refuse('Reveja o tratamento fiscal dos artigos no catálogo antes de os reutilizar.');
            }
            $line['tax_treatment'] = $treatment->value;
        }

        return compact('source', 'customer', 'items', 'requested', 'lines');
    }

    /**
     * @param  list<array{catalogue_item_public_id: string, quantity: string}>  $requested
     * @return list<array{catalogue_item_public_id: string, quantity: string}>
     */
    private function combineDecimalQuantities(array $requested): array
    {
        $grouped = [];
        foreach ($requested as $line) {
            $key = $line['catalogue_item_public_id'];
            $parts = explode('.', $line['quantity']);
            $scale = strlen($parts[1] ?? '');
            $units = (int) implode('', $parts);
            $previous = $grouped[$key] ?? ['units' => 0, 'scale' => 0];
            $commonScale = max($scale, $previous['scale']);
            $factor = 10 ** ($commonScale - $scale);
            $previousFactor = 10 ** ($commonScale - $previous['scale']);
            if ($units > intdiv(PHP_INT_MAX, $factor) || $previous['units'] > intdiv(PHP_INT_MAX, $previousFactor)) {
                $this->refuse('A quantidade acumulada é demasiado grande. Reveja o documento na versão web.');
            }
            $units *= $factor;
            $previousUnits = $previous['units'] * $previousFactor;
            if ($units > PHP_INT_MAX - $previousUnits) {
                $this->refuse('A quantidade acumulada é demasiado grande. Reveja o documento na versão web.');
            }
            $grouped[$key] = ['units' => $previousUnits + $units, 'scale' => $commonScale];
        }

        $result = [];
        foreach ($grouped as $key => $quantity) {
            $result[] = [
                'catalogue_item_public_id' => $key,
                'quantity' => (string) CanonicalNumber::fromScaledInteger($quantity['units'], $quantity['scale']),
            ];
        }

        return $result;
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['repeat' => $message]);
    }
}
