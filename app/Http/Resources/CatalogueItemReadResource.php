<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CatalogueItemReadResource extends JsonResource
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct($data);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->data, ['public_id', 'code', 'type', 'name', 'unit_of_measure', 'unit_price_minor', 'currency_code', 'tax_type', 'tax_code', 'tax_percentage', 'tax_exemption_code', 'is_active', 'price_scale', 'description']);
    }
}
