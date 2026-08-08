<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\FiscalDocumentStatus;
use App\Models\FiscalDocument;
use App\Models\User;
use App\Notifications\FiscalDocumentIssued;
use Illuminate\Support\Facades\Notification;

/**
 * Emails an issued document to its customer.
 *
 * Refuses to send a draft: a draft has no number, no signature and no AGT
 * acknowledgement, so what would arrive is a document the customer cannot rely
 * on and might try to pay against.
 */
class SendFiscalDocumentToCustomer
{
    public function execute(FiscalDocument $document, ?User $actor = null): void
    {
        if ($document->status === FiscalDocumentStatus::Draft) {
            throw BillingActionRefused::because(
                'Só um documento emitido pode ser enviado ao cliente.',
            );
        }

        $email = $this->recipient($document);

        if ($email === null) {
            throw BillingActionRefused::because(
                'Este cliente não tem email registado. Adicione um na ficha do cliente.',
            );
        }

        Notification::route('mail', $email)
            ->notify(FiscalDocumentIssued::fromModel($document->load('legalEntity')));

        /*
         * Written straight to the row: an issued document is immutable by
         * design, and rightly refuses a save(). Who it was emailed to is a
         * delivery note about the document, not part of the document, so it
         * does not belong behind that guard.
         */
        FiscalDocument::query()->whereKey($document->id)->update([
            'sent_to_customer_at' => now(),
            'sent_to_email' => $email,
            'send_count' => $document->send_count + 1,
        ]);

        $document->refresh();

        activity('fiscal-document')
            ->causedBy($actor)
            ->performedOn($document)
            ->event('sent-to-customer')
            ->withProperties([
                'document_no' => $document->document_no,
                'email' => $email,
                'send_count' => $document->send_count,
            ])
            ->log('document emailed to customer');
    }

    /** Whether this document would be sent without anyone asking. */
    public function shouldSendAutomatically(FiscalDocument $document): bool
    {
        return $document->customer?->auto_send_documents === true
            && $this->recipient($document) !== null;
    }

    private function recipient(FiscalDocument $document): ?string
    {
        $email = $document->customer?->email;

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }
}
