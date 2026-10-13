<?php

namespace App\Http\Requests;

use App\Models\LegalEntity;
use App\Models\Workspace;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyBankDetailsRequest extends FormRequest
{
    public function legalEntity(): LegalEntity
    {
        $workspace = $this->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace, 404);

        return $workspace->legalEntities()->oldest('id')->firstOrFail();
    }

    public function authorize(): bool
    {
        return $this->user()?->can('updateBankDetails', $this->legalEntity()) === true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'bank_name' => ['nullable', 'required_with:bank_iban,bank_account_number', 'string', 'max:120'],
            'bank_account_holder' => ['nullable', 'required_with:bank_iban,bank_account_number', 'string', 'max:255'],
            'bank_account_number' => ['nullable', Rule::requiredIf(fn (): bool => $this->filled('bank_name') && ! $this->filled('bank_iban')), 'string', 'max:64'],
            'bank_iban' => ['nullable', 'string', 'max:34', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/D', $value)
                    || (str_starts_with($value, 'AO') && ! preg_match('/^AO06[0-9]{21}$/D', $value))) {
                    $fail('Introduza um IBAN válido.');

                    return;
                }

                $remainder = 0;
                foreach (str_split(substr($value, 4).substr($value, 0, 4)) as $character) {
                    $digits = ctype_digit($character) ? $character : (string) (ord($character) - 55);
                    foreach (str_split($digits) as $digit) {
                        $remainder = ($remainder * 10 + (int) $digit) % 97;
                    }
                }
                if ($remainder !== 1) {
                    $fail('Os dígitos de controlo do IBAN não são válidos.');
                }
            }],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('bank_iban'))) {
            $this->merge(['bank_iban' => strtoupper((string) preg_replace('/\s+/', '', $this->input('bank_iban')))]);
        }
    }
}
