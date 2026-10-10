<?php

namespace App\Fiscal;

use App\Models\LegalEntity;
use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class HumanServiceCommandContext implements \JsonSerializable
{
    private function __construct(public int $actorId, public int $workspaceId, public int $entityId, public int $membershipId, public string $requestId) {}

    public static function resolve(User $user, LegalEntity $entity): self
    {
        abort_if(Context::get('impersonator_id') !== null, 403);
        $member = DB::table('workspace_memberships')->useWritePdo()->where('user_id', $user->id)->where('workspace_id', $entity->workspace_id)->where('is_active', true)->first();
        abort_unless($member !== null, 403);
        $context = new self($user->id, $entity->workspace_id, $entity->id, (int) $member->id, (string) (Context::get('request_id') ?? Str::uuid()));
        $context->authorize();

        return $context;
    }

    public function authorize(bool $locked = false): User
    {
        abort_if(Context::get('impersonator_id') !== null, 403);
        $user = User::query()->useWritePdo()->whereKey($this->actorId)->when($locked, fn ($q) => $q->sharedLock())->first();
        $member = DB::table('workspace_memberships')->useWritePdo()->where('id', $this->membershipId)->where('user_id', $this->actorId)
            ->where('workspace_id', $this->workspaceId)->when($locked, fn ($q) => $q->sharedLock())->first();
        abort_unless($user !== null && $user->hasVerifiedEmail() && $member !== null && $member->is_active
            && in_array($member->role, ['owner', 'administrator', 'accountant', 'billing'], true), 403);
        abort_unless(LegalEntity::query()->useWritePdo()->whereKey($this->entityId)->where('workspace_id', $this->workspaceId)->exists(), 403);

        return $user;
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
