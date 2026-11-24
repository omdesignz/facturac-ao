<?php

namespace App\Http\Requests;

use App\Models\FiscalDocument;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueFiscalDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $document = $this->route('fiscalDocument');

        return $document instanceof FiscalDocument
            && $this->user()?->can('issue', $document) === true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'password' => [
                Rule::requiredIf(! $this->passwordRecentlyConfirmed()),
                'nullable',
                'string',
                'current_password:web',
            ],
            'series_public_id' => [
                'required',
                'string',
                Rule::exists('fiscal_series', 'public_id')->where(function ($query): void {
                    $document = $this->route('fiscalDocument');

                    if (! $document instanceof FiscalDocument) {
                        $query->whereRaw('1 = 0');

                        return;
                    }

                    $query
                        ->where('workspace_id', $document->workspace_id)
                        ->where('legal_entity_id', $document->legal_entity_id)
                        ->where('establishment_id', $document->establishment_id)
                        ->where('document_type', $document->document_type->value);
                }),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Escreva a sua palavra-passe para emitir.',
            'password.current_password' => 'A palavra-passe não está correcta.',
        ];
    }

    /**
     * Whether the person confirmed their password within the usual window, the
     * same test the password.confirm middleware applies.
     */
    public function passwordRecentlyConfirmed(): bool
    {
        $confirmedAt = (int) $this->session()->get('auth.password_confirmed_at', 0);

        return time() - $confirmedAt < (int) config('auth.password_timeout', 10800);
    }

    /**
     * A password typed here counts as a confirmation, so the next issue within
     * the window does not ask again.
     */
    protected function passedValidation(): void
    {
        if ($this->filled('password')) {
            $this->session()->put('auth.password_confirmed_at', time());
        }
    }
}
