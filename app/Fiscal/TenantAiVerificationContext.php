<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use App\Models\LegalEntity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Request-local owner authority. Public identifiers retain their exact stored bytes. */
final readonly class TenantAiVerificationContext
{
    /** @param \Fiber<mixed, mixed, mixed, mixed>|null $fiber */
    private function __construct(
        private Request $request,
        private TenantAiContext $owner,
        public int $legalEntityId,
        public string $legalEntityPublicId,
        public string $actorAttributionId,
        private int $pid,
        private ?\Fiber $fiber,
        public float $deadline,
    ) {}

    public static function resolve(Request $request, string $workspacePublicId, string $legalEntityPublicId, string $environment): self
    {
        $deadline = hrtime(true) / 1e9 + 10;
        if ($environment !== 'production' || preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $workspacePublicId) !== 1
            || preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $legalEntityPublicId) !== 1 || $request->bearerToken() !== null) {
            throw new TenantAiStorageUnavailable;
        }
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        try {
            return DB::transaction(function () use ($request, $workspacePublicId, $legalEntityPublicId, $deadline): self {
                TenantAiVerificationAdmission::limits($deadline);
                $owner = TenantAiContext::resolve($request, $workspacePublicId);
                $entity = LegalEntity::query()->useWritePdo()->where('workspace_id', $owner->workspaceId)->where('public_id', $legalEntityPublicId)->first();
                if ($entity === null) {
                    throw new TenantAiStorageUnavailable;
                }
                $actor = $owner->authorize();
                TenantAiVerificationQuota::key($actor->attribution_id);
                $pid = getmypid();
                if ($pid === false) {
                    throw new TenantAiStorageUnavailable;
                }
                $context = new self($request, $owner, $entity->id, $entity->public_id, $actor->attribution_id, $pid, \Fiber::getCurrent(), $deadline);
                $context->authorize();

                return $context;
            }, 1);
        } catch (\Throwable) {
            throw new TenantAiStorageUnavailable;
        }
    }

    public function assertRequest(): void
    {
        if (getmypid() !== $this->pid || \Fiber::getCurrent() !== $this->fiber || hrtime(true) / 1e9 >= $this->deadline
            || app('request') !== $this->request || $this->request->bearerToken() !== null
            || $this->request->user('web')?->getAuthIdentifier() !== $this->owner->actorId) {
            throw new TenantAiStorageUnavailable;
        }
    }

    public function authorize(): TenantAiContext
    {
        $this->assertRequest();
        if ($this->owner->authorize()->attribution_id !== $this->actorAttributionId
            || ! LegalEntity::query()->useWritePdo()->where('id', $this->legalEntityId)->where('public_id', $this->legalEntityPublicId)
                ->where('workspace_id', $this->owner->workspaceId)->exists()) {
            throw new TenantAiStorageUnavailable;
        }

        return $this->owner;
    }

    private function __clone() {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['context' => 'restricted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
