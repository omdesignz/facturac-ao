<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use App\Models\TransportDocument;
use App\Models\TransportDocumentLine;
use App\TransportDocumentStatus;
use Illuminate\Validation\ValidationException;

final class TransportDocumentOrigins
{
    public function resolve(FiscalDocument $invoice, ?string $publicId, int $index): ?TransportDocumentLine
    {
        if (! filled($publicId)) {
            return null;
        }
        $line = TransportDocumentLine::query()
            ->where('workspace_id', $invoice->workspace_id)
            ->where('legal_entity_id', $invoice->legal_entity_id)
            ->where('public_id', $publicId)->first();
        $guide = $line === null ? null : TransportDocument::query()
            ->whereKey($line->transport_document_id)->lockForUpdate()->first();

        if ($guide === null || $guide->status !== TransportDocumentStatus::Issued
            || $guide->movement_direction !== 'outgoing'
            || $guide->movement_date->isAfter($invoice->document_date)
            || $guide->recipient_tax_identification_number !== $invoice->customer_tax_identification_number
            || ! in_array($invoice->document_type->value, ['FT', 'FR', 'FA', 'FG', 'GF', 'TV'], true)) {
            throw ValidationException::withMessages([
                "lines.{$index}.origin_transport_line_public_id" => 'Seleccione uma linha de guia emitida, de saída, do mesmo cliente e com data não posterior à factura.',
            ]);
        }

        return $line->setRelation('transportDocument', $guide);
    }

    public function assertIssuable(FiscalDocument $invoice): void
    {
        $invoice->loadMissing('lines.originTransportLine');
        foreach ($invoice->lines as $index => $line) {
            if ($line->origin_transport_line_id !== null) {
                $this->resolve($invoice, $line->originTransportLine?->public_id, $index);
            }
        }
    }
}
