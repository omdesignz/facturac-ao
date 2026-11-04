<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DownloadFiscalDocumentsRequest extends FormRequest
{
    /** One page of the documents list, and about as many PDFs as one request should render. */
    public const MAX_DOCUMENTS = 25;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'documentos' => ['required', 'array', 'max:'.self::MAX_DOCUMENTS],
            'documentos.*' => ['required', 'string', 'distinct'],
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            ? $workspace->legalEntities()->oldest('id')->first()
            : null;
    }
}
