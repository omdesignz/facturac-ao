<?php

namespace App\Http\Requests;

use App\Models\Workspace;
use App\Pay4AllEnvironment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SimulateEmisPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $workspace = $this->attributes->get('currentWorkspace');

        return config('billing.pay4all.environment') === Pay4AllEnvironment::Simulation->value
            && $workspace instanceof Workspace
            && $this->user()?->can('update', $workspace) === true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [];
    }
}
