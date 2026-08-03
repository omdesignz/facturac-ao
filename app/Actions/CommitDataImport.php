<?php

namespace App\Actions;

use App\DataImportRowStatus;
use App\DataImportStatus;
use App\DataImportType;
use App\Imports\ImportValueNormalizer;
use App\Models\CatalogueItem;
use App\Models\Customer;
use App\Models\DataImport;
use App\Models\User;
use App\Notifications\DataImportCompleted;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class CommitDataImport
{
    public function __construct(
        private readonly ImportValueNormalizer $normalizer,
    ) {}

    public function execute(DataImport $dataImport, User $user): DataImport
    {
        $completedNow = false;

        $committedImport = DB::transaction(function () use (
            $dataImport,
            $user,
            &$completedNow,
        ): DataImport {
            $lockedImport = DataImport::query()
                ->whereKey($dataImport->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedImport->status === DataImportStatus::Completed) {
                return $lockedImport;
            }

            if ($lockedImport->status !== DataImportStatus::Ready || $lockedImport->invalid_rows !== 0) {
                throw new DomainException('Valide todas as linhas antes de concluir a importação.');
            }

            if ($lockedImport->valid_rows === 0) {
                throw new DomainException('A importação não contém linhas válidas.');
            }

            $lockedImport->update(['status' => DataImportStatus::Importing]);
            $createdRows = 0;
            $updatedRows = 0;
            $importedRows = 0;

            $lockedImport->rows()
                ->where('status', DataImportRowStatus::Valid)
                ->orderBy('id')
                ->chunkById(200, function ($rows) use (
                    $lockedImport,
                    &$createdRows,
                    &$updatedRows,
                    &$importedRows,
                ): void {
                    foreach ($rows as $row) {
                        if ($row->normalized_payload === null) {
                            throw new DomainException('Uma linha validada perdeu os dados normalizados.');
                        }

                        $target = $lockedImport->type === DataImportType::Customers
                            ? $this->commitCustomer($lockedImport, $row->normalized_payload)
                            : $this->commitCatalogueItem($lockedImport, $row->normalized_payload);

                        $target->wasRecentlyCreated ? $createdRows++ : $updatedRows++;
                        $importedRows++;
                        $row->update([
                            'status' => DataImportRowStatus::Imported,
                            'target_type' => $target::class,
                            'target_id' => $target->id,
                        ]);
                    }
                });

            if ($importedRows !== $lockedImport->valid_rows) {
                throw new DomainException('A contagem final não corresponde às linhas validadas.');
            }

            $lockedImport->update([
                'status' => DataImportStatus::Completed,
                'imported_rows' => $importedRows,
                'created_rows' => $createdRows,
                'updated_rows' => $updatedRows,
                'committed_at' => now(),
            ]);

            activity('data-import')
                ->event('data-import-completed')
                ->causedBy($user)
                ->performedOn($lockedImport)
                ->withProperties([
                    'workspace_id' => $lockedImport->workspace_id,
                    'legal_entity_id' => $lockedImport->legal_entity_id,
                    'type' => $lockedImport->type->value,
                    'imported_rows' => $importedRows,
                    'created_rows' => $createdRows,
                    'updated_rows' => $updatedRows,
                    'file_sha256' => $lockedImport->sha256,
                ])
                ->log('data import committed');

            $completedNow = true;

            return $lockedImport;
        });

        if ($completedNow) {
            $this->removeSourceFile($committedImport);

            $uploader = $committedImport->uploader;

            if ($uploader instanceof User) {
                Notification::send($uploader, DataImportCompleted::fromModel($committedImport));
            }
        }

        return $committedImport->fresh();
    }

    /** @param array<string, mixed> $payload */
    private function commitCustomer(DataImport $dataImport, array $payload): Customer
    {
        return Customer::query()->updateOrCreate(
            [
                'legal_entity_id' => $dataImport->legal_entity_id,
                'tax_identification_number' => (string) $payload['tax_identification_number'],
            ],
            [
                'workspace_id' => $dataImport->workspace_id,
                'name' => (string) $payload['name'],
                'country_code' => (string) $payload['country_code'],
                'address_line' => $payload['address_line'],
                'email' => $payload['email'],
                'phone' => $payload['phone'],
                'is_active' => (bool) $payload['is_active'],
            ],
        );
    }

    /** @param array<string, mixed> $payload */
    private function commitCatalogueItem(DataImport $dataImport, array $payload): CatalogueItem
    {
        return CatalogueItem::query()->updateOrCreate(
            [
                'legal_entity_id' => $dataImport->legal_entity_id,
                'code' => (string) $payload['code'],
            ],
            [
                'workspace_id' => $dataImport->workspace_id,
                'type' => (string) $payload['type'],
                'name' => (string) $payload['name'],
                'description' => $payload['description'],
                'unit_of_measure' => (string) $payload['unit_of_measure'],
                'unit_price_minor' => $this->normalizer->moneyToMinorUnits((string) $payload['unit_price']),
                'currency_code' => (string) $payload['currency_code'],
                'tax_type' => (string) $payload['tax_type'],
                'tax_code' => $payload['tax_code'],
                'tax_percentage' => (string) $payload['tax_percentage'],
                'tax_exemption_code' => $payload['tax_exemption_code'],
                'is_active' => (bool) $payload['is_active'],
            ],
        );
    }

    private function removeSourceFile(DataImport $dataImport): void
    {
        if ($dataImport->storage_path === null) {
            return;
        }

        Storage::disk($dataImport->storage_disk)->delete($dataImport->storage_path);
        $dataImport->update(['storage_path' => null]);
    }
}
