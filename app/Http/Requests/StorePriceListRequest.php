<?php

namespace App\Http\Requests;

use App\Models\PriceList;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePriceListRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $priceList = $this->route('priceList');

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:60',
                Rule::unique('price_lists', 'name')
                    ->where('legal_entity_id', $this->legalEntityId())
                    ->ignore($priceList instanceof PriceList ? $priceList->id : null),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'is_default' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Já existe uma tabela com este nome.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'is_default' => $this->boolean('is_default'),
            // A list created without saying otherwise is one you can use.
            'is_active' => $this->boolean('is_active', true),
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
