<?php

namespace App\Http\Requests;

use App\AgtEnvironment;
use App\Models\AgtConnection;
use App\Models\LegalEntity;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgtConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $legalEntity = $this->currentLegalEntity();

        return $legalEntity !== null
            && $this->user()?->can('manageAgtConnection', $legalEntity) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $connection = $this->existingConnection();
        $credentialsRequired = $connection === null || ! $connection->hasBasicCredentials();

        return [
            'basic_auth_username' => [
                Rule::requiredIf($credentialsRequired),
                'nullable',
                'string',
                'max:255',
            ],
            'basic_auth_password' => [
                Rule::requiredIf($credentialsRequired),
                'nullable',
                'string',
                'max:1024',
            ],
            'product_id' => ['required', 'string', 'max:255'],
            'product_version' => ['required', 'string', 'max:64'],
            'software_validation_number' => ['required', 'string', 'max:255'],
            'establishment_number' => ['required', 'string', 'max:255'],
            'software_key_reference' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/',
                'not_regex:/\.\./',
            ],
            'taxpayer_key_reference' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._\/-]*\z/',
                'not_regex:/\.\./',
            ],
        ];
    }

    /**
     * @return array{
     *     basic_auth_username: string|null,
     *     basic_auth_password: string|null,
     *     product_id: string,
     *     product_version: string,
     *     software_validation_number: string,
     *     establishment_number: string,
     *     software_key_reference: string,
     *     taxpayer_key_reference: string
     * }
     */
    public function connectionProfile(): array
    {
        $validated = $this->validated();

        return [
            'basic_auth_username' => $this->nullableString($validated['basic_auth_username'] ?? null),
            'basic_auth_password' => $this->nullableString($validated['basic_auth_password'] ?? null),
            'product_id' => (string) $validated['product_id'],
            'product_version' => (string) $validated['product_version'],
            'software_validation_number' => (string) $validated['software_validation_number'],
            'establishment_number' => (string) $validated['establishment_number'],
            'software_key_reference' => (string) $validated['software_key_reference'],
            'taxpayer_key_reference' => (string) $validated['taxpayer_key_reference'],
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

    protected function prepareForValidation(): void
    {
        $this->merge(collect($this->except('basic_auth_password'))
            ->map(fn (mixed $value): mixed => is_string($value) ? trim($value) : $value)
            ->all());
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'basic_auth_username' => 'utilizador AGT',
            'basic_auth_password' => 'palavra-passe AGT',
            'product_id' => 'nome do software',
            'product_version' => 'versão do software',
            'software_validation_number' => 'número de validação do software',
            'establishment_number' => 'número do estabelecimento na AGT',
            'software_key_reference' => 'referência da chave do software',
            'taxpayer_key_reference' => 'referência da chave do contribuinte',
        ];
    }

    private function existingConnection(): ?AgtConnection
    {
        return $this->currentLegalEntity()?->agtConnections()
            ->where('environment', AgtEnvironment::Homologation)
            ->first();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
