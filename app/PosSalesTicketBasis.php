<?php

namespace App;

/** Confirmed categories in article 5 of Decreto Presidencial 71/25. */
enum PosSalesTicketBasis: string
{
    case VendingMachine = 'vending_machine';
    case TicketedService = 'ticketed_service';
    case LicensedVendor = 'licensed_vendor';

    public function label(): string
    {
        return match ($this) {
            self::VendingMachine => 'Bens vendidos por máquina automática',
            self::TicketedService => 'Serviço com bilhete, senha ou portagem',
            self::LicensedVendor => 'Bens vendidos por ambulante ou feirante autorizado',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $basis): array => [
            'value' => $basis->value,
            'label' => $basis->label(),
        ], self::cases());
    }
}
