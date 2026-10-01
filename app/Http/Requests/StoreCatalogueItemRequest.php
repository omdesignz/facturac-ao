<?php

namespace App\Http\Requests;

use App\CatalogueItemType;
use App\Fiscal\SupportedTaxTreatment;
use App\Models\CatalogueItem;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCatalogueItemRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $item = $this->route('catalogueItem');

        return [
            'code' => [
                'required',
                'string',
                'max:60',
                'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/',
                Rule::unique('catalogue_items', 'code')
                    ->where('legal_entity_id', $this->legalEntityId())
                    ->ignore($item instanceof CatalogueItem ? $item->id : null),
            ],
            'type' => ['required', Rule::enum(CatalogueItemType::class)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'unit_of_measure' => ['required', 'string', 'max:10'],
            // Kept as a decimal string and converted to minor units on save, so
            // the price never passes through a float.
            'unit_price' => ['required', 'string', 'max:18', 'regex:/\A(?:0|[1-9]\d*)(?:\.\d{1,2})?\z/'],
            'tax_type' => ['required', Rule::in(['IVA', 'NS'])],
            'tax_code' => ['nullable', Rule::in(['NOR', 'ISE'])],
            'tax_percentage' => ['required', 'string', 'max:5', 'regex:/\A(?:0|[1-9]\d?)(?:\.\d{1,2})?\z/'],
            'tax_exemption_code' => ['nullable', Rule::in(['M00', 'M02', 'M04'])],
            'is_active' => ['required', 'boolean'],
            'tracks_stock' => ['required', 'boolean'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Já existe um artigo com este código.',
            'code.regex' => 'O código só pode ter letras maiúsculas, números, ponto, hífen ou underscore.',
        ];
    }

    public function unitPriceMinor(): int
    {
        $parts = explode('.', (string) $this->input('unit_price'));
        $cents = str_pad($parts[1] ?? '', 2, '0');

        return (int) $parts[0] * 100 + (int) $cents;
    }

    /**
     * Parses a typed decimal into scaled integer units without a float, so a
     * threshold like "1.005" survives intact.
     */
    public function scaledUnits(string $value, int $scale): int
    {
        [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);

        return (int) ($whole.$fraction);
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (SupportedTaxTreatment::fromComponents(
                    $this->input('tax_type'),
                    $this->input('tax_code'),
                    $this->input('tax_percentage'),
                    $this->input('tax_exemption_code'),
                ) === null) {
                    $validator->errors()->add(
                        'tax_percentage',
                        'Seleccione um tratamento fiscal suportado.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $taxType = $this->normaliseCode($this->input('tax_type', 'IVA')) ?? 'IVA';
        $taxCode = $this->normaliseCode($this->input('tax_code'));
        $taxPercentage = $this->normaliseDecimal($this->input('tax_percentage', '14'));
        $taxExemptionCode = $this->normaliseCode($this->input('tax_exemption_code'));
        $taxTreatment = SupportedTaxTreatment::fromLegacyComponents(
            $taxType,
            $taxCode,
            $taxPercentage,
            $taxExemptionCode,
        );
        $taxProfile = $taxTreatment?->profile();

        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'unit_of_measure' => strtoupper(trim((string) $this->input('unit_of_measure', 'UN'))),
            'tax_type' => $taxProfile['type'] ?? $taxType,
            'tax_code' => $taxProfile !== null ? $taxProfile['code'] : $taxCode,
            'tax_percentage' => $taxProfile['percentage'] ?? $taxPercentage,
            'tax_exemption_code' => $taxProfile !== null
                ? $taxProfile['exemption_code']
                : $taxExemptionCode,
            'is_active' => $this->boolean('is_active'),
            'tracks_stock' => $this->input('type') === 'product' && $this->boolean('tracks_stock'),
        ]);
    }

    private function normaliseCode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtoupper(trim($value));

        return $value === '' ? null : $value;
    }

    private function normaliseDecimal(mixed $value): string
    {
        return is_string($value)
            ? str_replace(',', '.', trim($value))
            : (string) $value;
    }

    private function legalEntityId(): ?int
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->value('id')
            : null;
    }
}
