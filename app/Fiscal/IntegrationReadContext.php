<?php

namespace App\Fiscal;

use App\AgtEnvironment;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

final readonly class IntegrationReadContext implements DocumentReadContext
{
    private function __construct(public int $credentialId, public int $integrationId, private int $workspace, private int $entity,
        private AgtEnvironment $agtEnvironment, private string $requestId, private int $sponsorId,
        public string $workspacePublicId, public string $entityPublicId, public string $integrationPublicId, public string $credentialPublicId,
        private ?string $clientRequestId = null) {}

    public static function authenticate(#[\SensitiveParameter] string $bearer, string $requestId, ?string $clientRequestId = null): self
    {
        abort_unless((bool) config('integrations.enabled'), 404);
        abort_unless(strlen($bearer) <= 256 && preg_match('/\Afcr1\.([0-7][0-9A-HJKMNP-TV-Z]{25})\.([A-Za-z0-9_-]{43})\z/', $bearer, $parts) === 1, 401);
        $row = self::query()->where('c.public_id', $parts[1])->first();
        $actual = hash('sha256', $bearer);
        $expected = $row !== null ? $row->secret_hash : str_repeat('0', 64);
        $matches = hash_equals($expected, $actual);
        abort_unless($matches && $row !== null, 401);
        self::credentialActive($row);

        return (new self((int) $row->credential_id, (int) $row->integration_id, (int) $row->workspace_id, (int) $row->legal_entity_id,
            AgtEnvironment::from($row->environment), $requestId, (int) $row->sponsor_user_id,
            $row->workspace_public_id, $row->entity_public_id, $row->integration_public_id, $row->credential_public_id))->withClientRequestId($clientRequestId);
    }

    public function withClientRequestId(?string $clientRequestId): self
    {
        abort_unless($clientRequestId === null || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $clientRequestId) === 1, 422);

        return new self($this->credentialId, $this->integrationId, $this->workspace, $this->entity,
            $this->agtEnvironment, $this->requestId, $this->sponsorId, $this->workspacePublicId,
            $this->entityPublicId, $this->integrationPublicId, $this->credentialPublicId, $clientRequestId);
    }

    private static function query(?string $scope = null): Builder
    {
        return DB::table('integration_credentials as c')->useWritePdo()->join('integrations as i', 'i.id', '=', 'c.integration_id')
            ->join('legal_entities as e', function ($join): void {
                $join->on('e.id', '=', 'i.legal_entity_id')->on('e.workspace_id', '=', 'i.workspace_id');
            })
            ->join('workspaces as w', 'w.id', '=', 'i.workspace_id')
            ->leftJoin('users as u', 'u.id', '=', 'i.sponsor_user_id')
            ->leftJoin('workspace_memberships as m', function ($join): void {
                $join->on('m.id', '=', 'i.sponsor_membership_id')->on('m.user_id', '=', 'i.sponsor_user_id')->on('m.workspace_id', '=', 'i.workspace_id');
            })
            ->select(['c.id as credential_id', 'c.public_id as credential_public_id', 'c.secret_hash', 'c.hash_version', 'c.expires_at', 'c.revoked_at as credential_revoked_at',
                'i.id as integration_id', 'i.public_id as integration_public_id', 'i.workspace_id', 'i.legal_entity_id', 'i.environment', 'i.sponsor_user_id', 'i.revoked_at as integration_revoked_at',
                'e.public_id as entity_public_id', 'w.public_id as workspace_public_id', 'u.email_verified_at', 'u.two_factor_confirmed_at', 'm.is_active', 'm.role'])
            ->selectRaw("CASE WHEN u.two_factor_secret IS NOT NULL AND u.two_factor_secret <> '' THEN 1 ELSE 0 END as has_mfa")
            ->selectRaw('CASE WHEN EXISTS(SELECT 1 FROM integration_scopes s WHERE s.integration_id = i.id AND s.scope = ?) AND EXISTS(SELECT 1 FROM integration_credential_scopes s WHERE s.credential_id = c.id AND s.scope = ?) THEN 1 ELSE 0 END as has_scope', [$scope, $scope]);
    }

    private static function credentialActive(stdClass $row): void
    {
        abort_unless($row->hash_version === 'sha256-v1' && $row->credential_revoked_at === null && $row->integration_revoked_at === null
            && CarbonImmutable::parse($row->expires_at)->isFuture(), 401);
    }

    public function authorize(string $permission): void
    {
        $scope = match ($permission) {
            'documents.read' => 'documents:read',
            'documents.agt-status.read' => 'documents:agt-status:read',
            'customers.read' => 'customers:read',
            'catalogue.read' => 'catalogue:read',
            'analytics.billing.read' => 'analytics:billing:read',
            default => null,
        };
        abort_unless(config('integrations.enabled') && $scope !== null, 403);
        abort_if(in_array($permission, ['customers.read', 'catalogue.read', 'analytics.billing.read'], true) && $this->agtEnvironment !== AgtEnvironment::Production, 403);
        $row = self::query($scope)->where('c.id', $this->credentialId)->where('i.id', $this->integrationId)->first();
        abort_unless($row !== null, 401);
        self::credentialActive($row);
        abort_unless((int) $row->workspace_id === $this->workspace && (int) $row->legal_entity_id === $this->entity && $row->environment === $this->agtEnvironment->value
            && $row->email_verified_at !== null && $row->two_factor_confirmed_at !== null && (int) $row->has_mfa === 1
            && $row->is_active && in_array($row->role, ['owner', 'administrator'], true) && (int) $row->has_scope === 1, 403);
    }

    public function workspaceId(): int
    {
        return $this->workspace;
    }

    public function legalEntityId(): int
    {
        return $this->entity;
    }

    public function environment(): AgtEnvironment
    {
        return $this->agtEnvironment;
    }

    public function correlationId(): string
    {
        return $this->requestId;
    }

    public function auditCauser(): Model
    {
        return Integration::findOrFail($this->integrationId);
    }

    /** @return array<string, mixed> */
    public function audit(): array
    {
        return ['actor_kind' => 'integration', 'principal_kind' => 'integration', 'integration_id' => $this->integrationId,
            'integration_public_id' => $this->integrationPublicId, 'credential_id' => $this->credentialId, 'credential_public_id' => $this->credentialPublicId,
            'real_actor_kind' => 'integration', 'effective_actor_kind' => 'integration', 'real_actor_id' => $this->integrationId, 'effective_actor_id' => $this->integrationId,
            'human_actor_id' => null, 'authority_user_id' => $this->sponsorId, 'workspace_id' => $this->workspace, 'legal_entity_id' => $this->entity,
            'environment' => $this->agtEnvironment->value, 'request_id' => $this->requestId, 'operation_id' => (string) Str::uuid(), 'client_request_id' => $this->clientRequestId,
            'impersonation_session' => null, 'approval_id' => null, 'automation_id' => null, 'user_agent' => null, 'outcome' => 'succeeded'];
    }

    public function recordSuccessfulUse(): void
    {
        $now = now()->format('Y-m-d H:i:s.u');
        DB::table('integration_credentials')->where('id', $this->credentialId)
            ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $now))->update(['last_used_at' => $now]);
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException('Live authority cannot be serialized.');
    }
}
