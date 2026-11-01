<?php

namespace App\Fiscal\Documents;

use App\Actions\ResolveCustomerPrices;
use App\CatalogueItemType;
use App\FiscalOperationType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\LegalEntity;
use Illuminate\Validation\ValidationException;

/**
 * The fiscal lines of a sale, built from the catalogue as it stands now.
 *
 * A till or a phone says which item and how many. The description, price and tax
 * are read here, so neither can invent them.
 */
final readonly class CatalogueDocumentLines
{
    public function __construct(private ResolveCustomerPrices $resolvePrices) {}

    /**
     * @param  list<array{catalogue_item_public_id: string, quantity: string, discount_percentage?: string}>  $requested
     * @return list<array{
     *     operation_type: string,
     *     operation_date: string|null,
     *     product_code: string,
     *     product_description: string,
     *     quantity: string,
     *     unit_of_measure: string,
     *     unit_price: string,
     *     discount_percentage: string,
     *     tax_type: string,
     *     tax_code: string|null,
     *     tax_percentage: string,
     *     tax_exemption_code: string|null
     * }>
     */
    public function for(LegalEntity $legalEntity, string $currencyCode, ?Customer $customer, array $requested): array
    {
        $items = CatalogueItem::query()
            ->where('workspace_id', $legalEntity->workspace_id)
            ->where('legal_entity_id', $legalEntity->id)
            ->where('is_active', true)
            ->where('currency_code', $currencyCode)
            ->whereIn('public_id', array_column($requested, 'catalogue_item_public_id'))
            ->get()
            ->keyBy('public_id');

        // The same precedence as the invoice editor: a price agreed with this
        // buyer, then their tabela, then the catalogue.
        $agreed = $customer instanceof Customer ? $this->resolvePrices->forCustomer($customer) : [];
        $lines = [];

        foreach ($requested as $index => $line) {
            $item = $items->get($line['catalogue_item_public_id']);

            if (! $item instanceof CatalogueItem) {
                throw ValidationException::withMessages([
                    "lines.{$index}.catalogue_item_public_id" => 'Este artigo já não está disponível para venda.',
                ]);
            }

            $lines[] = [
                'operation_type' => $item->type === CatalogueItemType::Service
                    ? FiscalOperationType::GeneralService->value
                    : FiscalOperationType::GoodsTransfer->value,
                'operation_date' => null,
                'product_code' => $item->code,
                'product_description' => mb_substr($item->name, 0, 200),
                'quantity' => $line['quantity'],
                'unit_of_measure' => $item->unit_of_measure,
                'unit_price' => $this->decimal($agreed[$item->public_id] ?? $item->unit_price_minor),
                'discount_percentage' => $line['discount_percentage'] ?? '0',
                'tax_type' => $item->tax_type,
                'tax_code' => $item->tax_code,
                'tax_percentage' => (string) $item->tax_percentage,
                'tax_exemption_code' => $item->tax_exemption_code,
            ];
        }

        return $lines;
    }

    /** Minor units as the "123.45" string the calculator reads, without a float. */
    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
