<?php

namespace App\Fiscal\Documents;

use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\TransportDocumentStatus;
use Illuminate\Support\Facades\URL;

/**
 * What the phone is told about a transport guide: a row for the list, and the
 * whole guide for the screen a driver shows at a stop.
 */
final readonly class MobileTransportDocumentPresenter
{
    /** As long as the link the web hands a customer for an invoice. */
    public const int LINK_DAYS = FiscalDocumentPresenter::LINK_DAYS;

    /**
     * @return array<string, mixed>
     */
    public function row(TransportDocument $document): array
    {
        $document->loadMissing('establishment');
        $timezone = $document->establishment->timezone;

        return [
            'public_id' => $document->public_id,
            'document_no' => $document->document_no,
            'document_type' => $document->document_type->value,
            'document_type_label' => $document->document_type->shortLabel(),
            'status' => $document->status->value,
            'status_label' => $document->status->label(),
            'recipient_name' => $document->recipient_name,
            'destination_city' => $document->destination_city,
            'vehicle_registration' => $document->vehicle_registration,
            'movement_start_at' => $document->movement_start_at->setTimezone($timezone)->toIso8601String(),
            'gross_total_minor' => $document->gross_total_minor,
            'currency_code' => $document->currency_code,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(TransportDocument $document, bool $replayed = false): array
    {
        $document->loadMissing(['lines', 'establishment', 'legalEntity']);
        $timezone = $document->establishment->timezone;

        return [
            ...$this->row($document),
            'document_type_name' => $document->document_type->label(),
            'company_name' => $document->legalEntity->trade_name ?: $document->legalEntity->legal_name,
            'company_tax_identification_number' => $document->legalEntity->tax_identification_number,
            'recipient' => [
                'name' => $document->recipient_name,
                'tax_identification_number' => $document->recipient_tax_identification_number,
            ],
            'origin' => ['address' => $document->origin_address, 'city' => $document->origin_city],
            'destination' => ['address' => $document->destination_address, 'city' => $document->destination_city],
            'movement_end_at' => $document->movement_end_at?->setTimezone($timezone)->toIso8601String(),
            'issued_at' => $document->issued_at?->toIso8601String(),
            'notes' => $document->notes,
            'lines' => array_values($document->lines->map(fn (TransportDocumentLine $line): array => [
                'line_number' => $line->line_number,
                'product_code' => $line->product_code,
                'description' => $line->product_description,
                'quantity' => number_format($line->quantity_units / (10 ** $line->quantity_scale), $line->quantity_scale, '.', ''),
                'unit_of_measure' => $line->unit_of_measure,
                'net_amount_minor' => $line->net_amount_minor,
            ])->all()),
            'is_issued' => $document->document_no !== null,
            'share_url' => $this->shareUrl($document),
            'replayed' => $replayed,
        ];
    }

    /**
     * The guide's PDF behind a signed link, the code shown at a checkpoint. A
     * draft has no number and nothing to show; a cancelled guide still opens,
     * stamped ANULADO, so whoever reads it sees that it no longer counts.
     */
    public function shareUrl(TransportDocument $document): ?string
    {
        if ($document->document_no === null || $document->status === TransportDocumentStatus::Draft) {
            return null;
        }

        return URL::temporarySignedRoute(
            'transport-documents.shared',
            now()->addDays(self::LINK_DAYS),
            ['transportDocument' => $document->public_id],
        );
    }
}
