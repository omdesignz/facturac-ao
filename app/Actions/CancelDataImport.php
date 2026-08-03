<?php

namespace App\Actions;

use App\DataImportStatus;
use App\Models\DataImport;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CancelDataImport
{
    public function execute(DataImport $dataImport, User $user): DataImport
    {
        $path = null;
        $disk = null;

        $cancelledImport = DB::transaction(function () use (
            $dataImport,
            $user,
            &$path,
            &$disk,
        ): DataImport {
            $lockedImport = DataImport::query()
                ->whereKey($dataImport->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedImport->status, [
                DataImportStatus::AwaitingMapping,
                DataImportStatus::Ready,
                DataImportStatus::HasErrors,
                DataImportStatus::Failed,
            ], true)) {
                throw new DomainException('Esta importação já não pode ser cancelada.');
            }

            $path = $lockedImport->storage_path;
            $disk = $lockedImport->storage_disk;
            $lockedImport->update([
                'status' => DataImportStatus::Cancelled,
                'storage_path' => null,
                'cancelled_at' => now(),
            ]);

            activity('data-import')
                ->event('data-import-cancelled')
                ->causedBy($user)
                ->performedOn($lockedImport)
                ->withProperties([
                    'workspace_id' => $lockedImport->workspace_id,
                    'file_sha256' => $lockedImport->sha256,
                ])
                ->log('data import cancelled');

            return $lockedImport;
        });

        if (is_string($path) && is_string($disk)) {
            Storage::disk($disk)->delete($path);
        }

        return $cancelledImport->fresh();
    }
}
