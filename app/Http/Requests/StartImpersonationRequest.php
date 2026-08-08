<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StartImpersonationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'min:'.(int) config('impersonation.reason_min_length'),
                'max:500',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Descreva o motivo do diagnóstico.',
            'reason.min' => 'O motivo tem de explicar o problema a diagnosticar.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'reason' => trim((string) $this->input('reason')),
        ]);
    }
}
