<?php

namespace App\Http\Requests;

use App\Fiscal\CustomerCreateInput;
use App\Models\LegalEntity;
use App\Models\MobileCreatedRecord;
use Illuminate\Validation\Rule;

/**
 * The web's customer rules for the fields the phone asks for. A retried request may
 * meet its own customer, so that one customer's NIF does not count as taken.
 */
class StoreMobileCustomerRequest extends StoreCustomerRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $this->attributes->get('currentLegalEntity');
        $alreadyMade = MobileCreatedRecord::query()
            ->where('legal_entity_id', $legalEntity->id)
            ->where('client_key', (string) $this->input('client_key'))
            ->value('customer_id');

        return [
            ...array_intersect_key(parent::rules(), array_flip([
                'name', 'country_code', 'address_line', 'email', 'phone', 'is_active', 'payment_terms_days', 'auto_send_documents',
            ])),
            'client_key' => ['required', 'uuid'],
            'tax_identification_number' => [
                ...CustomerCreateInput::coreRules()['tax_identification_number'],
                Rule::unique('customers', 'tax_identification_number')
                    ->where('legal_entity_id', $legalEntity->id)
                    ->ignore($alreadyMade),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => true, 'auto_send_documents' => false]);

        parent::prepareForValidation();
    }

    /**
     * The stored shape, as the web's form gives it for a customer with nothing
     * negotiated yet.
     *
     * @return array<string, mixed>
     */
    public function customerAttributes(): array
    {
        return [
            ...collect($this->validated())->except('client_key')->all(),
            'credit_limit_minor' => null,
            'price_list_id' => null,
            'withholding_type' => null,
            'withholding_rate_basis_points' => null,
        ];
    }
}
