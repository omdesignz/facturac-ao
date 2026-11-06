<?php

namespace App\Http\Requests;

use App\Fiscal\SupportedTaxTreatment;
use App\Models\LegalEntity;
use Illuminate\Validation\Rule;

/**
 * The web's article rules for what the phone can change: the name, the price, the
 * tax and the barcode. The code, the kind, stock and price lists stay on the web.
 */
class UpdateMobileCatalogueItemRequest extends StoreCatalogueItemRequest
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
                'name', 'unit_price', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code',
            ])),
            'tax_treatment' => ['required', Rule::enum(SupportedTaxTreatment::class)],
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                'regex:/\A[A-Za-z0-9-]+\z/',
                Rule::unique('catalogue_items', 'barcode')
                    ->where('legal_entity_id', $legalEntity->id)
                    ->whereNot('public_id', (string) $this->route('itemPublicId')),
            ],
        ];
    }

    /**
     * The stored shape of the change.
     *
     * @return array<string, mixed>
     */
    public function itemAttributes(): array
    {
        return [
            ...collect($this->validated())->except(['unit_price', 'tax_treatment'])->all(),
            'unit_price_minor' => $this->unitPriceMinor(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $profile = SupportedTaxTreatment::tryFrom((string) $this->input('tax_treatment'))?->profile();

        $this->merge([
            'tax_type' => $profile['type'] ?? null,
            'tax_code' => $profile['code'] ?? null,
            'tax_percentage' => $profile['percentage'] ?? null,
            'tax_exemption_code' => $profile['exemption_code'] ?? null,
        ]);

        parent::prepareForValidation();
    }
}
