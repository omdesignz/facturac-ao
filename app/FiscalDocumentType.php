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

    public function requiresLines(): bool
    {
        return ! in_array($this, [
            self::CollectionNoticeReceipt,
            self::IssuedReceipt,
            self::Receipt,
        ], true);
    }
}
