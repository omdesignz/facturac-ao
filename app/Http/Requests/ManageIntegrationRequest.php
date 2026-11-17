<?php

namespace App\Http\Requests;

use App\Fiscal\IntegrationManagementContext;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

class ManageIntegrationRequest extends FormRequest
{
    public function context(): IntegrationManagementContext
    {
        $workspace = $this->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace && $this->isSecure(), 403);

        return IntegrationManagementContext::resolve($this, $workspace->id);
    }

    public function authorize(): bool
    {
        $this->context()->authorize(reveal: ! $this->isMethod('DELETE'));

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        if ($this->routeIs('integrations.settings.store')) {
            return [
                'name' => ['required', 'string', 'max:100'],
                'entity_public_id' => ['required', 'ulid'],
                'environment' => ['required', 'in:production,homologation'],
                'scopes' => ['required', 'array', 'min:1', 'max:7'],
                'scopes.*' => ['required', 'string', 'distinct:strict'],
            ];
        }

        return ['revision' => ['required', 'integer', 'min:1']];
    }
}
