<?php

namespace App\Actions;

use App\DataImportRowStatus;
use App\DataImportStatus;
use App\DataImportType;
use App\Imports\ImportSchema;
use App\Imports\ImportValueNormalizer;
use App\Models\DataImport;
use App\Models\DataImportRow;
use Closure;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as LaravelValidator;

class ValidateDataImport
{
    public function __construct(
        private readonly ImportSchema $schema,
        private readonly ImportValueNormalizer $normalizer,
    ) {}

    /**
     * @param  array<string, string>  $mapping
     */
    public function execute(DataImport $dataImport, array $mapping): DataImport
    {
        if (! in_array($dataImport->status, [
            DataImportStatus::AwaitingMapping,
            DataImportStatus::Ready,
            DataImportStatus::HasErrors,
        ], true)) {
            throw new DomainException('Esta importação já não pode ser novamente validada.');
        }

        $this->assertMappingIsSafe($dataImport, $mapping);

        return DB::transaction(function () use ($dataImport, $mapping): DataImport {
            $validRows = 0;
            $invalidRows = 0;
            $seenIdentifiers = [];

            $dataImport->rows()
                ->orderBy('id')
                ->chunkById(250, function ($rows) use (
                    $dataImport,
                    $mapping,
                    &$validRows,
                    &$invalidRows,
                    &$seenIdentifiers,
                ): void {
                    foreach ($rows as $row) {
                        $normalized = $this->normalizeRow($dataImport, $row, $mapping);
                        $validator = $this->validator($dataImport->type, $normalized);
                        $validator->fails();
                        $identifier = $this->identifier($dataImport->type, $normalized);

                        if ($identifier !== '' && isset($seenIdentifiers[$identifier])) {
                            $validator->errors()->add(
                                $dataImport->type === DataImportType::Customers
                                    ? 'tax_identification_number'
                                    : 'code',
                                "Este identificador já aparece na linha {$seenIdentifiers[$identifier]}.",
                            );
                        } elseif ($identifier !== '') {
                            $seenIdentifiers[$identifier] = $row->row_number;
                        }

                        if ($dataImport->type === DataImportType::CatalogueItems) {
                            $this->validateTaxTreatment($validator, $normalized);
                        }

                        if ($validator->errors()->isNotEmpty()) {
                            $invalidRows++;
                            $row->update([
                                'status' => DataImportRowStatus::Invalid,
                                'normalized_payload' => $normalized,
                                'validation_errors' => $validator->errors()->messages(),
                                'target_type' => null,
                                'target_id' => null,
                            ]);

                            continue;
                        }

                        $validRows++;
                        $row->update([
                            'status' => DataImportRowStatus::Valid,
                            'normalized_payload' => $normalized,
                            'validation_errors' => null,
                            'target_type' => null,
                            'target_id' => null,
                        ]);
                    }
                });

            $dataImport->update([
                'status' => $invalidRows === 0
                    ? DataImportStatus::Ready
                    : DataImportStatus::HasErrors,
                'column_mapping' => $mapping,
                'valid_rows' => $validRows,
                'invalid_rows' => $invalidRows,
                'imported_rows' => 0,
                'created_rows' => 0,
                'updated_rows' => 0,
                'mapped_at' => now(),
                'validated_at' => now(),
                'failure_code' => null,
                'failure_message' => null,
            ]);

            return $dataImport->fresh();
        });
    }

    /** @param array<string, string> $mapping */
    private function assertMappingIsSafe(DataImport $dataImport, array $mapping): void
    {
        $headers = $dataImport->headers ?? [];

        foreach ($this->schema->requiredKeys($dataImport->type) as $requiredKey) {
            if (! isset($mapping[$requiredKey]) || $mapping[$requiredKey] === '') {
                throw new DomainException('Associe todas as colunas obrigatórias antes de validar.');
            }
        }

        $mappedHeaders = [];

        foreach ($mapping as $header) {
            if ($header === '') {
                continue;
            }

            if (! in_array($header, $headers, true)) {
                throw new DomainException('Uma das colunas seleccionadas já não existe no ficheiro.');
            }

            if (in_array($header, $mappedHeaders, true)) {
                throw new DomainException('Cada coluna do ficheiro só pode ser usada uma vez.');
            }

            $mappedHeaders[] = $header;
        }
    }

    /**
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>
     */
    private function normalizeRow(
        DataImport $dataImport,
        DataImportRow $row,
        array $mapping,
    ): array {
        $value = fn (string $key): mixed => ($mapping[$key] ?? '') === ''
            ? null
            : ($row->source_payload[$mapping[$key]] ?? null);

        return match ($dataImport->type) {
            DataImportType::Customers => $this->normalizeCustomerRow($value),
            DataImportType::CatalogueItems => $this->normalizeCatalogueRow($value),
        };
    }

    /**
     * @param  Closure(string): mixed  $value
     * @return array<string, mixed>
     */
    private function normalizeCustomerRow(Closure $value): array
    {
        $countryCode = $this->normalizer->code($value('country_code'));

        return [
            'name' => $this->normalizer->string($value('name')),
            'tax_identification_number' => $this->normalizer->code($value('tax_identification_number')),
            'country_code' => $countryCode === '' ? 'AO' : $countryCode,
            'address_line' => $this->normalizer->nullableString($value('address_line')),
            'email' => $this->normalizer->nullableString($value('email')),
            'phone' => $this->normalizer->nullableString($value('phone')),
            'is_active' => $this->normalizer->boolean($value('is_active')),
        ];
    }

