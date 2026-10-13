<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTaxpayerAssociationRequest extends FormRequest
{
    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace ? $workspace->legalEntities()->oldest('id')->first() : null;
    }

    public function authorize(): bool
    {
        $entity = $this->currentLegalEntity();

        return $entity !== null && $this->user()?->can('manageAgtConnection', $entity) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'association_confirmed' => ['accepted'],
            'tax_identification_number' => ['required', 'string', Rule::in([$this->currentLegalEntity()?->tax_identification_number])],
            'environment' => ['prohibited'],
            'basic_auth_username' => ['prohibited'],
            'basic_auth_password' => ['prohibited'],
            'software_key_reference' => ['prohibited'],
            'taxpayer_key_reference' => ['prohibited'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'association_confirmed.accepted' => 'Confirme que autoriza a associação à empresa indicada.',
            'tax_identification_number.in' => 'A empresa mudou. Actualize a página e confirme o NIF antes de continuar.',
        ];
    }
}
