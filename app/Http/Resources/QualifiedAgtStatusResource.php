<?php

namespace App\Http\Resources;

use App\Fiscal\Documents\QualifiedAgtStatusRead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @property array<string, mixed> $resource */
class QualifiedAgtStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->resource, QualifiedAgtStatusRead::FIELDS);
    }
}
