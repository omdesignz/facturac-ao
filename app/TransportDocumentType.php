<?php

namespace App;

/**
 * Document types accepted by SAF-T (AO) MovementOfGoods.
 */
enum TransportDocumentType: string
{
    case DeliveryNote = 'GR';
    case TransportGuide = 'GT';
    case OwnAssets = 'GA';
    case Consignment = 'GC';
    case ReturnNote = 'GD';

    public function label(): string
    {
        return match ($this) {
            self::DeliveryNote => 'Guia de remessa',
            self::TransportGuide => 'Guia de transporte',
            self::OwnAssets => 'Guia de movimentação de activos próprios',
            self::Consignment => 'Guia de consignação',
            self::ReturnNote => 'Guia ou nota de devolução',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::DeliveryNote => 'Remessa',
            self::TransportGuide => 'Transporte',
            self::OwnAssets => 'Activos próprios',
            self::Consignment => 'Consignação',
            self::ReturnNote => 'Devolução',
        };
    }
}
