<?php

namespace App\Http\Requests;

use App\Fiscal\Documents\FiscalSeriesYearWindow;
use App\FiscalDocumentType;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgtSeriesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $legalEntity = $this->currentLegalEntity();

        return $legalEntity instanceof LegalEntity
            && $this->user()?->can('testAgtConnection', $legalEntity) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_type' => [
                'required',
                'string',
                Rule::in(array_map(
                    static fn (FiscalDocumentType $type): string => $type->value,
                    FiscalDocumentType::issuable(),
                )),
            ],
            'series_year' => [
                'required',
                'integer',
                Rule::in((new FiscalSeriesYearWindow)->allowedYears(now('UTC'))),
            ],
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

    public function documentType(): FiscalDocumentType
    {
        return FiscalDocumentType::from((string) $this->validated('document_type'));
    }

    public function seriesYear(): int
    {
        return (int) $this->validated('series_year');
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'document_type' => 'tipo de documento',
            'series_year' => 'ano da série',
        ];
    }
}
