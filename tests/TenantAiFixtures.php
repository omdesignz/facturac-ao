<?php

use App\Fiscal\TenantAiContext;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @return array{user: User, context: TenantAiContext, request: Request} */
function tenantAiFixture(): array
{
    $user = User::factory()->withWorkspace()->create();
    $user->forceFill(['two_factor_secret' => 'test-only', 'two_factor_confirmed_at' => now()])->save();
    auth('web')->login($user);
    $session = new Store('tenant-ai-test', new ArraySessionHandler(120));
    $session->start();
    $session->put((string) config('work_session.started_at_key'), time());
    $session->put('auth.password_confirmed_at', time());
    $request = Request::create('/internal-test-only', 'POST');
    $request->setLaravelSession($session);
    $request->headers->set('X-CSRF-TOKEN', $session->token());
    $request->setUserResolver(fn () => $user);
    $context = TenantAiContext::resolve($request, $user->currentWorkspace->public_id);

    return compact('user', 'context', 'request');
}

function tenantAiRoot(): void
{
    config(['tenant_ai.deployment_id' => (string) Str::uuid()]);
    DB::table('ai_gateway_controls')->insert(['id' => (string) Str::uuid(), 'deployment_id' => config('tenant_ai.deployment_id'), 'kind' => 'global', 'subject_key' => 'root', 'created_at' => now(), 'updated_at' => now()]);
}
