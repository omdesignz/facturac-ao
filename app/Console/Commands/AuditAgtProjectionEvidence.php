<?php

namespace App\Console\Commands;

use App\Fiscal\Documents\CurrentAgtState;
use App\Models\FiscalDocument;
use Illuminate\Console\Command;

class AuditAgtProjectionEvidence extends Command
{
    protected $signature = 'agt:projection-audit';

    protected $description = 'Read-only restricted inventory of unsupported receipt source evidence';

    public function handle(): int
    {
        $blocked = 0;
        $affectedDrafts = [];
        FiscalDocument::query()->where('status', '!=', 'draft')->select(['id', 'public_id', 'status', 'workspace_id', 'legal_entity_id', 'environment'])
            ->with('submissions')->orderBy('id')->chunkById(100, function ($documents) use (&$blocked, &$affectedDrafts): void {
                foreach ($documents as $document) {
                    if (CurrentAgtState::acceptanceEvidenceSatisfied($document)) {
                        continue;
                    }
                    $blocked++;
                    $state = CurrentAgtState::projection($document->submissions->first());
                    $this->line(json_encode(['document' => $document->public_id, 'workspace_id' => $document->workspace_id,
                        'legal_entity_id' => $document->legal_entity_id, 'environment' => $document->environment,
                        'category' => $state['classification']], JSON_THROW_ON_ERROR));
                    foreach ($document->settledBy()->whereHas('receipt', fn ($receipt) => $receipt->where('status', 'draft'))->pluck('fiscal_document_id') as $id) {
                        $affectedDrafts[$id] = true;
                    }
                }
            });
        $this->info(json_encode(['blocked_sources' => $blocked, 'affected_draft_receipts' => count($affectedDrafts)], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
