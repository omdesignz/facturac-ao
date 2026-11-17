<?php

namespace App\Http\Controllers;

use App\AgtEnvironment;
use App\Billing\SubscriptionAccess;
use App\Fiscal\IntegrationCredentials;
use App\Fiscal\IntegrationManagementContext;
use App\Fiscal\IssuedIntegrationCredential;
use App\Http\Requests\ManageIntegrationRequest;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\Workspace;
use App\SubscriptionFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationSettingsController extends Controller
{
    public function __construct(private IntegrationCredentials $credentials, private SubscriptionAccess $subscriptions) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('currentWorkspace');
        abort_unless($workspace instanceof Workspace && $request->isSecure(), 403);
        IntegrationManagementContext::resolve($request, $workspace->id);
        $integrations = Integration::query()->where('workspace_id', $workspace->id)->latest('id')->get();
        $credentials = IntegrationCredential::query()->whereIn('integration_id', $integrations->modelKeys())->orderByDesc('id')->get()->groupBy('integration_id');
        $scopes = DB::table('integration_scopes')->whereIn('integration_id', $integrations->modelKeys())->get()->groupBy('integration_id');
        $entities = $workspace->legalEntities()->get();

        return Inertia::render('Settings/Integrations', [
            'workspacePublicId' => $workspace->public_id,
            'entities' => $entities->map(fn ($entity): array => ['public_id' => $entity->public_id, 'name' => $entity->legal_name]),
            'enabled' => (bool) config('integrations.enabled'),
            'commandsEnabled' => (bool) config('integrations.commands_enabled'),
            'entitled' => $this->subscriptions->allows($workspace->id, SubscriptionFeature::Integrations),
            'integrations' => $integrations->map(fn (Integration $integration): array => [
                'public_id' => $integration->public_id,
                'name' => $integration->getAttribute('name'),
                'entity_public_id' => $entities->firstWhere('id', $integration->legal_entity_id)?->public_id,
                'environment' => $integration->environment,
                'revision' => $integration->revision,
                'revoked_at' => $integration->revoked_at?->toIso8601String(),
                'scopes' => $scopes->get($integration->id, collect())->pluck('scope')->values()->all(),
                'credentials' => $credentials->get($integration->id, collect())->map(fn (IntegrationCredential $credential): array => [
                    'public_id' => $credential->public_id,
                    'expires_at' => $credential->expires_at->toIso8601String(),
                    'revoked_at' => $credential->revoked_at?->toIso8601String(),
                    'last_used_at' => $credential->last_used_at?->toIso8601String(),
                    'active' => $credential->revoked_at === null && $credential->expires_at->isFuture(),
                ])->values()->all(),
            ]),
        ]);
    }

    public function reauthenticate(): RedirectResponse
    {
        return redirect()->route('integrations.settings.index');
    }

    public function store(ManageIntegrationRequest $request): JsonResponse
    {
        $context = $request->context();
        $this->subscriptions->require($context->workspaceId, SubscriptionFeature::Integrations);
        /** @var array{name: string, entity_public_id: string, environment: string, scopes: list<string>} $data */
        $data = $request->validated();

        return $this->disclose($this->credentials->create($context, $data['entity_public_id'], AgtEnvironment::from($data['environment']), $data['name'], $data['scopes']));
    }

    public function rotate(ManageIntegrationRequest $request, string $integrationPublicId, string $credentialPublicId): JsonResponse
    {
        $context = $request->context();
        $this->subscriptions->require($context->workspaceId, SubscriptionFeature::Integrations);
        $integration = Integration::query()->where('workspace_id', $context->workspaceId)->where('public_id', $integrationPublicId)->firstOrFail();
        $credential = IntegrationCredential::query()->where('integration_id', $integration->id)->where('public_id', $credentialPublicId)->firstOrFail();
        /** @var list<string> $scopes */
        $scopes = DB::table('integration_credential_scopes')->where('credential_id', $credential->id)->pluck('scope')->all();

        return $this->disclose($this->credentials->rotate($context, $integrationPublicId, $request->integer('revision'), $credentialPublicId, $scopes));
    }

    public function replace(ManageIntegrationRequest $request, string $integrationPublicId): JsonResponse
    {
        $context = $request->context();
        $this->subscriptions->require($context->workspaceId, SubscriptionFeature::Integrations);

        return $this->disclose($this->credentials->replaceExpired($context, $integrationPublicId, $request->integer('revision')));
    }

    public function destroy(ManageIntegrationRequest $request, string $integrationPublicId, ?string $credentialPublicId = null): JsonResponse
    {
        $this->credentials->revoke($request->context(), $integrationPublicId, $request->integer('revision'), credentialPublicId: $credentialPublicId);

        return response()->json(['revoked' => true])->header('Cache-Control', 'private, no-store');
    }

    private function disclose(IssuedIntegrationCredential $issued): JsonResponse
    {
        return response()->json([
            'integration_public_id' => $issued->integrationPublicId,
            'credential_public_id' => $issued->credentialPublicId,
            'token' => $issued->revealOnce(),
        ], 201)->header('Cache-Control', 'private, no-store')->header('Pragma', 'no-cache');
    }
}
