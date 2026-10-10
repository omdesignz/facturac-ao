<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\Integration;
use App\Models\IntegrationCredential;
use App\Models\LegalEntity;
use App\Models\User;
use App\Models\WorkspaceMembership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class IntegrationCredentials
{
    /** @param list<string> $scopes */
    public function create(IntegrationManagementContext $context, string $entityPublicId, AgtEnvironment $environment, string $name, array $scopes = ['documents:read'], ?CarbonImmutable $expiresAt = null): IssuedIntegrationCredential
    {
        $this->outsideTransaction();
        $this->scopes($scopes);
        $this->scopeEnvironment($scopes, $environment);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:100']])->validate();

        return $this->transition($context, function () use ($context, $entityPublicId, $environment, $name, $scopes, $expiresAt): IssuedIntegrationCredential {
            $actor = $context->authorize();
            $entity = LegalEntity::query()->where('workspace_id', $context->workspaceId)->where('public_id', strtolower($entityPublicId))->firstOrFail();
            $membership = WorkspaceMembership::query()->where('user_id', $actor->id)->where('workspace_id', $context->workspaceId)->firstOrFail();
            $integration = Integration::create(['workspace_id' => $context->workspaceId, 'legal_entity_id' => $entity->id,
                'environment' => $environment->value, 'name' => $name, 'sponsor_user_id' => $actor->id, 'sponsor_membership_id' => $membership->id,
                'creator_principal_kind' => 'user', 'creator_attribution_id' => $actor->attribution_id]);
            foreach ($scopes as $scope) {
                DB::table('integration_scopes')->insert(['integration_id' => $integration->id, 'scope' => $scope]);
            }
            $result = $this->issue($integration, $actor, $scopes, $expiresAt);
            $this->audit($integration, $actor, 'integration.created', ['credential_public_id' => $result->credentialPublicId, 'scopes' => $scopes]);

            return $result;
        });
    }

    /** @param list<string> $scopes */
    public function rotate(IntegrationManagementContext $context, string $publicId, int $expectedRevision, string $credentialPublicId, array $scopes = ['documents:read'], ?CarbonImmutable $expiresAt = null, bool $immediateRevoke = false): IssuedIntegrationCredential
    {
        $this->outsideTransaction();
        $this->scopes($scopes);

        return $this->transition($context, function () use ($context, $publicId, $expectedRevision, $credentialPublicId, $scopes, $expiresAt, $immediateRevoke): IssuedIntegrationCredential {
            [$integration, $actor] = $this->locked($context, $publicId, $expectedRevision);
            $credentials = IntegrationCredential::query()->where('integration_id', $integration->id)->orderBy('id')->lockForUpdate()->get();
            $old = $credentials->firstWhere('public_id', $credentialPublicId);
            abort_unless($old instanceof IntegrationCredential && $old->revoked_at === null && $old->expires_at->isFuture(), 409);
            abort_if($credentials->filter(fn (IntegrationCredential $credential): bool => $credential->revoked_at === null && $credential->expires_at->isFuture())->count() >= 2, 409);
            $allowed = DB::table('integration_credential_scopes')->where('credential_id', $old->id)->pluck('scope')->all();
            $ceiling = DB::table('integration_scopes')->where('integration_id', $integration->id)->pluck('scope')->all();
            abort_if(array_diff($scopes, $allowed) !== [] || array_diff($scopes, $ceiling) !== [], 403);
            $before = $old->expires_at->toIso8601String();
            $old->expires_at = $old->expires_at->min(now()->addDay());
            if ($immediateRevoke) {
                $old->revoked_at = now();
                $old->setAttribute('revoked_by_user_id', $actor->id);
                $old->setAttribute('revocation_reason', 'rotation');
            }
            $old->save();
            $result = $this->issue($integration, $actor, $scopes, $expiresAt, $old->id);
            $integration->increment('revision');
            $this->audit($integration, $actor, 'integration.rotated', ['credential_public_id' => $result->credentialPublicId, 'previous_revision' => $expectedRevision, 'scopes' => $scopes, 'previous_credential_public_id' => $old->public_id, 'previous_expires_at' => $before, 'retiring_expires_at' => $old->expires_at->toIso8601String()]);

            return $result;
        });
    }

    public function replaceExpired(IntegrationManagementContext $context, string $publicId, int $expectedRevision, ?CarbonImmutable $expiresAt = null): IssuedIntegrationCredential
    {
        $this->outsideTransaction();

        return $this->transition($context, function () use ($context, $publicId, $expectedRevision, $expiresAt): IssuedIntegrationCredential {
            [$integration, $actor] = $this->locked($context, $publicId, $expectedRevision);
            abort_if(IntegrationCredential::query()->where('integration_id', $integration->id)->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->exists(), 409);
            $scopes = array_values(array_map(fn (string $scope): string => $scope, DB::table('integration_scopes')->where('integration_id', $integration->id)->pluck('scope')->all()));
            $result = $this->issue($integration, $actor, $scopes, $expiresAt);
            $integration->increment('revision');
            $this->audit($integration, $actor, 'integration.credential-replaced', ['credential_public_id' => $result->credentialPublicId, 'previous_revision' => $expectedRevision]);

            return $result;
        });
    }

    public function revoke(IntegrationManagementContext $context, string $publicId, int $expectedRevision, string $reason = 'owner_request', ?string $credentialPublicId = null): void
    {
        Validator::make(['reason' => $reason], ['reason' => ['in:owner_request,rotation,compromise,authority_withdrawn,setup_failure']])->validate();
        $this->transition($context, function () use ($context, $publicId, $expectedRevision, $reason, $credentialPublicId): void {
            $integration = Integration::query()->where('workspace_id', $context->workspaceId)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
            $actor = $context->authorize(reveal: false);
            $target = $credentialPublicId === null ? $integration : IntegrationCredential::query()->where('integration_id', $integration->id)->where('public_id', $credentialPublicId)->lockForUpdate()->firstOrFail();
            if ($target->revoked_at !== null) {
                return;
            }
            abort_unless($integration->revision === $expectedRevision, 409);
            $target->forceFill(['revoked_at' => now(), 'revoked_by_user_id' => $actor->id, 'revocation_reason' => $reason])->save();
            $integration->increment('revision');
            $this->audit($integration, $actor, $credentialPublicId === null ? 'integration.revoked' : 'integration.credential-revoked', ['reason' => $reason, 'previous_revision' => $expectedRevision, 'credential_public_id' => $credentialPublicId]);
        });
    }

    public function reduceGrant(IntegrationManagementContext $context, string $publicId, int $expectedRevision): void
    {
        $this->transition($context, function () use ($context, $publicId, $expectedRevision): void {
            [$integration, $actor] = $this->locked($context, $publicId, $expectedRevision);
            $before = DB::table('integration_scopes')->where('integration_id', $integration->id)->pluck('scope')->all();
            DB::table('integration_scopes')->where('integration_id', $integration->id)->delete();
            $integration->increment('revision');
            $this->audit($integration, $actor, 'integration.grant-reduced', ['scopes' => [], 'previous_scopes' => $before, 'previous_revision' => $expectedRevision]);
        });
    }

    /** @return array{Integration, User} */
    private function locked(IntegrationManagementContext $context, string $publicId, int $revision): array
    {
        $integration = Integration::query()->where('workspace_id', $context->workspaceId)->where('public_id', $publicId)->lockForUpdate()->firstOrFail();
        $actor = $context->authorize();
        abort_unless($integration->revoked_at === null && $integration->revision === $revision, 409);

        return [$integration, $actor];
    }

    /** @param list<string> $scopes */
    private function issue(Integration $integration, User $actor, array $scopes, ?CarbonImmutable $expiresAt, ?int $replaces = null): IssuedIntegrationCredential
    {
        $this->scopes($scopes);
        $this->scopeEnvironment($scopes, AgtEnvironment::from($integration->environment));
        $expiresAt ??= CarbonImmutable::now()->addDays(30);
        abort_unless($expiresAt->greaterThanOrEqualTo(now()->addHour()) && $expiresAt->lessThanOrEqualTo(now()->addDays(90)), 422);
        $publicId = (string) Str::ulid();
        $secret = 'fcr1.'.$publicId.'.'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $credential = SensitiveCredentialQuery::run(fn (): IntegrationCredential => IntegrationCredential::create(['public_id' => $publicId, 'integration_id' => $integration->id,
            'secret_hash' => hash('sha256', $secret), 'hash_version' => 'sha256-v1', 'created_by_user_id' => $actor->id,
            'creator_principal_kind' => 'user', 'creator_attribution_id' => $actor->attribution_id, 'expires_at' => $expiresAt, 'replaces_credential_id' => $replaces]));
        foreach ($scopes as $scope) {
            DB::table('integration_credential_scopes')->insert(['credential_id' => $credential->id, 'scope' => $scope]);
        }
        $this->audit($integration, $actor, 'integration.credential-created', ['credential_public_id' => $credential->public_id, 'creator_attribution_id' => $actor->attribution_id, 'expires_at' => $expiresAt->toIso8601String(), 'scopes' => $scopes]);

        return new IssuedIntegrationCredential($integration->public_id, $credential->public_id, $secret);
    }

    /** @param list<string> $scopes */
    private function scopes(array $scopes): void
    {
        Validator::make(['scopes' => $scopes], ['scopes' => ['array'], 'scopes.*' => ['required', 'string', 'distinct:strict', 'in:documents:read,customers:read,catalogue:read,documents:agt-status:read,analytics:billing:read,customers:create,catalogue:services:create']])->validate();
    }

    /** @param list<string> $scopes */
    private function scopeEnvironment(array $scopes, AgtEnvironment $environment): void
    {
        abort_if($environment !== AgtEnvironment::Production && array_intersect($scopes, ['customers:read', 'catalogue:read', 'analytics:billing:read', 'customers:create', 'catalogue:services:create']) !== [], 422);
    }

    private function outsideTransaction(): void
    {
        abort_if(DB::connection()->transactionLevel() !== 0, 409, 'Secret disclosure requires an outermost transaction.');
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function transition(IntegrationManagementContext $context, \Closure $operation): mixed
    {
        try {
            return DB::transaction($operation);
        } catch (HttpException $exception) {
            try {
                $actor = $context->authorize(reveal: false);
                RequiredAudit::record(fn () => activity('integration')->causedBy($actor)->event('integration.denied')
                    ->withProperties(['actor_kind' => 'human', 'principal_kind' => 'user', 'real_actor_id' => $actor->id,
                        'effective_actor_id' => $actor->id, 'human_actor_attribution_id' => $actor->attribution_id,
                        'workspace_id' => $context->workspaceId, 'outcome' => 'denied', 'status' => $exception->getStatusCode(),
                        'user_agent' => null, 'impersonation_session' => null, 'request_id' => (string) Str::uuid(), 'operation_id' => (string) Str::uuid()])
                    ->log('Integration lifecycle refused'));
            } catch (\Throwable) {
                Log::notice('Integration lifecycle refused', ['operation_id' => (string) Str::uuid()]);
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $properties */
    private function audit(Integration $integration, User $actor, string $event, array $properties): void
    {
        RequiredAudit::record(fn () => activity('integration')->performedOn($integration)->causedBy($actor)->event($event)
            ->withProperties([...$properties, 'actor_kind' => 'human', 'principal_kind' => 'user', 'real_actor_kind' => 'user', 'effective_actor_kind' => 'user', 'real_actor_id' => $actor->id, 'effective_actor_id' => $actor->id, 'authority_user_id' => $actor->id, 'human_actor_id' => $actor->id, 'impersonation_session' => null, 'approval_id' => null, 'automation_id' => null, 'outcome' => 'succeeded', 'operation_id' => (string) Str::uuid(), 'creator_principal_kind' => 'user', 'creator_attribution_id' => $properties['creator_attribution_id'] ?? $integration->creator_attribution_id, 'integration_creator_attribution_id' => $integration->creator_attribution_id, 'user_agent' => null,
                'human_actor_attribution_id' => $actor->attribution_id, 'workspace_id' => $integration->workspace_id, 'legal_entity_id' => $integration->legal_entity_id,
                'environment' => $integration->environment, 'revision' => $integration->revision, 'request_id' => Context::get('request_id') ?? (string) Str::uuid()])
            ->log('Integration lifecycle transition'));
    }
}
