<?php

namespace App\Actions;

use App\Exceptions\BillingActionRefused;
use App\Models\TransportDocument;
use App\Models\User;
use App\TransportDocumentStatus;
use Illuminate\Support\Facades\DB;

final class CancelTransportDocument
{
    public function execute(
        TransportDocument $transportDocument,
        User $actor,
        string $reason,
    ): TransportDocument {
        return DB::transaction(function () use ($transportDocument, $actor, $reason): TransportDocument {
            $document = TransportDocument::query()
                ->whereKey($transportDocument->id)
                ->where('workspace_id', $transportDocument->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $document->canCancel()) {
                throw BillingActionRefused::because('Esta guia já não pode ser anulada.');
            }

            $document->forceFill([
                'status' => TransportDocumentStatus::Cancelled,
                'cancelled_by_user_id' => $actor->id,
                'cancellation_reason' => trim($reason),
                'cancelled_at' => now('Africa/Luanda'),
            ])->save();

            activity('transport-document')
                ->causedBy($actor)
                ->performedOn($document)
                ->event('cancelled')
                ->withProperties([
                    'document_no' => $document->document_no,
                    'reason' => $document->cancellation_reason,
                ])
                ->log('transport document cancelled');

            return $document;
        }, 5);
    }
}
