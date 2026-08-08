<?php

namespace App\Http\Requests;

use App\Models\Customer;
use App\Models\PriceList;
use App\Models\Workspace;
use App\WithholdingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $customer = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:255'],
            'tax_identification_number' => [
                'required',
                'string',
                'regex:/\A[A-Z0-9]{9,32}\z/',
                Rule::unique('customers', 'tax_identification_number')
                    ->where('legal_entity_id', $this->legalEntityId())
                    ->ignore($customer instanceof Customer ? $customer->id : null),
            ],
            'country_code' => ['required', 'string', 'regex:/\A[A-Z]{2}\z/'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['required', 'boolean'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'credit_limit' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'price_list' => [
                'nullable',
                'string',
                Rule::exists('price_lists', 'public_id')
                    ->where('legal_entity_id', $this->legalEntityId()),
            ],
            'auto_send_documents' => ['required', 'boolean'],
            /*
             * What this buyer normally keeps back. Optional throughout: most
             * customers withhold nothing, and a rate without a tax to attach it
             * to says nothing.
             */
            'withholding_type' => ['nullable', Rule::enum(WithholdingType::class)],
            'withholding_rate' => [
                'nullable',
                'required_with:withholding_type',
                'numeric',
                'gt:0',
                'max:100',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tax_identification_number.unique' => 'Já existe um cliente com este NIF.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_identification_number' => strtoupper(trim((string) $this->input('tax_identification_number'))),
            'country_code' => strtoupper(trim((string) $this->input('country_code', 'AO'))),
            'is_active' => $this->boolean('is_active'),
            'auto_send_documents' => $this->boolean('auto_send_documents'),
            // Absent means the terms were never negotiated, which is cash on
            // delivery — not a validation failure.
            'payment_terms_days' => (int) $this->input('payment_terms_days', 0),
        ]);
    }

    /** The chosen tabela's id, or null when this customer buys at catalogue price. */
    public function priceListId(): ?int
    {
        $chosen = $this->validated('price_list');

        if ($chosen === null || trim((string) $chosen) === '') {
            return null;
        }

        return PriceList::query()
            ->where('legal_entity_id', $this->legalEntityId())
            ->where('public_id', (string) $chosen)
            ->value('id');
    }

    /** The rate in basis points, or null when this buyer withholds nothing. */
    public function withholdingRateBasisPoints(): ?int
    {
        $type = $this->validated('withholding_type');
        $rate = $this->validated('withholding_rate');

        if ($type === null || $rate === null || trim((string) $rate) === '') {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', trim((string) $rate), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, 2, '0'), 0, 2));
    }

    /** The agreed ceiling in minor units, or null when none was agreed. */
    public function creditLimitMinor(): ?int
    {
        $limit = $this->validated('credit_limit');

        if ($limit === null || trim((string) $limit) === '') {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', trim((string) $limit), 2), 2, '');

        return (int) ($whole.substr(str_pad($fraction, 2, '0'), 0, 2));
    }

    private function legalEntityId(): ?int
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->value('id')
            : null;
    }
}
