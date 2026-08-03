<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\Workspace;
use App\TaxRegime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnboardingRequest extends FormRequest
{
    /**
     * Return the validated profile as a stable application boundary.
     *
     * @return array{
     *     legal_name: string,
     *     trade_name: string|null,
     *     tax_identification_number: string,
     *     tax_regime: string,
     *     main_cae_code: string,
     *     establishment_code: string,
     *     establishment_name: string,
     *     address_line: string,
     *     municipality: string,
     *     province_code: string
     * }
     */
    public function companyProfile(): array
    {
        return [
            'legal_name' => $this->string('legal_name')->toString(),
            'trade_name' => $this->filled('trade_name')
                ? $this->string('trade_name')->toString()
                : null,
            'tax_identification_number' => $this->string('tax_identification_number')->toString(),
            'tax_regime' => $this->string('tax_regime')->toString(),
            'main_cae_code' => $this->string('main_cae_code')->toString(),
            'establishment_code' => $this->string('establishment_code')->toString(),
            'establishment_name' => $this->string('establishment_name')->toString(),
            'address_line' => $this->string('address_line')->toString(),
            'municipality' => $this->string('municipality')->toString(),
            'province_code' => $this->string('province_code')->toString(),
        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('currentWorkspace');

        if (! $workspace instanceof Workspace) {
            return false;
        }

        $legalEntity = LegalEntity::query()
            ->where('workspace_id', $workspace->id)
            ->oldest('id')
            ->first();

        return $legalEntity === null
            ? $this->user()?->can('update', $workspace) === true
            : $this->user()?->can('update', $legalEntity) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->attributes->get('currentWorkspace');
        $legalEntityId = LegalEntity::query()
            ->where('workspace_id', $workspace->id)
            ->oldest('id')
            ->value('id');

        return [
            'legal_name' => ['required', 'string', 'min:2', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'tax_identification_number' => [
                'required',
                'string',
                'regex:/^[A-Z0-9]{9,32}$/',
                Rule::unique('legal_entities', 'tax_identification_number')
                    ->where('workspace_id', $workspace->id)
                    ->ignore($legalEntityId),
            ],
            'tax_regime' => ['required', Rule::enum(TaxRegime::class)],
            'main_cae_code' => ['required', 'string', 'regex:/^[A-Z0-9]{3,16}$/'],
            'establishment_code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_-]{1,31}$/'],
            'establishment_name' => ['required', 'string', 'min:2', 'max:255'],
            'address_line' => ['required', 'string', 'min:5', 'max:255'],
            'municipality' => ['required', 'string', 'min:2', 'max:120'],
            'province_code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,8}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tax_identification_number' => $this->normaliseCode($this->input('tax_identification_number')),
            'main_cae_code' => $this->normaliseCode($this->input('main_cae_code')),
            'establishment_code' => $this->normaliseCode($this->input('establishment_code'), preserveSeparators: true),
            'province_code' => $this->normaliseCode($this->input('province_code')),
        ]);
    }

    private function normaliseCode(mixed $value, bool $preserveSeparators = false): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $pattern = $preserveSeparators ? '/\s+/' : '/[\s.\-]+/';

        return mb_strtoupper((string) preg_replace($pattern, '', trim($value)));
    }
}
