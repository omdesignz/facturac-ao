<?php

namespace App\Http\Requests;

use App\Models\Establishment;
use App\Models\Workspace;
use App\Province;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreEstablishmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $establishment = $this->route('establishment');

        return [
            /*
             * The code goes on every document issued from here and cannot mean
             * two places at once within a company, so it is unique and fixed in
             * shape rather than free text.
             */
            'code' => [
                'required',
                'string',
                'regex:/\A[A-Z0-9][A-Z0-9_-]{1,31}\z/',
                Rule::unique('establishments', 'code')
                    ->where('legal_entity_id', $this->legalEntityId())
                    ->ignore($establishment instanceof Establishment ? $establishment->id : null),
            ],
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'address_line' => ['required', 'string', 'max:255'],
            'municipality' => ['nullable', 'string', 'max:255'],
            'province_code' => ['required', new Enum(Province::class)],
            'is_head_office' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Já existe um estabelecimento com este código.',
            'code.regex' => 'O código usa letras maiúsculas, números, hífen ou traço inferior.',
            'province_code.Illuminate\Validation\Rules\Enum' => 'Escolha uma província da lista.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'province_code' => strtoupper(trim((string) $this->input('province_code'))),
            'is_head_office' => $this->boolean('is_head_office'),
            // A place created without saying otherwise is one you can invoice from.
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
