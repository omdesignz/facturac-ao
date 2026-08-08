<?php

namespace App;

enum StockMovementType: string
{
    case Opening = 'opening';
    case Purchase = 'purchase';
    case Sale = 'sale';
    case SaleReturn = 'sale_return';
    case Adjustment = 'adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case WriteOff = 'write_off';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Saldo inicial',
            self::Purchase => 'Entrada por compra',
            self::Sale => 'Saída por venda',
            self::SaleReturn => 'Devolução de cliente',
            self::Adjustment => 'Acerto de inventário',
            self::TransferOut => 'Transferência (saída)',
            self::TransferIn => 'Transferência (entrada)',
            self::WriteOff => 'Quebra ou perda',
        };
    }

    /** Whether this kind of movement can only ever add stock. */
    public function isInbound(): bool
    {
        return in_array($this, [
            self::Opening,
            self::Purchase,
            self::SaleReturn,
            self::TransferIn,
        ], true);
    }

    /** Whether it can only ever remove stock. */
    public function isOutbound(): bool
    {
        return in_array($this, [
            self::Sale,
            self::TransferOut,
            self::WriteOff,
        ], true);
    }

    /**
     * Raised by the fiscal pipeline rather than by a person, so it must not be
     * offered in the manual entry form.
     */
    public function isAutomatic(): bool
    {
        return in_array($this, [self::Sale, self::SaleReturn], true);
    }

    /**
     * Whether a unit cost is expected. Outbound movements are valued at the
     * running weighted average, so asking for a cost would be misleading.
     */
    public function carriesCost(): bool
    {
        return in_array($this, [self::Opening, self::Purchase, self::TransferIn], true);
    }

    /** The types a person may record by hand. */
    /** @return list<self> */
    public static function manual(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type): bool => ! $type->isAutomatic(),
        ));
    }
}