    /**
     * @param  Closure(string): mixed  $value
     * @return array<string, mixed>
     */
    private function normalizeCatalogueRow(Closure $value): array
    {
        $unitOfMeasure = $this->normalizer->code($value('unit_of_measure'));
        $currencyCode = $this->normalizer->code($value('currency_code'));
        $taxType = $this->normalizer->code($value('tax_type'));
        $taxType = $taxType === '' ? 'IVA' : $taxType;
        $taxCode = $this->normalizer->nullableCode($value('tax_code'));

        if ($taxCode === null && $taxType === 'IVA') {
            $taxCode = 'NOR';
        }

        $taxPercentage = $this->normalizer->decimal($value('tax_percentage'));

        if ($taxPercentage === '') {
            $taxPercentage = $taxType === 'IVA' && $taxCode === 'NOR' ? '14' : '0';
        }

        $exemptionCode = $this->normalizer->nullableCode($value('tax_exemption_code'));

        if ($exemptionCode === null && $taxType === 'NS') {
            $exemptionCode = 'M02';
        }

        return [
            'code' => $this->normalizer->code($value('code')),
            'type' => $this->normalizer->catalogueType($value('type')),
            'name' => $this->normalizer->string($value('name')),
            'description' => $this->normalizer->nullableString($value('description')),
            'unit_of_measure' => $unitOfMeasure === '' ? 'UN' : $unitOfMeasure,
            'unit_price' => $this->normalizer->decimal($value('unit_price')),
            'currency_code' => $currencyCode === '' ? 'AOA' : $currencyCode,
            'tax_type' => $taxType,
            'tax_code' => $taxCode,
            'tax_percentage' => $taxPercentage,
            'tax_exemption_code' => $exemptionCode,
            'is_active' => $this->normalizer->boolean($value('is_active')),
        ];
    }

    /** @param array<string, mixed> $normalized */
    private function validator(DataImportType $type, array $normalized): LaravelValidator
    {
        $rules = match ($type) {
            DataImportType::Customers => [
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'tax_identification_number' => ['required', 'string', 'regex:/\A[A-Z0-9]{9,32}\z/'],
                'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
                'address_line' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email:rfc', 'max:255'],
                'phone' => ['nullable', 'string', 'max:40'],
                'is_active' => ['required', 'boolean'],
            ],
            DataImportType::CatalogueItems => [
                'code' => ['required', 'string', 'max:60'],
                'type' => ['required', Rule::in(['product', 'service'])],
                'name' => ['required', 'string', 'min:2', 'max:255'],
                'description' => ['nullable', 'string', 'max:255'],
                'unit_of_measure' => ['required', 'string', 'max:32'],
                'unit_price' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?\z/'],
                'currency_code' => ['required', Rule::in(['AOA'])],
                'tax_type' => ['required', Rule::in(['IVA', 'NS'])],
                'tax_code' => ['nullable', Rule::in(['NOR', 'ISE'])],
                'tax_percentage' => ['required', 'string', 'regex:/\A(?:0|[1-9]\d?|100)(?:\.\d{1,2})?\z/'],
                'tax_exemption_code' => ['nullable', Rule::in(['M00', 'M02', 'M04'])],
                'is_active' => ['required', 'boolean'],
            ],
        };

        return Validator::make($normalized, $rules, [
            'required' => 'Este valor é obrigatório.',
            'regex' => 'O formato deste valor não é válido.',
            'email' => 'Indique um endereço de e-mail válido.',
            'in' => 'O valor indicado não é reconhecido.',
            'boolean' => 'Use Sim/Não, Activo/Inactivo ou 1/0.',
            'max' => 'O valor excede o tamanho permitido.',
            'min' => 'O valor é demasiado curto.',
        ]);
    }

    /** @param array<string, mixed> $normalized */
    private function identifier(DataImportType $type, array $normalized): string
    {
        return (string) ($type === DataImportType::Customers
            ? ($normalized['tax_identification_number'] ?? '')
            : ($normalized['code'] ?? ''));
    }

    /** @param array<string, mixed> $normalized */
    private function validateTaxTreatment(LaravelValidator $validator, array $normalized): void
    {
        $type = $normalized['tax_type'] ?? null;
        $code = $normalized['tax_code'] ?? null;
        $percentage = $normalized['tax_percentage'] ?? null;
        $exemptionCode = $normalized['tax_exemption_code'] ?? null;

        if ($type === 'IVA' && $code === 'NOR') {
            if (! in_array($percentage, ['14', '14.0', '14.00'], true)) {
                $validator->errors()->add('tax_percentage', 'A taxa normal de IVA deve ser 14%.');
            }

            if ($exemptionCode !== null) {
                $validator->errors()->add('tax_exemption_code', 'A taxa normal não aceita motivo de isenção.');
            }

            return;
        }

        if ($type === 'IVA' && $code === 'ISE') {
            if (! in_array($percentage, ['0', '0.0', '0.00'], true)) {
                $validator->errors()->add('tax_percentage', 'Um item isento deve ter taxa 0%.');
            }

            if (! in_array($exemptionCode, ['M00', 'M04'], true)) {
                $validator->errors()->add('tax_exemption_code', 'Indique M00 ou M04 para a isenção.');
            }

            return;
        }

        if ($type === 'NS') {
            if ($code !== null) {
                $validator->errors()->add('tax_code', 'Um item não sujeito não aceita código de taxa.');
            }

            if (! in_array($percentage, ['0', '0.0', '0.00'], true)) {
                $validator->errors()->add('tax_percentage', 'Um item não sujeito deve ter taxa 0%.');
            }

            if ($exemptionCode !== 'M02') {
                $validator->errors()->add('tax_exemption_code', 'Use M02 para operações não sujeitas.');
            }

            return;
        }

        $validator->errors()->add('tax_code', 'A combinação de imposto não é válida.');
    }
}
