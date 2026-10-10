<?php

use App\AgtEnvironment;
use App\Fiscal\IntegrationCommandContext;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Models\Integration;
use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

function commandFixture(array $scopes = ['customers:create']): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test', 'two_factor_confirmed_at' => now()])->save();
    $entity = LegalEntity::factory()->configured()->create(['workspace_id' => $user->current_workspace_id]);
    $request = Request::create('https://localhost/');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session')->driver());
    $request->session()->put((string) config('work_session.started_at_key'), time());
    $request->session()->put('auth.password_confirmed_at', time());
    $management = IntegrationManagementContext::resolve($request, $entity->workspace_id);
    $issued = app(IntegrationCredentials::class)->create($management, $entity->public_id, AgtEnvironment::Production, 'Command', $scopes);
    $secret = $issued->revealOnce();
    $integration = Integration::where('public_id', $issued->integrationPublicId)->firstOrFail();
    $context = IntegrationCommandContext::authenticate($secret, (string) Str::uuid());
    $url = 'https://localhost/api/integrations/commands/v1/workspaces/'.$context->workspacePublicId.'/legal-entities/'.$context->entityPublicId.'/environments/production/customers';

    return compact('user', 'entity', 'management', 'issued', 'secret', 'integration', 'context', 'url');
}
