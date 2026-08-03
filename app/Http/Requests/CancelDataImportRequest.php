<?php

namespace App\Http\Requests;

use App\Models\DataImport;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelDataImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dataImport = $this->route('dataImport');
        $workspace = $this->attributes->get('currentWorkspace');

        abort_unless(
            $dataImport instanceof DataImport
                && $workspace instanceof Workspace
                && $dataImport->workspace_id === $workspace->id,
            404,
        );

        return $this->user()?->can('delete', $dataImport) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }
}
