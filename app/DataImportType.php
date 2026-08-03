<?php

namespace App;

enum DataImportType: string
{
    case Customers = 'customers';
    case CatalogueItems = 'catalogue_items';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Clientes',
            self::CatalogueItems => 'Produtos e serviços',
        };
    }

    public function singularLabel(): string
    {
        return match ($this) {
            self::Customers => 'cliente',
            self::CatalogueItems => 'item de catálogo',
        };
    }
}
