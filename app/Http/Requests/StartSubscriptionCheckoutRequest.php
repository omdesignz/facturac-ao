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
            'customer_phone' => ['required', 'string', 'regex:/\A9\d{8}\z/'],
            'coupon_code' => ['nullable', 'string', 'max:32', 'regex:'.PreviewSubscriptionCouponRequest::CODE_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'customer_phone.required' => 'Indique o telemóvel para o pagamento.',
            'customer_phone.regex' => 'Use nove dígitos, começando por 9, sem o indicativo +244.',
            'coupon_code.regex' => 'Este código não existe ou já não está disponível.',
            'coupon_code.max' => 'Este código não existe ou já não está disponível.',
        ];
    }
}
