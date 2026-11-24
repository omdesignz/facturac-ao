<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asks what a plan costs with a discount code, before anything is charged.
 */
class PreviewSubscriptionCouponRequest extends FormRequest
{
    /** Letters, digits and hyphens, 3 to 32 long, in any case. */
    public const CODE_PATTERN = '/\A\s*[A-Za-z0-9][A-Za-z0-9-]{2,31}\s*\z/';

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
                Rule::exists('subscription_plans', 'public_id')->where('is_active', true),
            ],
            'coupon_code' => ['required', 'string', 'max:40', 'regex:'.self::CODE_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'coupon_code.required' => 'Escreva o código de desconto.',
            'coupon_code.regex' => 'Este código não existe ou já não está disponível.',
            'coupon_code.max' => 'Este código não existe ou já não está disponível.',
        ];
    }
}
