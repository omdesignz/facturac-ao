<?php

namespace App;

enum FiscalDocumentType: string
{
    case AdvanceInvoice = 'FA';
    case Invoice = 'FT';
    case InvoiceReceipt = 'FR';
    case GlobalInvoice = 'FG';
    case GenericInvoice = 'GF';
    case CollectionNotice = 'AC';
    case CollectionNoticeReceipt = 'AR';
    case SalesTicket = 'TV';
    case IssuedReceipt = 'RC';
    case Receipt = 'RG';
    case ReversalReceipt = 'RE';
    case DebitNote = 'ND';
    case CreditNote = 'NC';
    case SelfBillingInvoiceReceipt = 'AF';
    case PremiumReceipt = 'RP';
    case AcceptedReinsurance = 'RA';
    case CoInsuranceAllocation = 'CS';
    case LeadCoInsurerAllocation = 'LD';

    public function label(): string
    {
        return match ($this) {
            self::AdvanceInvoice => 'Factura de adiantamento',
            self::Invoice => 'Factura',
            self::InvoiceReceipt => 'Factura/Recibo',
            self::GlobalInvoice => 'Factura global',
            self::GenericInvoice => 'Factura genérica',
            self::CollectionNotice => 'Aviso de cobrança',
            self::CollectionNoticeReceipt => 'Aviso de cobrança/Recibo',
            self::SalesTicket => 'Talão de venda',
            self::IssuedReceipt => 'Recibo emitido',
            self::Receipt => 'Recibo',
            self::ReversalReceipt => 'Estorno ou recibo de estorno',
            self::DebitNote => 'Nota de débito',
            self::CreditNote => 'Nota de crédito',
            self::SelfBillingInvoiceReceipt => 'Factura/Recibo de autofacturação',
            self::PremiumReceipt => 'Prémio ou recibo de prémio',
            self::AcceptedReinsurance => 'Resseguro aceite',
            self::CoInsuranceAllocation => 'Imputação a co-seguradoras',
            self::LeadCoInsurerAllocation => 'Imputação a co-seguradora líder',
        };
    }

    /**
     * Grammatical gender of the label, so the UI can agree its article:
     * «uma factura» and «uma nota» but «um recibo».
     */
    public function isFeminine(): bool
    {
        return match ($this) {
            self::AdvanceInvoice, self::Invoice, self::InvoiceReceipt,
            self::GlobalInvoice, self::GenericInvoice, self::DebitNote,
            self::CreditNote, self::SelfBillingInvoiceReceipt,
            self::CoInsuranceAllocation, self::LeadCoInsurerAllocation => true,
            default => false,
        };
    }

    /**
     * Adjustment documents correct an earlier one. The AGT requires both the
     * corrected document and a reason to accompany them.
     */
    public function isAdjustment(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote], true);
    }

    /**
     * A credit note gives value back to the customer; a debit note charges more.
     */
    public function reducesReceivable(): bool
    {
        return $this === self::CreditNote;
    }

    /**
     * Records a payment, so it must state the method and the amount.
     */
    public function isReceipt(): bool
    {
        return in_array($this, [
            self::InvoiceReceipt,
            self::IssuedReceipt,
            self::Receipt,
            self::CollectionNoticeReceipt,
        ], true);
    }

    /**
     * A standalone receipt settles earlier invoices rather than carrying goods
     * of its own. FR is the exception: it invoices and is paid in one document.
     */
    public function settlesOtherDocuments(): bool
    {
        return $this->isReceipt() && ! $this->requiresLines();
    }

    /**
     * The types this application can currently issue end to end.
     *
     * @return list<self>
     */
    public static function issuable(): array
    {
        return [
            self::Invoice,
            self::InvoiceReceipt,
            self::GenericInvoice,
            self::IssuedReceipt,
            self::CreditNote,
            self::DebitNote,
        ];
    }

    /**
     * AGT requires the date of the underlying operation on every FG/GF line.
     */
    public function requiresLineOperationDate(): bool
    {
        return in_array($this, [self::GlobalInvoice, self::GenericInvoice], true);
    }

    /**
     * How issuing this document moves stock, if at all.
     *
     * A debit note charges more for goods already delivered, so it moves no
     * stock; a credit note is how a return is documented, so it puts stock back.
     * Receipts settle money, never goods.
     */
    public function stockEffect(): ?StockMovementType
    {
        return match ($this) {
            self::Invoice,
            self::InvoiceReceipt,
            self::GlobalInvoice,
            self::GenericInvoice,
            self::SalesTicket => StockMovementType::Sale,
            self::CreditNote => StockMovementType::SaleReturn,
            default => null,
        };
    }

    public function requiresLines(): bool
    {
        return ! in_array($this, [
            self::CollectionNoticeReceipt,
            self::IssuedReceipt,
            self::Receipt,
        ], true);
    }
}
