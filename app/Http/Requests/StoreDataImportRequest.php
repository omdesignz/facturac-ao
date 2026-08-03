<?php

namespace App\Http\Requests;

use App\DataImportSource;
use App\DataImportType;
use App\Models\DataImport;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Validator;

class StoreDataImportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', DataImport::class) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(DataImportType::class)],
            'source' => ['required', Rule::enum(DataImportSource::class)],
            'file' => ['required', File::types(['xlsx', 'xls', 'csv'])->max('25mb')],
        ];
    }

    /** @return array<int, callable|ValidationRule> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $file = $this->file('file');
                $source = DataImportSource::tryFrom((string) $this->input('source'));

                if (! $file instanceof UploadedFile || $source === null) {
                    return;
                }

                $extension = mb_strtolower($file->getClientOriginalExtension());
                $allowedExtensions = match ($source) {
                    DataImportSource::Excel => ['xlsx', 'xls'],
                    DataImportSource::Csv => ['csv'],
                    DataImportSource::OtherApplication => ['xlsx', 'xls', 'csv'],
                };

                if (! in_array($extension, $allowedExtensions, true)) {
                    $validator->errors()->add(
                        'file',
                        'O formato do ficheiro não corresponde à origem seleccionada.',
                    );
                }
            },
        ];
    }

    public function currentLegalEntity(): ?LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return null;
        }

        return $workspace->legalEntities()->oldest('id')->first();
    }

    public function importType(): DataImportType
    {
        return DataImportType::from((string) $this->validated('type'));
    }

    public function importSource(): DataImportSource
    {
        return DataImportSource::from((string) $this->validated('source'));
    }

    public function importFile(): UploadedFile
    {
        $file = $this->file('file');

        abort_unless($file instanceof UploadedFile, 422);

        return $file;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'type' => mb_strtolower(trim((string) $this->input('type'))),
            'source' => mb_strtolower(trim((string) $this->input('source'))),
        ]);
    }
}
