<?php

namespace App\Http\Requests;

use App\AgtEnvironment;
use App\Fiscal\CatalogueListCommand;
use App\Fiscal\ExecutionContext;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadCatalogueApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $actor = $user instanceof User ? $user->newQuery()->useWritePdo()->find($user->getKey()) : null;

        if (! ($actor instanceof User && $actor->hasVerifiedEmail() && $actor->hasEnabledTwoFactorAuthentication())) {
            return false;
        }
        abort_if(! in_array(trim($this->getContent()), ['', '{}', '[]'], true) || $this->request->count() !== 0 || $this->files->count() !== 0, 422);

        return true;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        $filters = $this->query();
        if (isset($filters['q']) && is_string($filters['q'])) {
            $filters['q'] = trim($filters['q'], ' ');
        }

        return ['workspace' => $this->route('workspacePublicId'), 'entity' => $this->route('entityPublicId'),
            'environment' => $this->route('environment'), 'resource' => $this->route('itemPublicId'), 'filters' => $filters];
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'],
            'environment' => ['required', Rule::enum(AgtEnvironment::class)],
            'resource' => ['nullable', 'ulid'],
            ...($this->route('itemPublicId') === null ? CatalogueListCommand::rules() : ['filters' => ['array', 'max:0']]),
        ];
    }

    public function executionContext(): ExecutionContext
    {
        $actor = $this->user();
        abort_unless($actor instanceof User, 401);
        $workspace = Workspace::query()->where('public_id', strtolower($this->validated('workspace')))
            ->useWritePdo()->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id)->where('is_active', true))->first();
        abort_unless($workspace instanceof Workspace, 403);
        $entity = LegalEntity::query()->useWritePdo()->where('public_id', strtolower($this->validated('entity')))->where('workspace_id', $workspace->id)->first();
        abort_unless($entity instanceof LegalEntity, 403);
        $context = ExecutionContext::resolve($actor, $entity, AgtEnvironment::from($this->validated('environment')), readOnly: true);
        request()->attributes->set('execution_context', $context);

        return $context;
    }
}
