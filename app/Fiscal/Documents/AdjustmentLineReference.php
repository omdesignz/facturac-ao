<?php

namespace App\Fiscal\Documents;

use App\Models\FiscalDocument;
use DomainException;

final class AdjustmentLineReference
{
    public function resolve(FiscalDocument $document, ?int $lineNumber): int
    {
        $source = FiscalDocument::query()
            ->whereKey($document->references_document_id)
            ->where('workspace_id', $document->workspace_id)
            ->where('legal_entity_id', $document->legal_entity_id)
            ->where('document_no', $document->references_document_no)
            ->first();

        if ($lineNumber === null || $lineNumber < 1
            || $source === null || $source->isMutable()
            || $source->system_entry_at === null || blank($source->document_no)
            || (($document->environment ?? 'unresolved') !== 'unresolved' && $document->environment !== $source->environment)
            || ! $source->lines()->where('workspace_id', $document->workspace_id)
                ->where('legal_entity_id', $document->legal_entity_id)
                ->where('line_number', $lineNumber)->exists()) {
            throw new DomainException('Seleccione uma linha válida do documento original emitido.');
        }

        return $lineNumber;
    }
}
