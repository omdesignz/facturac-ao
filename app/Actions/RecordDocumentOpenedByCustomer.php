<?php

namespace App\Actions;

use App\Models\FiscalDocument;
use App\Notifications\DocumentOpenedByCustomer;
use Illuminate\Support\Facades\Cache;

/**
 * Notes that the customer opened the document from the link in their email.
 *
 * Best effort and deliberately quiet: a mail scanner that fetches the link
 * looks the same as a person, so a document counts as opened at most once an
 * hour, and the company is told only the first time.
 */
class RecordDocumentOpenedByCustomer
{
    public function execute(FiscalDocument $document): void
    {
        if (! Cache::add("document-opened:{$document->id}", true, 3600)) {
            return;
        }

        $first = $document->first_opened_by_customer_at === null;

        // Written straight to the row, as the delivery note is: an issued
        // document refuses a save(), and this is about the sending, not the
        // document.
        FiscalDocument::query()->whereKey($document->id)->update([
            'first_opened_by_customer_at' => $document->first_opened_by_customer_at ?? now(),
            'last_opened_by_customer_at' => now(),
            'open_count' => $document->open_count + 1,
        ]);

        if ($first && $document->workspace !== null) {
            DocumentOpenedByCustomer::fromDocument($document)->sendToWorkspace($document->workspace);
        }
    }
}
