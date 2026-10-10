<?php

namespace App\Http\Resources;

use App\Analytics\BillingSummaryQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/** @property array<string, mixed> $resource */
class BillingSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = Arr::only($this->resource, BillingSummaryQuery::FIELDS);
        $data['period'] = Arr::only($data['period'], ['month', 'from', 'to_exclusive']);
        $data['currencies'] = array_map(fn (array $bucket): array => Arr::only($bucket, ['currency_code', 'minor_unit_scale', ...BillingSummaryQuery::METRICS]), $data['currencies']);

        return $data;
    }
}
