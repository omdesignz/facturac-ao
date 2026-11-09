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
     * Legacy Apple clients omit provider; Firebase tokens retain their exact case.
     *
     * @return array<string, array<int, ValidationRule|string|object>>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', Rule::in(['apns', 'fcm'])],
            'token' => ['required', 'string', $this->input('provider') === 'fcm'
                ? 'regex:/\A[A-Za-z0-9_:.-]{20,4096}\z/'
                : 'regex:/\A[0-9a-f]{64,200}\z/'],
            'binding' => [Rule::requiredIf($this->input('provider') === 'fcm'), 'nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'bundle_id' => ['nullable', 'string', Rule::in(config('services.apns.allowed_bundle_ids'))],
            'environment' => ['required', 'string', Rule::in(PushDevice::ENVIRONMENTS)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $provider = $this->input('provider', 'apns');
        $token = $this->input('token');
        $this->merge([
            'provider' => $provider,
            'token' => is_string($token) && $provider === 'apns' ? strtolower(trim($token)) : $token,
        ]);
    }
}
