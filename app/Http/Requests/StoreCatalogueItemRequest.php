<?php

namespace App\Http\Requests;

use App\CatalogueItemType;
use App\Models\CatalogueItem;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'tax_type' => ['required', 'string', 'max:10'],
            'tax_code' => ['nullable', 'string', 'max:10'],
            'tax_percentage' => ['required', 'string', 'max:5', 'regex:/\A(?:0|[1-9]\d?)(?:\.\d{1,2})?\z/'],
            'tax_exemption_code' => ['nullable', 'string', 'max:20'],
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

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'unit_of_measure' => strtoupper(trim((string) $this->input('unit_of_measure', 'UN'))),
            'tax_type' => strtoupper(trim((string) $this->input('tax_type', 'IVA'))),
            'is_active' => $this->boolean('is_active'),
            'tracks_stock' => $this->input('type') === 'product' && $this->boolean('tracks_stock'),
        ]);
    }

    private function legalEntityId(): ?int
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->value('id')
            : null;
    }
}
