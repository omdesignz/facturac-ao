<?php

namespace App\Http\Requests;

use App\Models\FiscalDocument;

class UpdateFiscalDocumentRequest extends StoreFiscalDocumentRequest
{
    public function rules(): array
    {
        return [...parent::rules(), 'revision' => ['required', 'integer', 'min:1']];
    }

    public function authorize(): bool
    {
        $document = $this->route('fiscalDocument');

        return $document instanceof FiscalDocument
            && $this->user()?->can('update', $document) === true;
    }
}
