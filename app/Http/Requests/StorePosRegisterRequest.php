<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\PosRegister;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StorePosRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $register = $this->route('posRegister');

        if (! $register instanceof PosRegister) {
            return $this->user()?->can('create', PosRegister::class) === true;
        }

        // Another company's register does not exist as far as this one knows.
        abort_unless($register->legal_entity_id === $this->currentLegalEntity()?->id, 404);

        return $this->user()?->can('update', $register) === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'establishment_public_id' => [
                'required',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0)
                        ->where('is_active', true),
                ),
            ],
            'name' => ['required', 'string', 'min:2', 'max:60', $this->uniqueName($legalEntity)],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'Já existe uma caixa com este nome neste estabelecimento.',
            'establishment_public_id.exists' => 'Escolha um dos estabelecimentos activos da empresa.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            // A register created without saying otherwise is one you can sell from.
            'is_active' => $this->boolean('is_active', true),
        ]);
    }

    private function uniqueName(?LegalEntity $legalEntity): Unique
    {
        $register = $this->route('posRegister');
        $establishmentId = $legalEntity === null
            ? null
            : $legalEntity->establishments()
                ->where('public_id', (string) $this->input('establishment_public_id'))
                ->value('id');

        return Rule::unique('pos_registers', 'name')
            ->where('legal_entity_id', $legalEntity->id ?? 0)
            ->where('establishment_id', $establishmentId ?? 0)
            ->ignore($register instanceof PosRegister ? $register->id : null);
    }

    private function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}
