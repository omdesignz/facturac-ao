<?php

namespace App;

enum SubscriptionFeature: string
{
    case Invoicing = 'invoicing';
    case Pos = 'pos';
    case Inventory = 'inventory';
    case Transport = 'transport';
    case Recurring = 'recurring';
    case Reports = 'reports';
    case Integrations = 'integrations';

    public function label(): string
    {
        return match ($this) {
            self::Invoicing => 'Facturação, clientes, catálogo e orçamentos',
            self::Pos => 'Ponto de venda e talões de venda',
            self::Inventory => 'Inventário e movimentos de stock',
            self::Transport => 'Guias de transporte',
            self::Recurring => 'Avenças e facturação recorrente',
            self::Reports => 'Relatórios personalizados em Excel',
            self::Integrations => 'API e integrações externas',
        };
    }

    public function key(): string
    {
        return 'feature_'.$this->value;
    }
}
