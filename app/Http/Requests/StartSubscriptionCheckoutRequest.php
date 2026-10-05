<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartSubscriptionCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return $workspace instanceof Workspace
            && $this->user()?->can('update', $workspace) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'plan_public_id' => [
                'required',
                'string',
                Rule::exists('subscription_plans', 'public_id')
                    ->where('is_active', true),
            ],
            'customer_phone' => [Rule::requiredIf(config('billing.gateway') === 'wipay'), 'nullable', 'string', 'regex:/\A9\d{8}\z/'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_phone.required' => 'Indique o telemóvel para o pagamento.',
            'customer_phone.regex' => 'Use nove dígitos, começando por 9, sem o indicativo +244.',
        ];
    }
}
