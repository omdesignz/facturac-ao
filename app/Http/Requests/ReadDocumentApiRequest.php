<?php

namespace App\Http\Requests;

use App\AgtEnvironment;
use App\Fiscal\DocumentListCommand;
use App\Fiscal\ExecutionContext;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReadDocumentApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user()?->fresh();

        return $actor instanceof User && $actor->hasVerifiedEmail() && $actor->hasEnabledTwoFactorAuthentication();
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['workspace' => $this->route('workspacePublicId'), 'entity' => $this->route('entityPublicId'),
            'environment' => $this->route('environment'), 'document' => $this->route('documentPublicId'), 'filters' => $this->query()];
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'workspace' => ['required', 'ulid'], 'entity' => ['required', 'ulid'],
            'environment' => ['required', Rule::enum(AgtEnvironment::class)],
            'document' => ['nullable', 'ulid'],
            ...($this->route('documentPublicId') === null ? DocumentListCommand::rules() : ['filters' => ['array', 'max:0']]),
        ];
    }

    public function executionContext(): ExecutionContext
    {
        $actor = $this->user();
        abort_unless($actor instanceof User, 401);
        $workspace = Workspace::query()->where('public_id', $this->validated('workspace'))
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $actor->id)->where('is_active', true))->first();
        abort_unless($workspace instanceof Workspace, 403);
        $entity = LegalEntity::query()->where('public_id', $this->validated('entity'))->where('workspace_id', $workspace->id)->firstOrFail();
        $context = ExecutionContext::resolve($actor, $entity, AgtEnvironment::from($this->validated('environment')), readOnly: true);
        request()->attributes->set('execution_context', $context);

        return $context;
    }
}
