<?php

namespace App\Fiscal;

use App\Models\CatalogueItem;
use App\Models\Customer;

enum CommandCapability: string
{
    case CustomerCreate = 'customers.create';
    case ServiceCreate = 'catalogue.services.create';

    public function scope(): string
    {
        return match ($this) {
            self::CustomerCreate => 'customers:create', self::ServiceCreate => 'catalogue:services:create'
        };
    }

    public function creationEvent(): string
    {
        return match ($this) {
            self::CustomerCreate => 'customer.created', self::ServiceCreate => 'catalogue.service.created'
        };
    }

    public function subjectClass(): string
    {
        return match ($this) {
            self::CustomerCreate => Customer::class, self::ServiceCreate => CatalogueItem::class
        };
    }

    public function conflict(): string
    {
        return match ($this) {
            self::CustomerCreate => 'CUSTOMER_CONFLICT', self::ServiceCreate => 'CATALOGUE_CONFLICT'
        };
    }
}
