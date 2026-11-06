<?php

namespace App\Http\Requests;

use App\Fiscal\CustomerCreateInput;
use App\Models\LegalEntity;
use Illuminate\Validation\Rule;

/**
 * The web's customer rules for the details the phone can change: who they are and
 * how to reach them, and when they pay. Price lists, credit limits, withholdings
 * and sending documents automatically stay on the web.
 */
class UpdateMobileCustomerRequest extends StoreCustomerRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $this->attributes->get('currentLegalEntity');

        return [
            ...array_intersect_key(parent::rules(), array_flip([
                'name', 'address_line', 'email', 'phone', 'payment_terms_days',
            ])),
            'tax_identification_number' => [
                ...CustomerCreateInput::coreRules()['tax_identification_number'],
                Rule::unique('customers', 'tax_identification_number')
                    ->where('legal_entity_id', $legalEntity->id)
                    ->whereNot('public_id', (string) $this->route('customerPublicId')),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        return $this->validated();
    }
}
