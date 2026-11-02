<?php

namespace App\Http\Requests;

use App\Models\PushDevice;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMobilePushDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Apple's device token, as the hex the app turns it into.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^[0-9a-f]{64,200}$/'],
            'environment' => ['required', 'string', Rule::in(PushDevice::ENVIRONMENTS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['token' => strtolower(trim((string) $this->input('token')))]);
    }
}
