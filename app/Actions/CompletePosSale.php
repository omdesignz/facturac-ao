<?php

namespace App\Actions;

use App\CatalogueItemType;
use App\Exceptions\BillingActionRefused;
use App\Exceptions\FiscalFinalizationBlocked;
use App\Fiscal\Pos\FinalConsumer;
use App\Fiscal\Pos\PosSeriesResolver;
use App\FiscalDocumentType;
use App\FiscalOperationType;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalSeries;
use App\Models\PosSale;
use App\Models\PosSession;
use App\Models\User;
use App\PaymentMethod;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rings up one sale: a Factura/Recibo drafted and issued in a single step.
 *
 * A point-of-sale sale is nothing new fiscally. It goes through the same draft
 * and issue pipeline as any other FR, inside one transaction, so a sale either
 * exists completely (numbered, signed, queued for the AGT, stock moved) or not
 * at all. Prices, taxes and descriptions are read from the catalogue here, never
 * from the till.
 *
 * @phpstan-type SaleLine array{catalogue_item_public_id: string, quantity: string, discount_percentage: string}
 * @phpstan-type Sale array{
 *     client_key: string,
 *     customer_public_id: string|null,
 *     customer: array{name: string, tax_identification_number: string}|null,
 *     lines: list<SaleLine>,
 *     payment_method: PaymentMethod,
 *     tendered_minor: int|null,
 *     expected_total_minor: int
 * }
 */
