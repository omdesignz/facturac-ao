<?php

namespace App\Http\Requests;

use App\Fiscal\CustomerCreateInput;
use App\Models\LegalEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobileCustomerTaxIdentificationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $this->attributes->get('currentLegalEntity');

        return [
            'tax_identification_number' => [
                ...CustomerCreateInput::coreRules()['tax_identification_number'],
                Rule::unique('customers', 'tax_identification_number')
                    ->where('legal_entity_id', $legalEntity->id)
                    ->whereNot('public_id', (string) $this->route('customerPublicId')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['tax_identification_number.unique' => 'Já existe um cliente com este NIF.'];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_identification_number' => strtoupper(trim((string) $this->input('tax_identification_number'))),
        ]);
    }
}
