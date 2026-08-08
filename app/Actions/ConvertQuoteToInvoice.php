<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\FiscalDocumentStatus;
use App\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\QuoteStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns an accepted quote into an invoice draft.
 *
 * It stops at a draft on purpose. Issuing takes a fiscal series, signs the
 * content and files it with the AGT — irreversible steps that should happen
 * when someone deliberately presses issue, not as a side effect of a customer
 * saying yes to a price.
 */
class ConvertQuoteToInvoice
{
    public function execute(Quote $quote, User $user): FiscalDocument
    {
        if (! $quote->status->canConvert()) {
            throw BillingActionRefused::because(
                'Só um orçamento enviado ou aceite pode passar a factura.',
            );
        }

        if ($quote->converted_document_id !== null) {
            throw BillingActionRefused::because(
                'Este orçamento já deu origem a um documento.',
            );
        }

        if ($quote->lines()->count() === 0) {
            throw BillingActionRefused::because('Um orçamento sem linhas não pode ser facturado.');
        }

        // A quote may go out before the prospect has given a NIF; a factura may
        // not. Asking for it here — rather than inventing a consumidor-final
        // number for a named company — keeps the tax identity on the document
        // the one the customer actually gave.
        if (! $this->hasUsableTaxNumber($quote)) {
            throw BillingActionRefused::because(
                'Indique o NIF do cliente no orçamento antes de o passar a factura.',
            );
        }

        return DB::transaction(function () use ($quote, $user): FiscalDocument {
            $customer = $quote->customer;
            $issuedOn = now('Africa/Luanda')->startOfDay();

            $document = FiscalDocument::query()->create([
                'workspace_id' => $quote->workspace_id,
                'legal_entity_id' => $quote->legal_entity_id,
                'establishment_id' => $quote->establishment_id,
                'customer_id' => $quote->customer_id,
                'created_by_user_id' => $user->id,
                'updated_by_user_id' => $user->id,
                'document_type' => FiscalDocumentType::Invoice,
                'status' => FiscalDocumentStatus::Draft,
                'agt_document_status' => 'N',
                'document_date' => $issuedOn->toDateString(),
                // The customer's standing terms decide when it falls due, not
                // the quote's own validity date, which was only a deadline for
                // accepting the price.
                'due_date' => $customer === null
                    ? $issuedOn->toDateString()
                    : $customer->dueDateFor($issuedOn)->toDateString(),
                'currency_code' => $quote->currency_code,
                'customer_name' => $quote->customer_name,
                'customer_tax_identification_number' => $customer->tax_identification_number
                    ?? $quote->customer_tax_identification_number,
                'customer_country_code' => $quote->customer_country_code,
                'customer_address' => $quote->customer_address,
                'notes' => $quote->notes,
                'settlement_total_minor' => 0,
                'net_total_minor' => $quote->net_total_minor,
                'tax_payable_minor' => $quote->tax_total_minor,
                'gross_total_minor' => $quote->gross_total_minor,
                'revision' => 1,
                'payload_schema_version' => (string) config('agt.schema_version', '1.2'),
                'calculation_sha256' => hash('sha256', "quote:{$quote->public_id}"),
            ]);

            foreach ($quote->lines as $line) {
                $this->copyLine($document, $line);
            }

            $quote->forceFill([
                'status' => QuoteStatus::Converted,
                'decided_at' => $quote->decided_at ?? now(),
                'converted_document_id' => $document->id,
            ])->save();

            activity('quote')
                ->causedBy($user)
                ->performedOn($quote)
                ->event('converted')
                ->withProperties([
                    'reference' => $quote->reference,
                    'document_public_id' => $document->public_id,
                ])
                ->log('quote converted to invoice draft');

            return $document;
        });
    }

    /**
     * Whether the quote carries a tax number a fiscal document would accept.
     *
     * Deliberately the same shape the invoice form enforces, so a quote never
     * converts into a draft that the invoice screen would then reject.
     */
    private function hasUsableTaxNumber(Quote $quote): bool
    {
        $number = $quote->customer->tax_identification_number
            ?? $quote->customer_tax_identification_number;

        return $number !== null && preg_match('/\A[A-Z0-9]{9,32}\z/', $number) === 1;
    }

    /**
     * The code for a line the quote wrote by hand rather than picked from the
     * catalogue.
     *
     * Every fiscal line needs one, and a quote's line does not. Rather than
     * refusing the conversion over a field the quote form never asked for, the
     * description becomes the code and the draft carries it to the invoice
     * screen, where it is editable and the user reviews it before issuing.
     */
    private function productCodeFor(QuoteLine $line): string
    {
        if ($line->product_code !== null && $line->product_code !== '') {
            return $line->product_code;
        }

        $derived = Str::upper(Str::slug($line->product_description));

        return $derived === ''
            ? "L{$line->line_number}"
            : Str::limit($derived, 60, '');
    }

    /**
     * Copies one quote line onto the document, with its tax row.
     *
     * The amounts are carried across rather than recomputed: the customer
     * agreed to these numbers, and re-deriving them risks the invoice differing
     * from the quote by a kwanza of rounding.
     */
    private function copyLine(FiscalDocument $document, QuoteLine $line): void
    {
        $created = $document->lines()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'line_number' => $line->line_number,
            'operation_type' => $line->operation_type,
            'product_code' => $this->productCodeFor($line),
            'product_description' => $line->product_description,
            'quantity_units' => $line->quantity_units,
            'quantity_scale' => $line->quantity_scale,
            'unit_of_measure' => $line->unit_of_measure,
            'unit_price_base_minor' => $line->unit_price_minor,
            'unit_price_micros' => $line->unit_price_minor * 1_000_000,
            'discount_rate_basis_points' => $line->discount_rate_basis_points,
            'base_amount_minor' => $line->net_amount_minor,
            'settlement_amount_minor' => 0,
            'net_amount_minor' => $line->net_amount_minor,
            'tax_amount_minor' => $line->tax_amount_minor,
            'gross_amount_minor' => $line->gross_amount_minor,
        ]);

        $created->taxes()->create([
            'workspace_id' => $document->workspace_id,
            'legal_entity_id' => $document->legal_entity_id,
            'fiscal_document_id' => $document->id,
            'tax_type' => $line->tax_type,
            'tax_country_region' => 'AO',
            'tax_code' => $line->tax_code,
            // The percentage is stored as a decimal string on the quote and as
            // basis points on the document, which is the same number in the
            // representation each side already uses.
            'tax_rate_basis_points' => (int) round(((float) $line->tax_percentage) * 100),
            'tax_contribution_minor' => $line->tax_amount_minor,
            'tax_exemption_code' => $line->tax_exemption_code,
        ]);
    }
}
