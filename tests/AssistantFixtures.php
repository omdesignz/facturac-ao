<?php

use App\Fiscal\AssistantPlanner;
use App\Fiscal\AssistantProviderInvocation;
use App\Models\Customer;
use App\Models\FiscalDocument;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/** @return array<string, mixed> */
function assistantFixture(): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $customer = Customer::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'name' => 'Cliente Consulta']);
    $document = FiscalDocument::factory()->create(['workspace_id' => $entity->workspace_id, 'legal_entity_id' => $entity->id, 'environment' => 'production', 'created_by_user_id' => $user->id]);
    $parameters = ['workspacePublicId' => $entity->workspace->public_id, 'entityPublicId' => $entity->public_id, 'environment' => 'production'];
    $url = route('assistant.store', $parameters);

    return compact('user', 'entity', 'customer', 'document', 'parameters', 'url');
}

/** @return array<string, mixed> */
function assistantPayload(array $f, array $overrides = []): array
{
    return [...['request_nonce' => (string) Str::uuid(), 'question' => 'Consulta limitada', 'references' => [
        ['kind' => 'customer', 'public_id' => $f['customer']->public_id], ['kind' => 'document', 'public_id' => $f['document']->public_id],
    ]], ...$overrides];
}

/** @param list<array{tool: string, arguments: array<string, mixed>}> $calls */
function assistantReadPlan(array $calls): string
{
    return json_encode(['decision' => 'read', 'calls' => $calls], JSON_THROW_ON_ERROR);
}

/** @return AssistantPlanner&object{inputs: array} */
function assistantPlanner(string|Closure $plan): AssistantPlanner
{
    $planner = new class($plan) implements AssistantPlanner
    {
        public array $inputs = [];

        public function __construct(private string|Closure $answer) {}

        public function plan(#[SensitiveParameter] array $input, ?AssistantProviderInvocation $invocation = null): string
        {
            $this->inputs[] = $input;

            return $this->answer instanceof Closure ? ($this->answer)($input) : $this->answer;
        }
    };
    app()->instance(AssistantPlanner::class, $planner);
    foreach (Route::getRoutes() as $route) {
        $route->flushController();
    }

    return $planner;
}
