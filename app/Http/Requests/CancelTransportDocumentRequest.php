<?php

namespace App\Http\Requests;

use App\Models\TransportDocument;
use Illuminate\Foundation\Http\FormRequest;

class CancelTransportDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $document = $this->route('transportDocument');

        return $document instanceof TransportDocument
            && $this->user()?->can('cancel', $document) === true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:6', 'max:50']];
    }
}
