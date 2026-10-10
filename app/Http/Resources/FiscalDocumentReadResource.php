<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

class FiscalDocumentReadResource extends JsonResource
{
    /** @param array<string, mixed> $document */
    public function __construct(private readonly array $document)
    {
        parent::__construct($document);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return Arr::only($this->document, ['public_id', 'document_no', 'document_type', 'environment', 'revision',
            'currency_code', 'gross_total_minor', 'issue_status', 'agt_status', 'agt_status_source']);
    }
}
