<?php

namespace App\Fiscal;

use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\FiscalDocument;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

enum CommandCapability: string
{
    case CustomerCreate = 'customers.create';
    case ServiceCreate = 'catalogue.services.create';
    case DocumentIssue = 'documents.issue';

    public function scope(): string
    {
        return match ($this) {
            self::CustomerCreate => 'customers:create', self::ServiceCreate => 'catalogue:services:create', self::DocumentIssue => 'documents:issue',
        };
    }

    public function creationEvent(): string
    {
        return match ($this) {
            self::CustomerCreate => 'customer.created', self::ServiceCreate => 'catalogue.service.created', self::DocumentIssue => 'fiscal-document.issued',
        };
    }

    public function subjectClass(): string
    {
        return match ($this) {
            self::CustomerCreate => Customer::class, self::ServiceCreate => CatalogueItem::class, self::DocumentIssue => FiscalDocument::class,
        };
    }

    /** The activity log a created subject is recorded in. */
    public function subjectLog(): string
    {
        return match ($this) {
            self::CustomerCreate => 'customer', self::ServiceCreate => 'catalogue-item', self::DocumentIssue => 'fiscal-document',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CustomerCreate => 'Customer command', self::ServiceCreate => 'Catalogue service command', self::DocumentIssue => 'Document issue command',
        };
    }

    public function conflict(): string
    {
        return match ($this) {
            self::CustomerCreate => 'CUSTOMER_CONFLICT', self::ServiceCreate => 'CATALOGUE_CONFLICT', self::DocumentIssue => 'DOCUMENT_CONFLICT',
        };
    }

    /**
     * Whether a database error is the natural-key collision this command reports
     * as its conflict. Issuing a document has no caller-chosen natural key.
     */
    public function isConflict(QueryException $exception): bool
    {
        [$constraint, $sqlite] = match ($this) {
            self::CustomerCreate => ['customers_entity_tax_id_unique', 'UNIQUE constraint failed: customers.legal_entity_id, customers.tax_identification_number'],
            self::ServiceCreate => ['catalogue_items_entity_code_unique', 'UNIQUE constraint failed: catalogue_items.legal_entity_id, catalogue_items.code'],
            self::DocumentIssue => [null, null],
        };
        if ($constraint === null) {
            return false;
        }
        $detail = (string) ($exception->errorInfo[2] ?? '');

        return (($exception->errorInfo[0] ?? '') === '23505' && str_contains($detail, '"'.$constraint.'"'))
            || (DB::getDriverName() === 'sqlite' && str_contains($detail, $sqlite));
    }
}
