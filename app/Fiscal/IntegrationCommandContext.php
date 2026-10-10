<?php

namespace App\Fiscal;

use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class IntegrationCommandContext implements \JsonSerializable
{
    private function __construct(public int $integrationId, public int $credentialId, public int $workspaceId,
        public int $entityId, public string $environment, public ?int $sponsorId, public ?int $membershipId,
        public string $integrationPublicId, public string $credentialPublicId, public string $workspacePublicId,
        public string $entityPublicId, public string $requestId, public ?string $clientRequestId) {}

    public static function authenticate(#[\SensitiveParameter] string $bearer, string $requestId, ?string $clientRequestId = null): self
    {
        abort_unless(config('integrations.enabled') && config('integrations.commands_enabled'), 404);
        abort_unless(strlen($bearer) <= 256 && preg_match('/\Afcr1\.([0-7][0-9A-HJKMNP-TV-Z]{25})\.([A-Za-z0-9_-]{43})\z/', $bearer, $parts) === 1, 401);
        $row = self::identity()->where('c.public_id', $parts[1])->first();
        $match = hash_equals($row !== null ? $row->secret_hash : str_repeat('0', 64), hash('sha256', $bearer));
        abort_unless($match && $row !== null, 401);
        self::active($row);

        abort_unless(Str::isUuid($requestId), 503);
        abort_unless($clientRequestId === null || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $clientRequestId) === 1, 422);

        return new self((int) $row->integration_id, (int) $row->credential_id, (int) $row->workspace_id, (int) $row->legal_entity_id,
            $row->environment, $row->sponsor_user_id === null ? null : (int) $row->sponsor_user_id,
            $row->sponsor_membership_id === null ? null : (int) $row->sponsor_membership_id, $row->integration_public_id,
            $row->credential_public_id, $row->workspace_public_id, $row->entity_public_id, $requestId, $clientRequestId);
    }

    public function withClientRequestId(?string $value): self
    {
        abort_unless($value === null || preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $value) === 1, 422);

        return new self($this->integrationId, $this->credentialId, $this->workspaceId, $this->entityId, $this->environment,
            $this->sponsorId, $this->membershipId, $this->integrationPublicId, $this->credentialPublicId,
            $this->workspacePublicId, $this->entityPublicId, $this->requestId, $value);
    }

    private static function identity(): Builder
    {
        return DB::table('integration_credentials as c')->useWritePdo()->join('integrations as i', 'i.id', '=', 'c.integration_id')
            ->join('workspaces as w', 'w.id', '=', 'i.workspace_id')
            ->join('legal_entities as e', fn ($join) => $join->on('e.id', '=', 'i.legal_entity_id')->on('e.workspace_id', '=', 'i.workspace_id'))
            ->select(['c.id as credential_id', 'i.id as integration_id', 'c.public_id as credential_public_id', 'i.public_id as integration_public_id',
                'w.public_id as workspace_public_id', 'e.public_id as entity_public_id', 'c.secret_hash', 'c.hash_version', 'c.expires_at',
                'c.revoked_at as credential_revoked_at', 'i.revoked_at as integration_revoked_at', 'i.workspace_id', 'i.legal_entity_id',
                'i.environment', 'i.sponsor_user_id', 'i.sponsor_membership_id']);
    }

    private static function active(stdClass $row): void
    {
        $current = DB::getDriverName() === 'pgsql' ? DB::selectOne('SELECT clock_timestamp() AS value')->value : now()->toIso8601String();
        abort_unless($row->hash_version === 'sha256-v1' && $row->credential_revoked_at === null && $row->integration_revoked_at === null
            && CarbonImmutable::parse($row->expires_at)->greaterThan(CarbonImmutable::parse($current)), 401);
    }

    public function authorize(bool $locked = false, CommandCapability $capability = CommandCapability::CustomerCreate): stdClass
    {
        abort_unless(config('integrations.enabled') && config('integrations.commands_enabled'), 403);
        abort_unless($this->environment === 'production', 403);
        if ($locked) {
            foreach ([['users', $this->sponsorId, 'FOR SHARE'], ['workspace_memberships', $this->membershipId, 'FOR SHARE'],
                ['integrations', $this->integrationId, 'FOR UPDATE'], ['integration_credentials', $this->credentialId, 'FOR UPDATE']] as [$table, $id, $lock]) {
                try {
                    DB::table($table)->useWritePdo()->where('id', $id)->lock(DB::getDriverName() === 'pgsql' ? $lock : true)->first();
                } catch (QueryException $exception) {
                    if ($table === 'integrations' && ($exception->errorInfo[0] ?? '') === '55P03') {
                        throw new HttpException(409, 'COMMAND_IN_PROGRESS', null, ['Retry-After' => '1']);
                    }
                    throw $exception;
                }
            }
            foreach ([['integration_scopes', 'integration_id', $this->integrationId], ['integration_credential_scopes', 'credential_id', $this->credentialId]] as [$table, $column, $id]) {
                DB::table($table)->useWritePdo()->where($column, $id)->where('scope', $capability->scope())->sharedLock()->first();
            }
            DB::table('workspaces')->where('id', $this->workspaceId)->lock(DB::getDriverName() === 'pgsql' ? 'FOR KEY SHARE' : true)->first();
            DB::table('legal_entities')->where('id', $this->entityId)->where('workspace_id', $this->workspaceId)->lock(DB::getDriverName() === 'pgsql' ? 'FOR KEY SHARE' : true)->first();
        }
        $row = self::identity()->where('c.id', $this->credentialId)->where('i.id', $this->integrationId)->first();
        abort_unless($row !== null, 401);
        self::active($row);
        abort_unless((int) $row->workspace_id === $this->workspaceId && (int) $row->legal_entity_id === $this->entityId
            && $row->environment === $this->environment && $row->sponsor_user_id !== null && (int) $row->sponsor_user_id === $this->sponsorId
            && $row->sponsor_membership_id !== null && (int) $row->sponsor_membership_id === $this->membershipId, 403);
        $user = DB::table('users')->useWritePdo()->where('id', $this->sponsorId)->first();
        $member = DB::table('workspace_memberships')->useWritePdo()->where('id', $this->membershipId)->where('user_id', $this->sponsorId)->where('workspace_id', $this->workspaceId)->first();
        abort_unless($user !== null && $user->email_verified_at !== null && $user->two_factor_confirmed_at !== null && ! empty($user->two_factor_secret)
            && $member !== null && $member->is_active && in_array($member->role, ['owner', 'administrator'], true), 403);
        foreach ([['integration_scopes', 'integration_id', $this->integrationId], ['integration_credential_scopes', 'credential_id', $this->credentialId]] as [$table, $column, $id]) {
            abort_unless(DB::table($table)->useWritePdo()->where($column, $id)->where('scope', $capability->scope())->exists(), 403);
        }

        return $user;
    }

    public function causer(): Integration
    {
        return Integration::query()->useWritePdo()->findOrFail($this->integrationId);
    }

    public function jsonSerialize(): never
    {
        throw new \LogicException('Command data cannot be serialized.');
    }

    public function __serialize(): array
    {
        throw new \LogicException('Command authority cannot be serialized.');
    }
}
