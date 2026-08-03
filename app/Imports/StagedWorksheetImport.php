<?php

namespace App\Imports;

use App\DataImportRowStatus;
use App\Models\DataImport;
use App\Models\DataImportRow;
use DateTimeInterface;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithLimit;
use Maatwebsite\Excel\Row;
use Stringable;

class StagedWorksheetImport implements OnEachRow, SkipsEmptyRows, WithChunkReading, WithHeadingRow, WithLimit
{
    private const int BufferSize = 250;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    public function __construct(
        private readonly DataImport $dataImport,
        private readonly int $maximumRows,
    ) {}

    public function onRow(Row $row): void
    {
        $payload = collect($row->toArray())
            ->mapWithKeys(fn (mixed $value, int|string $key): array => [
                (string) $key => $this->serializableValue($value),
            ])
            ->all();
        $model = new DataImportRow;
        $now = now();

        $model->forceFill([
            'data_import_id' => $this->dataImport->id,
            'workspace_id' => $this->dataImport->workspace_id,
            'legal_entity_id' => $this->dataImport->legal_entity_id,
            'row_number' => $row->getIndex(),
            'status' => DataImportRowStatus::Pending,
            'source_payload' => $payload,
            'normalized_payload' => null,
            'validation_errors' => null,
            'target_type' => null,
            'target_id' => null,
        ]);
        $model->setCreatedAt($now);
        $model->setUpdatedAt($now);
        $this->buffer[] = $model->getAttributes();

        if (count($this->buffer) >= self::BufferSize) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->buffer === []) {
            return;
        }

        DataImportRow::query()->insert($this->buffer);
        $this->buffer = [];
    }

    public function chunkSize(): int
    {
        return 500;
    }

    public function limit(): int
    {
        return $this->maximumRows + 2;
    }

    private function serializableValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Stringable) {
            return Str::limit((string) $value, 10000, '');
        }

        return null;
    }
}
