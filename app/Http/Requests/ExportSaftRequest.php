<?php

namespace App\Http\Requests;

use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ExportSaftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', FiscalDocument::class) === true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $legalEntity = $this->currentLegalEntity();

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'establishment' => [
                'nullable',
                'string',
                Rule::exists('establishments', 'public_id')->where(
                    fn ($query) => $query
                        ->where('workspace_id', $legalEntity->workspace_id ?? 0)
                        ->where('legal_entity_id', $legalEntity->id ?? 0),
                ),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'A data final não pode ser anterior à inicial.'];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $from = (string) $this->input('from');
                $to = (string) $this->input('to');

                if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $from) !== 1
                    || preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $to) !== 1) {
                    return;
                }

                if (substr($from, 0, 4) !== substr($to, 0, 4)) {
                    $validator->errors()->add(
                        'to',
                        'O SAF-T tem de pertencer a um único exercício fiscal.',
                    );
                }
            },
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
