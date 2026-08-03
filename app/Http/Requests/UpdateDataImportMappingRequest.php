<?php

namespace App\Http\Requests;

use App\Imports\ImportSchema;
use App\Models\DataImport;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDataImportMappingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $dataImport = $this->dataImport();
        $workspace = $this->attributes->get('currentWorkspace');

        abort_unless(
            $dataImport instanceof DataImport
                && $workspace instanceof Workspace
                && $dataImport->workspace_id === $workspace->id,
            404,
        );

        return $this->user()?->can('update', $dataImport) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $dataImport = $this->dataImport();

        if (! $dataImport instanceof DataImport) {
            return ['mapping' => ['required', 'array']];
        }

        $schema = app(ImportSchema::class);
        $fieldKeys = collect($schema->fields($dataImport->type))->pluck('key')->all();
        $requiredKeys = $schema->requiredKeys($dataImport->type);
        $rules = [
            'mapping' => ['required', 'array:'.implode(',', $fieldKeys)],
        ];

        foreach ($fieldKeys as $fieldKey) {
            $rules["mapping.{$fieldKey}"] = [
                in_array($fieldKey, $requiredKeys, true) ? 'required' : 'nullable',
                'string',
                Rule::in($dataImport->headers ?? []),
            ];
        }

        return $rules;
    }

    /** @return array<int, callable|ValidationRule> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $mapping = $this->input('mapping', []);

                if (! is_array($mapping)) {
                    return;
                }

                $selectedHeaders = array_values(array_filter(
                    $mapping,
                    fn (mixed $header): bool => is_string($header) && $header !== '',
                ));

                if (count(array_unique($selectedHeaders)) !== count($selectedHeaders)) {
                    $validator->errors()->add(
                        'mapping',
                        'Cada coluna do ficheiro só pode ser usada uma vez.',
                    );
                }
            },
        ];
    }

    /** @return array<string, string> */
    public function mappingProfile(): array
    {
        $mapping = $this->validated('mapping', []);

        return is_array($mapping)
            ? collect($mapping)
                ->map(fn (mixed $header): string => trim((string) $header))
                ->all()
            : [];
    }

    protected function prepareForValidation(): void
    {
        $mapping = $this->input('mapping');

        if (is_array($mapping)) {
            $this->merge([
                'mapping' => collect($mapping)
                    ->map(fn (mixed $header): string => trim((string) $header))
                    ->all(),
            ]);
        }
    }

    private function dataImport(): ?DataImport
    {
        $dataImport = $this->route('dataImport');

        return $dataImport instanceof DataImport ? $dataImport : null;
    }
}