final readonly class CompletePosSale
{
    /** The most change a sale may give back: 100 000,00 in the sale's currency. */
    public const int MAX_CHANGE_MINOR = 10_000_000;

    public function __construct(
        private SaveFiscalDocumentDraft $saveDraft,
        private IssueFiscalDocument $issue,
        private ResolveCustomerPrices $resolvePrices,
        private PosSeriesResolver $series,
    ) {}

    /**
     * @param  Sale  $sale
     *
     * @throws ValidationException
     * @throws FiscalFinalizationBlocked
     * @throws BillingActionRefused
     * @throws AuthorizationException
     */
    public function execute(PosSession $session, User $user, array $sale): PosSale
    {
        try {
            return DB::transaction(fn (): PosSale => $this->complete($session, $user, $sale));
        } catch (UniqueConstraintViolationException $exception) {
            // Two identical requests raced past the lock: the loser finds the
            // winner's sale and answers with it. Anything else is not ours.
            $existing = $this->existing($session, $sale['client_key']);

            if ($existing instanceof PosSale) {
                return $existing;
            }

            throw $exception;
        }
    }

    /**
     * @param  Sale  $sale
     */
    private function complete(PosSession $session, User $user, array $sale): PosSale
    {
        $locked = PosSession::query()
            ->with(['legalEntity', 'establishment'])
            ->whereKey($session->id)
            ->where('workspace_id', $session->workspace_id)
            ->where('legal_entity_id', $session->legal_entity_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->opened_by_user_id !== $user->id) {
            throw new AuthorizationException('Só quem abriu o turno pode vender nele.');
        }

        // A retried request must come back as the sale it already made, even if
        // the shift has been closed in the meantime.
        $existing = $this->existing($locked, $sale['client_key']);

        if ($existing instanceof PosSale) {
            return $existing;
        }

        if (! $locked->isOpen()) {
            throw BillingActionRefused::because('Este turno já foi fechado.');
        }

        $legalEntity = $locked->legalEntity;
        $customer = $this->savedCustomer($locked, $sale['customer_public_id']);
        $lines = $this->lines($locked, $customer, $sale['lines']);

        $today = now('Africa/Luanda')->toDateString();
        $draft = $this->saveDraft->execute($legalEntity, $user, [
            'document_type' => FiscalDocumentType::InvoiceReceipt->value,
            'document_date' => $today,
            'due_date' => null,
            'currency_code' => $locked->currency_code,
            'withholdings' => [],
            'establishment_public_id' => $locked->establishment->public_id,
            'customer_public_id' => $customer?->public_id,
            'customer' => $this->customerBlock($sale),
            'notes' => null,
            'references_document_public_id' => null,
            'adjustment_reason' => null,
            'payment_method' => $sale['payment_method']->value,
            'payment_amount_minor' => null,
            'payment_date' => $today,
            'settlements' => [],
            'lines' => $lines,
        ]);

        $total = $draft->gross_total_minor;

        // The customer is charged what the screen showed, or not at all.
        if ($total !== $sale['expected_total_minor']) {
            throw ValidationException::withMessages([
                'expected_total_minor' => 'O total mudou desde que a venda foi apresentada. Reveja o carrinho.',
            ]);
        }

        [$tendered, $change] = $this->tender($sale, $total);

        $series = $this->series->forEstablishment($legalEntity, $locked->establishment);

        if (! $series instanceof FiscalSeries) {
            throw FiscalFinalizationBlocked::because(
                'Não há série de Factura/Recibo disponível neste estabelecimento. Peça uma série à AGT antes de vender.',
            );
        }

        $this->issue->execute($draft, $user, $series->public_id, $draft->revision);

        $posSale = PosSale::query()->create([
            'workspace_id' => $locked->workspace_id,
            'legal_entity_id' => $locked->legal_entity_id,
            'pos_session_id' => $locked->id,
            'fiscal_document_id' => $draft->id,
            'client_key' => $sale['client_key'],
            'payment_method' => $sale['payment_method'],
            'total_minor' => $total,
            'tendered_minor' => $tendered,
            'change_minor' => $change,
            'created_by_user_id' => $user->id,
        ]);

        return $posSale->load('fiscalDocument');
    }

    private function existing(PosSession $session, string $clientKey): ?PosSale
    {
        $existing = PosSale::query()
            ->with('fiscalDocument')
            ->where('legal_entity_id', $session->legal_entity_id)
            ->where('client_key', $clientKey)
            ->first();

        if (! $existing instanceof PosSale) {
            return null;
        }

        if ($existing->pos_session_id !== $session->id) {
            throw BillingActionRefused::because('Esta venda já foi registada noutro turno.');
        }

        $existing->replayed = true;

        return $existing;
    }

    private function savedCustomer(PosSession $session, ?string $publicId): ?Customer
    {
        if ($publicId === null) {
            return null;
        }

        $customer = Customer::query()
            ->where('public_id', $publicId)
            ->where('workspace_id', $session->workspace_id)
            ->where('legal_entity_id', $session->legal_entity_id)
            ->where('is_active', true)
            ->first();

        if (! $customer instanceof Customer) {
            throw ValidationException::withMessages([
                'customer_public_id' => 'Este cliente já não está disponível.',
            ]);
        }

        return $customer;
    }

    /**
     * The fiscal lines, built from the catalogue as it stands now.
     *
     * @param  list<SaleLine>  $requested
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
    private function lines(PosSession $session, ?Customer $customer, array $requested): array
    {
        $items = CatalogueItem::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('legal_entity_id', $session->legal_entity_id)
            ->where('is_active', true)
            ->where('currency_code', $session->currency_code)
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
                'discount_percentage' => $line['discount_percentage'],
                'tax_type' => $item->tax_type,
                'tax_code' => $item->tax_code,
                'tax_percentage' => (string) $item->tax_percentage,
                'tax_exemption_code' => $item->tax_exemption_code,
            ];
        }

        return $lines;
    }

    /**
     * @param  Sale  $sale
     * @return array{name: string, tax_identification_number: string, country_code: string, address_line: string|null}
     */
    private function customerBlock(array $sale): array
    {
        // A saved customer's own details are read by the draft; this block
        // only matters when there is none.
        if ($sale['customer'] === null) {
            return FinalConsumer::profile();
        }

        return [
            'name' => $sale['customer']['name'],
            'tax_identification_number' => $sale['customer']['tax_identification_number'],
            'country_code' => FinalConsumer::COUNTRY_CODE,
            'address_line' => null,
        ];
    }

    /**
     * What was handed over and what goes back.
     *
     * Only cash has change: every other method settles exactly the total, so a
     * tendered figure sent with a card sale is ignored.
     *
     * @param  Sale  $sale
     * @return array{0: int, 1: int}
     */
    private function tender(array $sale, int $total): array
    {
        if ($sale['payment_method'] !== PaymentMethod::Cash) {
            return [$total, 0];
        }

        $tendered = $sale['tendered_minor'];

        if ($tendered === null) {
            throw ValidationException::withMessages([
                'tendered_minor' => 'Indique o valor entregue pelo cliente.',
            ]);
        }

        if ($tendered < $total) {
            throw ValidationException::withMessages([
                'tendered_minor' => 'O valor entregue é inferior ao total da venda.',
            ]);
        }

        // Nobody hands over this much more than they owe. A barcode read into
        // the amount field does, and would otherwise print as the change.
        if ($tendered - $total > self::MAX_CHANGE_MINOR) {
            throw ValidationException::withMessages([
                'tendered_minor' => 'O valor entregue está muito acima do total. Confirme-o antes de emitir.',
            ]);
        }

        return [$tendered, $tendered - $total];
    }

    /** Minor units as the "123.45" string the calculator reads, without a float. */
    private function decimal(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
