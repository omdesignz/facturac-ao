<?php

namespace App\Fiscal\Documents;

use App\Billing\SubscriptionAccess;
use App\Fiscal\Agt\Support\CanonicalNumber;
use App\Fiscal\Pos\PosSeriesResolver;
use App\FiscalDocumentType;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\LegalEntity;
use App\Models\Quote;
use App\Models\QuoteLine;
use App\Models\User;
use App\QuoteStatus;
use App\SubscriptionFeature;
use Illuminate\Support\Facades\Gate;

/**
 * A quote as the phone shows it, and what this person may do with it now.
 */
final readonly class MobileQuotePresenter
{
    public function __construct(
        private SubscriptionAccess $subscription,
        private PosSeriesResolver $series,
        private MobileDocumentReceipt $receipt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function row(Quote $quote): array
    {
        $status = $quote->effectiveStatus();

        return [
            'public_id' => $quote->public_id,
            'reference' => $quote->reference,
            'customer_name' => $quote->customer_name,
            'valid_until' => $quote->valid_until->toDateString(),
            'gross_total_minor' => $quote->gross_total_minor,
            'currency_code' => $quote->currency_code,
            'status' => $status->value,
            'status_label' => $status->label(),
            'tone' => match ($status) {
                QuoteStatus::Accepted, QuoteStatus::Converted => 'positive',
                QuoteStatus::Rejected, QuoteStatus::Expired => 'critical',
                default => 'neutral',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Quote $quote, User $user, LegalEntity $legalEntity): array
    {
        $quote->loadMissing(['lines', 'convertedDocument.lines.taxes', 'convertedDocument.establishment']);

        $status = $quote->effectiveStatus();
        $canManage = Gate::allows('create', Customer::class)
            && $this->subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing);
        $invoice = $quote->convertedDocument;

        return [
            ...$this->row($quote),
            'issue_date' => $quote->issue_date->toDateString(),
            'can_send' => $canManage && $status === QuoteStatus::Draft,
            'can_decide' => $canManage && $status === QuoteStatus::Sent,
            'can_convert' => $canManage && $status->canConvert() && ! $invoice instanceof FiscalDocument,
            'share_text' => $status->isClosed() ? null : $this->shareText($quote, $legalEntity),
            'summary' => $this->quoteSummary($quote),
            'invoice' => $invoice instanceof FiscalDocument ? $this->invoice($invoice, $user, $legalEntity) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(FiscalDocument $document, User $user, LegalEntity $legalEntity): array
    {
        $reason = match (true) {
            $document->document_no !== null => null,
            Gate::denies('issue', $document) => 'A sua função nesta empresa não permite emitir facturas.',
            ! $this->subscription->allows($legalEntity->workspace_id, SubscriptionFeature::Invoicing) => 'O plano actual não inclui facturação. Gira o plano em facturac.ao.',
            ! $user->hasEnabledTwoFactorAuthentication() => 'Para emitir, active a autenticação em dois passos em facturac.ao.',
            ! $this->series->exists($legalEntity, $document->establishment, FiscalDocumentType::Invoice) => 'Não há série de Factura disponível.',
            default => null,
        };

        return [
            'receipt' => $this->receipt->forDocument($document, false, $legalEntity),
            'revision' => $document->revision,
            'can_issue' => $document->document_no === null && $reason === null,
            'reason' => $reason,
            'summary' => [
                'customer_name' => $document->customer_name,
                'customer_tax_identification_number' => $document->customer_tax_identification_number,
                'due_date' => $document->due_date?->toDateString(),
                'lines' => array_values($document->lines->map(fn (FiscalDocumentLine $line): array => [
                    'description' => $line->product_description,
                    'quantity' => $this->quantity($line->quantity_units, $line->quantity_scale),
                    'unit_of_measure' => $line->unit_of_measure,
                    'tax_percentage' => (string) CanonicalNumber::fromBasisPoints((int) $line->taxes->first()?->tax_rate_basis_points),
                    'gross_minor' => $line->gross_amount_minor,
                ])->all()),
                'net_total_minor' => $document->net_total_minor,
                'tax_total_minor' => $document->tax_payable_minor,
                'gross_total_minor' => $document->gross_total_minor,
                'currency_code' => $document->currency_code,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteSummary(Quote $quote): array
    {
        return [
            'customer_name' => $quote->customer_name,
            'customer_tax_identification_number' => $quote->customer_tax_identification_number,
            'due_date' => null,
            'lines' => array_values($quote->lines->map(fn (QuoteLine $line): array => [
                'description' => $line->product_description,
                'quantity' => $this->quantity($line->quantity_units, $line->quantity_scale),
                'unit_of_measure' => $line->unit_of_measure,
                'tax_percentage' => (string) $line->tax_percentage,
                'gross_minor' => $line->gross_amount_minor,
            ])->all()),
            'net_total_minor' => $quote->net_total_minor,
            'tax_total_minor' => $quote->tax_total_minor,
            'gross_total_minor' => $quote->gross_total_minor,
            'currency_code' => $quote->currency_code,
        ];
    }

    /**
     * The quote as words, for whichever app the person sends it with.
     */
    private function shareText(Quote $quote, LegalEntity $legalEntity): string
    {
        $company = $legalEntity->trade_name ?: $legalEntity->legal_name;
        $lines = $quote->lines->map(fn (QuoteLine $line): string => sprintf(
            '%s × %s: %s',
            $this->quantity($line->quantity_units, $line->quantity_scale),
            $line->product_description,
            $this->money($line->gross_amount_minor, $quote->currency_code),
        ))->implode("\n");

        return implode("\n", [
            "Orçamento {$quote->reference} de {$company}",
            "Para {$quote->customer_name}",
            '',
            $lines,
            '',
            'Total com IVA: '.$this->money($quote->gross_total_minor, $quote->currency_code),
            'Válido até '.$quote->valid_until->format('d/m/Y'),
            '',
            'Este documento não serve de factura.',
        ]);
    }

    private function quantity(int $units, int $scale): string
    {
        $number = (string) CanonicalNumber::fromScaledInteger($units, $scale);

        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }

    private function money(int $minor, string $currency): string
    {
        $whole = number_format(intdiv($minor, 100), 0, '', ' ');
        $cents = str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);

        return "{$whole},{$cents} ".($currency === 'AOA' ? 'Kz' : $currency);
    }
}
