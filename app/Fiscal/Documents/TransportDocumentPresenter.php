<?php

namespace App\Fiscal\Documents;

use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\TransportDocumentType;

final class TransportDocumentPresenter
{
    /** @return array<string, mixed> */
    public function forForm(TransportDocument $document): array
    {
        $document->loadMissing(['lines.catalogueItem', 'establishment', 'customer']);

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->label(),
            'party_label' => $document->document_type === TransportDocumentType::ReturnNote
                ? 'Fornecedor'
                : 'Destinatário',
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'is_editable' => $document->isMutable(),
            'can_issue' => $document->isMutable(),
            'can_cancel' => $document->canCancel(),
            'revision' => $document->revision,
            'establishment_public_id' => $document->establishment->public_id,
            'customer_public_id' => $document->customer?->public_id,
            'movement_date' => $document->movement_date->toDateString(),
            'movement_start_at' => $document->movement_start_at
                ->setTimezone($document->establishment->timezone)
                ->format('Y-m-d H:i'),
            'movement_end_at' => $document->movement_end_at
                ?->setTimezone($document->establishment->timezone)
                ->format('Y-m-d H:i'),
            'recipient' => [
                'name' => $document->recipient_name,
                'tax_identification_number' => $document->recipient_tax_identification_number,
                'country_code' => $document->recipient_country_code,
                'address' => $document->recipient_address,
                'city' => $document->recipient_city,
                'province' => $document->recipient_province,
            ],
            'origin' => $this->address($document, 'origin'),
            'destination' => $this->address($document, 'destination'),
            'transporter' => [
                'name' => $document->transporter_name,
                'tax_identification_number' => $document->transporter_tax_identification_number,
                'vehicle_registration' => $document->vehicle_registration,
            ],
            'gross_weight_kg' => $document->gross_weight_grams === null
                ? null
                : $this->decimal($document->gross_weight_grams, 3),
            'package_count' => $document->package_count,
            'notes' => $document->notes,
            'cancellation_reason' => $document->cancellation_reason,
            'currency_code' => $document->currency_code,
            'gross_total_minor' => $document->gross_total_minor,
            'document_hash' => $document->document_hash,
            'issued_at' => $document->issued_at?->toIso8601String(),
            'cancelled_at' => $document->cancelled_at?->toIso8601String(),
            'lines' => array_values($document->lines->map(
                fn (TransportDocumentLine $line): array => [
                    'line_number' => $line->line_number,
                    'catalogue_item_public_id' => $line->catalogueItem?->public_id,
                    'product_code' => $line->product_code,
                    'product_description' => $line->product_description,
                    'quantity' => $this->decimal($line->quantity_units, $line->quantity_scale),
                    'unit_of_measure' => $line->unit_of_measure,
                    'unit_price' => $this->decimal($line->unit_price_minor, 2),
                    'net_amount_minor' => $line->net_amount_minor,
                ],
            )->all()),
        ];
    }

    /** @return array<string, mixed> */
    public function forPrint(TransportDocument $document): array
    {
        $document->loadMissing(['lines', 'legalEntity', 'establishment']);

        return [
            ...$this->forForm($document),
            'company' => [
                'legal_name' => $document->legalEntity->legal_name,
                'trade_name' => $document->legalEntity->trade_name,
                'tax_identification_number' => $document->legalEntity->tax_identification_number,
                'address' => $document->establishment->address_line,
                'city' => $document->establishment->municipality,
                'province' => $document->establishment->province_code,
            ],
            'establishment' => [
                'name' => $document->establishment->name,
                'code' => $document->establishment->code,
            ],
            'authenticity' => [
                'software_product_id' => $document->software_product_id,
                'software_validation_number' => $document->software_validation_number,
                'hash_control' => $document->hash_control,
                'digest' => $document->document_hash === null
                    ? null
                    : substr($document->document_hash, 0, (int) config('fiscal.print.digest_characters', 8)),
            ],
        ];
    }

    /** @return array{address: string, city: string, province: string|null, country_code: string} */
    private function address(TransportDocument $document, string $prefix): array
    {
        return [
            'address' => (string) $document->getAttribute("{$prefix}_address"),
            'city' => (string) $document->getAttribute("{$prefix}_city"),
            'province' => $document->getAttribute("{$prefix}_province"),
            'country_code' => (string) $document->getAttribute("{$prefix}_country_code"),
        ];
    }

    private function decimal(int $units, int $scale): string
    {
        return number_format($units / (10 ** $scale), $scale, '.', '');
    }
}
