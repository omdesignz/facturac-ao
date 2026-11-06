<?php

namespace App\Http\Requests;

use App\Fiscal\SupportedTaxTreatment;
use App\Models\LegalEntity;
use App\Models\MobileCreatedRecord;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The web's article rules for the fields the phone asks for. The phone names a tax
 * treatment instead of its components, and may leave the code for this to make from
 * the name. A retried request may meet its own item, so its code does not count as taken.
 */
class StoreMobileCatalogueItemRequest extends StoreCatalogueItemRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $alreadyMade = MobileCreatedRecord::query()
            ->where('legal_entity_id', $this->legalEntity()->id)
            ->where('client_key', (string) $this->input('client_key'))
            ->value('catalogue_item_id');

        return [
            ...array_intersect_key(parent::rules(), array_flip([
                'type', 'name', 'unit_of_measure', 'unit_price', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code', 'is_active', 'tracks_stock',
            ])),
            'client_key' => ['required', 'uuid'],
            // From the camera, when the code it read belongs to no item yet.
            'barcode' => [
                'nullable',
                'string',
                'max:64',
                'regex:/\A[A-Za-z0-9-]+\z/',
                Rule::unique('catalogue_items', 'barcode')
                    ->where('legal_entity_id', $this->legalEntity()->id)
                    ->ignore($alreadyMade),
            ],
            'tax_treatment' => ['required', Rule::enum(SupportedTaxTreatment::class)],
            'code' => [
                'required',
                'string',
                'max:60',
                'regex:/\A[A-Z0-9][A-Z0-9._-]*\z/',
                Rule::unique('catalogue_items', 'code')
                    ->where('legal_entity_id', $this->legalEntity()->id)
                    ->ignore($alreadyMade),
            ],
        ];
    }

    /**
     * The fields the shared action reads.
     *
     * @return array<string, mixed>
     */
    public function itemValues(): array
    {
        return collect($this->validated())->except(['client_key', 'tax_treatment'])->all();
    }

    /**
     * The stored shape of a product.
     *
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return [
            ...collect($this->itemValues())->except('unit_price')->all(),
            'unit_price_minor' => $this->unitPriceMinor(),
            'reorder_level_units' => null,
        ];
    }

    protected function prepareForValidation(): void
    {
        $profile = SupportedTaxTreatment::tryFrom((string) $this->input('tax_treatment'))?->profile();

        $this->merge([
            'code' => $this->filled('code') ? $this->input('code') : $this->codeFromName(),
            'is_active' => true,
            'tracks_stock' => false,
            'tax_type' => $profile['type'] ?? null,
            'tax_code' => $profile['code'] ?? null,
            'tax_percentage' => $profile['percentage'] ?? null,
            'tax_exemption_code' => $profile['exemption_code'] ?? null,
        ]);

        parent::prepareForValidation();
    }

    /** "Arroz agulha 25 kg" becomes ARROZ-AGULHA-25-KG, numbered if that is taken. */
    private function codeFromName(): string
    {
        $base = Str::of(Str::ascii((string) $this->input('name')))
            ->upper()
            ->replaceMatches('/[^A-Z0-9]+/', '-')
            ->trim('-')
            ->limit(40, '')
            ->rtrim('-')
            ->whenEmpty(fn () => Str::of('ARTIGO'))
            ->toString();

        $taken = $this->legalEntity()->catalogueItems()
            ->where('code', 'like', "{$base}%")
            ->pluck('code')
            ->all();

        $code = $base;

        for ($number = 2; in_array($code, $taken, true); $number++) {
            $code = "{$base}-{$number}";
        }

        return $code;
    }

    private function legalEntity(): LegalEntity
    {
        /** @var LegalEntity $legalEntity */
        $legalEntity = $this->attributes->get('currentLegalEntity');

        return $legalEntity;
    }
}
