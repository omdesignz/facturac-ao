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
        ];
    }
}
