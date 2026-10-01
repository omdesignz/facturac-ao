<?php

namespace App\Http\Requests;

use App\Models\TransportDocument;
use Illuminate\Foundation\Http\FormRequest;

class IssueTransportDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('transportDocument');

        return $document instanceof TransportDocument
            && $this->user()?->can('issue', $document) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['expected_revision' => ['required', 'integer', 'min:1']];
    }
}
