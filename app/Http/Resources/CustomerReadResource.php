<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class CustomerReadResource extends JsonResource
{
    /** @param array<string, mixed> $data */
    public function __construct(private readonly array $data)
    {
        parent::__construct($data);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->data, ['public_id', 'name', 'country_code', 'is_active']);
    }
}
