<?php

namespace App;

enum DataImportSource: string
{
    case Excel = 'excel';
    case Csv = 'csv';
    case OtherApplication = 'other_application';

    public function label(): string
    {
        return match ($this) {
            self::Excel => 'Microsoft Excel',
            self::Csv => 'Ficheiro CSV',
            self::OtherApplication => 'Exportação de outra aplicação',
        };
    }
}
