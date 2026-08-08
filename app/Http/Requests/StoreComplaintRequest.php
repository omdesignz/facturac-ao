<?php

namespace App\Http\Requests;

use App\ComplaintCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreComplaintRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(ComplaintCategory::class)],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:20', 'max:4000'],
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.min' => 'Descreva o que aconteceu com algum detalhe, para podermos investigar.',
            'contact_email.email' => 'Precisamos de um email válido para lhe responder.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subject' => trim((string) $this->input('subject')),
            'body' => trim((string) $this->input('body')),
            'contact_name' => trim((string) $this->input('contact_name')),
            'contact_email' => strtolower(trim((string) $this->input('contact_email'))),
        ]);
    }
}
