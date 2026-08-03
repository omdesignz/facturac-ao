<?php

namespace App;

enum FiscalTaxType: string
{
    case Vat = 'IVA';
    case StampDuty = 'IS';
    case ExciseDuty = 'IEC';
    case SpecialConsumptionContribution = 'CEOC';
    case NotSubject = 'NS';

    public function label(): string
    {
        return match ($this) {
            self::Vat => 'Imposto sobre o Valor Acrescentado',
            self::StampDuty => 'Imposto de Selo',
            self::ExciseDuty => 'Imposto Especial de Consumo',
            self::SpecialConsumptionContribution => 'Contribuição Especial de Consumo',
            self::NotSubject => 'Não sujeito',
        };
    }
}
