<?php

namespace App\Actions;

use App\DataImportStatus;
use App\Imports\FirstWorksheetImport;
use App\Imports\ImportSchema;
use App\Imports\StagedWorksheetImport;
use App\Models\DataImport;
use DomainException;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;
use Throwable;

class StageDataImport
{
    public const int MaximumRows = 10000;

    public const int MaximumColumns = 100;

    public function __construct(
        private readonly ImportSchema $schema,
    ) {}

    public function execute(DataImport $dataImport): DataImport
    {
        if ($dataImport->storage_path === null) {
            throw new DomainException('O ficheiro de importação não está disponível.');
        }

        try {
            $headings = (new HeadingRowImport)->toArray(
                $dataImport->storage_path,
                $dataImport->storage_disk,
            );
            $rawHeaders = $headings[0][0] ?? null;

            if (! is_array($rawHeaders) || $rawHeaders === []) {
                throw new DomainException('O ficheiro deve começar com uma linha de cabeçalhos.');
            }

            if (count($rawHeaders) > self::MaximumColumns) {
                throw new DomainException('O ficheiro excede o limite de 100 colunas.');
            }

            if (collect($rawHeaders)->contains(fn (mixed $header): bool => ! is_string($header) || trim($header) === '')) {
                throw new DomainException('Todas as colunas preenchidas devem ter um cabeçalho único.');
            }

            $headers = [];

            foreach ($rawHeaders as $rawHeader) {
                $headers[] = trim($rawHeader);
            }

            if (count(array_unique($headers)) !== count($headers)) {
                throw new DomainException('Existem cabeçalhos duplicados. Renomeie as colunas e tente novamente.');
            }

            $dataImport->rows()->delete();
            $worksheetImport = new StagedWorksheetImport($dataImport, self::MaximumRows);

            Excel::import(
                new FirstWorksheetImport($worksheetImport),
                $dataImport->storage_path,
                $dataImport->storage_disk,
            );
            $worksheetImport->flush();

            $rowCount = $dataImport->rows()->count();

            if ($rowCount === 0) {
                throw new DomainException('O ficheiro não contém linhas de dados para importar.');
            }

            if ($rowCount > self::MaximumRows) {
                throw new DomainException('O ficheiro excede o limite de 10 000 linhas por importação.');
            }

            $dataImport->update([
                'status' => DataImportStatus::AwaitingMapping,
                'headers' => $headers,
                'column_mapping' => $this->schema->suggestedMapping($dataImport->type, $headers),
                'total_rows' => $rowCount,
                'valid_rows' => 0,
                'invalid_rows' => 0,
                'imported_rows' => 0,
                'created_rows' => 0,
                'updated_rows' => 0,
                'failure_code' => null,
                'failure_message' => null,
                'mapped_at' => null,
                'validated_at' => null,
            ]);
        } catch (Throwable $exception) {
            $safeMessage = $exception instanceof DomainException
                ? $exception->getMessage()
                : 'Não foi possível ler o ficheiro. Confirme o formato e tente novamente.';

            $dataImport->rows()->delete();
            $dataImport->update([
                'status' => DataImportStatus::Failed,
                'total_rows' => 0,
                'failure_code' => $exception instanceof DomainException
                    ? 'invalid_import_file'
                    : 'reader_failure',
                'failure_message' => $safeMessage,
            ]);
            Storage::disk($dataImport->storage_disk)->delete($dataImport->storage_path);
            $dataImport->update(['storage_path' => null]);

            throw $exception;
        }

        return $dataImport->fresh();
    }
}
