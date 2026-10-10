<?php

namespace App\Fiscal;

use App\Exceptions\TenantAiStorageUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

/** One transient, closed verification session. Persisted data cannot recreate its object authority. */
final class TenantAiCredentialVerifier
{
    private string $phase = 'new';

    private ?TenantAiVerificationContext $context = null;

    private ?TenantAiVerificationRequest $request = null;

    private ?TenantAiVerificationPolicy $policy = null;

    /** @var array<string, mixed> */
    private array $attempt = [];

    private ?VerificationRequestPermit $requestPermit = null;

    private ?CredentialPromotionPermit $promotionPermit = null;

    private ?CredentialPromotionPermit $claimedPromotion = null;

    private ?TenantAiVerificationSecret $secret = null;

    private ?CredentialVerificationEvidence $observation = null;

    private ?CarbonImmutable $observedAt = null;

    private int $requestStage = 0;

    private bool $sendAuthorized = false;

    private TenantAiVerificationTransport $transport;

    private string $evidenceRealm = 'provider_tls';

    public function __construct()
    {
        $this->transport = new TenantAiVerificationTransport;
    }

    public function verify(TenantAiVerificationContext $context, TenantAiVerificationRequest $request): TenantAiVerificationResult
    {
        if ($this->phase !== 'new' || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $this->context = $context;
        $this->request = $request;
        $this->phase = 'admitting';
        try {
            $this->realm();
            DB::transaction(function () use ($context, $request): void {
                TenantAiVerificationAdmission::limits($context->deadline);
                $policy = (new TenantAiVerificationPolicyResolver)->resolve($context, $request, false);
                $this->receiptKey($policy);
                $context->assertRequest();
            }, 1);
            $admitted = (new TenantAiVerificationAdmission)->admit($this, $context, $request);
            $this->policy = $admitted['policy'];
            $this->attempt = $admitted['attempt'];
            $this->requestPermit = new VerificationRequestPermit;
            $this->phase = 'jit';
            $this->secret = TenantAiVerificationSecret::load($this, $this->requestPermit, $context);
            $this->phase = 'sending';
            try {
                $this->observation = $this->secret->exchange($this, $this->requestPermit, $context);
            } finally {
                $this->secret = null;
            }
            $this->observedAt = $this->sendAuthorized ? TenantAiVerificationAdmission::instant() : null;
            $this->phase = 'finalizing';
            if ($this->observation->outcome === 'provider_authenticated_model_visible' && $this->sendAuthorized) {
                $this->promotionPermit = new CredentialPromotionPermit;

                return (new TenantAiActiveLifecycle)->promote($this, $context, $this->promotionPermit);
            }
            $this->finishFailure($this->observation->outcome);

            return new TenantAiVerificationResult('verification-rejected', $this->attempt['id']);
        } catch (\Throwable) {
            if ($this->attempt !== [] && ! in_array($this->phase, ['closed', 'finalizing'], true)) {
                try {
                    $this->phase = 'finalizing';
                    $this->finishFailure('local_policy_denied');
                } catch (\Throwable) {
                    // Retained admission is recovered without replay, secret access or refund.
                }
            }

            return new TenantAiVerificationResult('verification-unavailable', $this->attempt['id'] ?? null);
        } finally {
            $this->phase = 'closed';
            $this->requestPermit = $this->promotionPermit = $this->claimedPromotion = null;
            $this->secret = null;
            $this->observation = null;
            $this->context = null;
            $this->request = null;
            $this->policy = null;
            $this->attempt = [];
        }
    }

    public function requirePhase(TenantAiVerificationContext $context, string $phase): void
    {
        if ($this->context !== $context || $this->phase !== $phase) {
            throw new TenantAiStorageUnavailable;
        }
        $context->assertRequest();
    }

    public function realm(): string
    {
        if ($this->evidenceRealm === 'provider_tls' && $this->transport::class === TenantAiVerificationTransport::class) {
            return 'provider_tls';
        }
        if ($this->evidenceRealm !== 'offline_fixture' || PHP_SAPI !== 'cli' || ! app()->runningUnitTests()
            || ! class_exists(TestCase::class, false) || DB::getDriverName() !== 'pgsql'
            || ! str_starts_with(DB::connection()->getDatabaseName(), 'facturac_test_')) {
            throw new TenantAiStorageUnavailable;
        }

        return 'offline_fixture';
    }

    public function claimDecrypt(TenantAiVerificationContext $context, VerificationRequestPermit $permit): void
    {
        $this->requirePhase($context, 'jit');
        if ($permit !== $this->requestPermit || $this->requestStage !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $this->requestStage = 1;
    }

    public function observeSecret(TenantAiVerificationSecret $secret, VerificationRequestPermit $permit, TenantAiVerificationContext $context,
        #[\SensitiveParameter] string $plaintext): CredentialVerificationEvidence
    {
        $this->requirePhase($context, 'sending');
        if ($secret !== $this->secret || $permit !== $this->requestPermit || $this->requestStage !== 1 || $this->policy === null) {
            throw new TenantAiStorageUnavailable;
        }
        $this->requestStage = 2;

        return (new AnthropicCredentialVerificationAdapter($this->transport))->observe($this, $permit, $context, $this->policy, $plaintext);
    }

    public function authorizeSend(TenantAiVerificationContext $context, VerificationRequestPermit $permit): void
    {
        $this->requirePhase($context, 'sending');
        if ($permit !== $this->requestPermit || $this->requestStage !== 2 || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $this->requestStage = 3;
        DB::transaction(function () use ($context): void {
            TenantAiVerificationAdmission::limits($context->deadline);
            $this->fresh($context, 'sending');
            $row = $this->currentAttempt();
            if ($row->send_authorized_at !== null) {
                throw new TenantAiStorageUnavailable;
            }
            $time = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            DB::table('assistant_provider_attempts')->where('id', $row->id)->update(['send_authorized_at' => $time]);
            TenantAiVerificationAdmission::audit($context, $this->attempt, 'verification_send_authorized', 'authorized');
            $this->attempt['send_authorized_at'] = $time;
        }, 1);
        $this->sendAuthorized = true;
    }

    public function fresh(TenantAiVerificationContext $context, string $phase): TenantAiVerificationPolicy
    {
        $this->requirePhase($context, $phase);
        $this->lockCaptured();

        return $this->validateCaptured();
    }

    public function consumePromotion(TenantAiVerificationContext $context, CredentialPromotionPermit $permit): void
    {
        $this->requirePhase($context, 'finalizing');
        if ($permit !== $this->promotionPermit || $this->claimedPromotion !== null || $this->observation?->outcome !== 'provider_authenticated_model_visible') {
            throw new TenantAiStorageUnavailable;
        }
        $this->promotionPermit = null;
        $this->claimedPromotion = $permit;
    }

    /** Whole completion only; never a signer accepting caller-supplied receipt fields or outcomes. */
    public function completePromotion(TenantAiVerificationContext $context, CredentialPromotionPermit $permit): TenantAiVerificationResult
    {
        $this->requirePhase($context, 'finalizing');
        if ($permit !== $this->claimedPromotion || $this->policy === null || $this->observedAt === null || $this->observation === null
            || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $this->claimedPromotion = null;

        return DB::transaction(function () use ($context): TenantAiVerificationResult {
            TenantAiVerificationAdmission::limits($context->deadline);
            $this->lockCaptured();
            $row = $this->currentAttempt();
            $disposition = 'promoted';
            try {
                $this->validateCaptured();
                $this->authenticateActive();
            } catch (TenantAiStorageUnavailable) {
                $disposition = 'rejected_stale';
            }
            $now = TenantAiVerificationAdmission::instant();
            $expires = $this->observedAt->addSeconds(5)->min($now->addMicroseconds(max(0, (int) floor(($context->deadline - hrtime(true) / 1e9) * 1000000))))->min(CarbonImmutable::parse($row->admitted_at)->addSeconds(10))
                ->min(CarbonImmutable::parse($this->policy->profile->valid_until))->min(CarbonImmutable::parse($this->policy->approval->expires_at))
                ->min(CarbonImmutable::parse($this->policy->account['expires_at']));
            if ($this->policy->credential->expires_at !== null) {
                $expires = $expires->min(CarbonImmutable::parse($this->policy->credential->expires_at));
            }
            foreach ($this->policy->budgets as $budget) {
                $expires = $expires->min(CarbonImmutable::parse($budget->approval_expires_at));
            }
            $expires = $expires->min(CarbonImmutable::parse(config('tenant_ai.verification.receipt_keys.'.config('tenant_ai.verification.signing_key_id').'.valid_until')));
            if (! $now->lt($expires)) {
                $disposition = 'expired';
            }
            $completion = ['state' => 'usage_unknown', 'outcome' => 'usage_unknown', 'finalized_at' => TenantAiVerificationAdmission::time($now),
                'observed_at' => TenantAiVerificationAdmission::time($this->observedAt), 'verification_outcome' => $this->observation->outcome,
                'claim_strength' => 'organization_and_workspace_observed', 'observed_organization_id' => $this->observation->organization,
                'observed_workspace_id' => $this->observation->workspace, 'observed_model_id' => $this->observation->model,
                'evidence_id' => (string) Str::uuid(), 'receipt_key_id' => config('tenant_ai.verification.signing_key_id'),
                'promotion_expires_at' => TenantAiVerificationAdmission::time($expires), 'promotion_disposition' => $disposition,
                'committed_activated_generation' => $disposition === 'promoted' ? (int) $row->proposed_activated_generation : null];
            $key = $this->receiptKey($this->policy);
            try {
                $completion['receipt_mac'] = hash_hmac('sha256', TenantAiVerificationReceipt::canonical(TenantAiVerificationReceipt::fields([...(array) $row, ...$completion])), $key);
            } finally {
                unset($key);
            }
            DB::table('assistant_provider_attempts')->where('id', $row->id)->update($completion);
            $this->unknownCounters();
            if ($disposition === 'promoted') {
                $this->rotate($now);
            }
            TenantAiVerificationAdmission::audit($context, [...$this->attempt, 'evidence_id' => $completion['evidence_id']], 'verification_succeeded', 'provider_authenticated_model_visible', false);
            TenantAiVerificationAdmission::audit($context, $this->attempt, $disposition === 'promoted' ? 'credential_promoted' : 'promotion_rejected', $disposition, false);
            if ($disposition === 'promoted') {
                TenantAiVerificationAdmission::audit($context, $this->attempt, 'selection_changed', 'active');
            }
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            $context->assertRequest();
            if ($disposition === 'promoted') {
                $context->authorize();
                if (! TenantAiVerificationAdmission::instant()->lt($expires)
                    || $this->policy->configurationDigest !== hash('sha256', json_encode(config('tenant_ai.verification'), JSON_THROW_ON_ERROR))) {
                    throw new TenantAiStorageUnavailable;
                }
            }
            $this->phase = 'closed';

            return new TenantAiVerificationResult($disposition === 'promoted' ? 'verified-and-active' : 'credential-state-changed', $row->id);
        }, 1);
    }

    private function validateCaptured(): TenantAiVerificationPolicy
    {
        if ($this->context === null || $this->request === null || $this->policy === null) {
            throw new TenantAiStorageUnavailable;
        }
        $fresh = (new TenantAiVerificationPolicyResolver)->resolve($this->context, $this->request, false);
        $snapshot = (new TenantAiVerificationAdmission)->snapshot($this->context, $fresh, $this->request);
        foreach ($snapshot as $field => $value) {
            if (! array_key_exists($field, $this->attempt) || $this->attempt[$field] !== $value) {
                throw new TenantAiStorageUnavailable;
            }
        }
        if ($fresh->configurationDigest !== $this->policy->configurationDigest) {
            throw new TenantAiStorageUnavailable;
        }
        $this->receiptKey($fresh);

        return $fresh;
    }

    private function lockCaptured(): void
    {
        if ($this->policy === null || DB::transactionLevel() !== 1) {
            throw new TenantAiStorageUnavailable;
        }
        foreach ($this->policy->controls as $row) {
            DB::table('ai_gateway_controls')->where('id', $row->id)->lockForUpdate()->firstOrFail();
        }
        foreach ($this->policy->budgets as $row) {
            DB::table('assistant_provider_controls')->where('budget_id', $row->budget_id)->lockForUpdate()->firstOrFail();
        }
        DB::table('tenant_ai_settings')->where('workspace_id', $this->attempt['workspace_id'])->lockForUpdate()->firstOrFail();
        DB::table('tenant_ai_connections')->where('id', $this->attempt['connection_id'])->lockForUpdate()->firstOrFail();
        foreach ($this->policy->credentials as $row) {
            DB::table('tenant_ai_credentials')->select('id')->where('id', $row->id)->lockForUpdate()->firstOrFail();
        }
        DB::table('assistant_provider_tenants')->where('id', $this->attempt['owner_approval_id'])->lockForUpdate()->firstOrFail();
        DB::table('assistant_provider_acknowledgements')->where('id', $this->attempt['acknowledgement_id'])->lockForUpdate()->firstOrFail();
        foreach ($this->windows() as $window) {
            DB::table('assistant_provider_windows')->where('id', $window->id)->lockForUpdate()->firstOrFail();
        }
        $this->currentAttempt();
    }

    /** @return list<\stdClass> */
    private function windows(): array
    {
        $rows = DB::table('assistant_provider_windows as w')->useWritePdo()->select('w.*')
            ->join('assistant_provider_allocations as a', 'a.window_id', '=', 'w.id')->where('a.attempt_id', $this->attempt['id'])
            ->orderBy('w.budget_id')->orderByRaw("CASE w.scope WHEN 'deployment_month' THEN 1 WHEN 'deployment_day' THEN 2 WHEN 'workspace_month' THEN 3 WHEN 'workspace_day' THEN 4 ELSE 5 END")
            ->orderByRaw('w.scope_key COLLATE "C"')->orderBy('w.window_start')->limit(10)->get()->values()->all();
        if (count($rows) !== 9) {
            throw new TenantAiStorageUnavailable;
        }

        return array_values($rows);
    }

    private function currentAttempt(): \stdClass
    {
        $row = DB::table('assistant_provider_attempts')->where('id', $this->attempt['id'])->where('contract_version', 'gateway_v1')
            ->where('purpose', 'connection_probe')->where('workspace_id', $this->attempt['workspace_id'])->where('deployment_id', $this->attempt['deployment_id'])
            ->lockForUpdate()->first();
        if ($row === null || $row->state !== 'admitted') {
            throw new TenantAiStorageUnavailable;
        }

        return $row;
    }

    private function unknownCounters(): void
    {
        foreach ($this->windows() as $row) {
            DB::table('assistant_provider_windows')->where('id', $row->id)->update([
                'unknown_usage_count' => TenantAiVerificationAdmission::add((int) $row->unknown_usage_count, 1)]);
        }
    }

    private function receiptKey(TenantAiVerificationPolicy $policy): string
    {
        $id = config('tenant_ai.verification.signing_key_id');
        if (! is_string($id)) {
            throw new TenantAiStorageUnavailable;
        }

        return (new TenantAiVerificationReceiptKeyFile)->read($id, $policy->connection->deployment_id, $policy->manifest['verifier_policy_sha256'], true);
    }

    private function authenticateActive(): void
    {
        $active = $this->policy?->active;
        if ($active === null) {
            return;
        }
        $receipt = DB::table('assistant_provider_attempts')->useWritePdo()->where('id', $active->verification_operation_id)->first();
        if ($receipt === null || $receipt->contract_version !== 'gateway_v1' || $receipt->purpose !== 'connection_probe'
            || $receipt->promotion_disposition !== 'promoted' || $receipt->credential_version_id !== $active->id
            || $receipt->connection_id !== $this->attempt['connection_id'] || $receipt->workspace_public_id !== $this->attempt['workspace_public_id']
            || $receipt->legal_entity_public_id !== $this->attempt['legal_entity_public_id'] || $receipt->deployment_id !== $this->attempt['deployment_id']
            || $receipt->profile_id !== $this->attempt['profile_id'] || $receipt->account_mapping_sha256 !== $this->attempt['account_mapping_sha256']
            || $receipt->evidence_realm !== $this->realm() || (int) $receipt->committed_activated_generation !== (int) $active->activated_generation
            || $receipt->credential_created_at !== $active->created_at || (int) $receipt->credential_version_number !== (int) $active->version_number) {
            throw new TenantAiStorageUnavailable;
        }
        $key = (new TenantAiVerificationReceiptKeyFile)->read($receipt->receipt_key_id, $receipt->deployment_id, $receipt->verifier_policy_sha256);
        if (! TenantAiVerificationReceipt::authentic(TenantAiVerificationReceipt::fields((array) $receipt), $receipt->receipt_mac, $key)) {
            throw new TenantAiStorageUnavailable;
        }
    }

    private function rotate(CarbonImmutable $now): void
    {
        $time = TenantAiVerificationAdmission::time($now);
        $active = $this->policy->active;
        if ($active !== null) {
            DB::table('tenant_ai_credentials')->where('id', $active->id)->update(['state' => 'replaced', 'replaced_at' => $time, 'revoked_at' => $time,
                'secret_destroyed_at' => $time, 'secret_ciphertext' => null, 'wrapped_dek' => null, 'kek_version' => null, 'updated_at' => $time]);
            foreach (['credential_replaced', 'credential_destroyed'] as $event) {
                TenantAiVerificationAdmission::audit($this->context, [...$this->attempt, 'credential_version_id' => $active->id], $event, 'replaced');
            }
        }
        DB::table('tenant_ai_credentials')->where('id', $this->attempt['credential_version_id'])->update([
            'state' => 'active', 'verification_state' => 'verified', 'verified_profile_id' => $this->attempt['profile_id'],
            'verification_operation_id' => $this->attempt['id'], 'last_test_operation_id' => $this->attempt['id'],
            'last_verified_at' => TenantAiVerificationAdmission::time($this->observedAt), 'last_tested_at' => TenantAiVerificationAdmission::time($this->observedAt),
            'last_test_outcome' => 'success', 'activated_generation' => $this->attempt['proposed_activated_generation'], 'updated_at' => $time]);
        DB::table('tenant_ai_connections')->where('id', $this->attempt['connection_id'])->update([
            'active_generation' => $this->attempt['proposed_activated_generation'], 'revision' => $this->request->connectionRevision + 1, 'updated_at' => $time]);
        DB::table('tenant_ai_settings')->where('workspace_id', $this->attempt['workspace_id'])->update([
            'credential_version_id' => $this->attempt['credential_version_id'], 'revision' => $this->request->settingsRevision + 1, 'updated_at' => $time]);
    }

    private function finishFailure(string $outcome): void
    {
        if ($this->context === null || DB::transactionLevel() !== 0) {
            throw new TenantAiStorageUnavailable;
        }
        $this->requirePhase($this->context, 'finalizing');
        DB::transaction(function () use ($outcome): void {
            TenantAiVerificationAdmission::limits($this->context->deadline);
            $this->lockCaptured();
            $row = $this->currentAttempt();
            $current = false;
            try {
                $this->validateCaptured();
                $current = true;
            } catch (TenantAiStorageUnavailable) {
                // Failure evidence persists without granting stale lifecycle authority.
            }
            $now = TenantAiVerificationAdmission::time(TenantAiVerificationAdmission::instant());
            $observed = $this->sendAuthorized && $this->observedAt !== null ? TenantAiVerificationAdmission::time($this->observedAt) : null;
            DB::table('assistant_provider_attempts')->where('id', $row->id)->update(['state' => $this->sendAuthorized ? 'usage_unknown' : 'failed',
                'outcome' => 'usage_unknown', 'verification_outcome' => $outcome, 'observed_at' => $observed, 'finalized_at' => $now, 'promotion_disposition' => 'not_applicable']);
            $this->unknownCounters();
            if ($current) {
                $metadata = ['last_test_operation_id' => $row->id, 'last_tested_at' => $observed ?? $now,
                    'last_test_outcome' => match ($outcome) {
                        'credential_rejected' => 'auth_failed', 'model_unavailable', 'malformed_response', 'account_context_unproven' => 'protocol_failed',
                        'provider_unavailable' => 'unavailable', default => 'unknown',
                    }, 'updated_at' => $now];
                if ($outcome === 'credential_rejected') {
                    $metadata['verification_state'] = 'failed';
                }
                DB::table('tenant_ai_credentials')->where('id', $row->credential_version_id)->where('state', 'pending')->update($metadata);
                DB::table('tenant_ai_connections')->where('id', $row->connection_id)->update(['revision' => $this->request->connectionRevision + 1, 'updated_at' => $now]);
            }
            TenantAiVerificationAdmission::audit($this->context, $this->attempt, 'verification_failed', $outcome, false);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        }, 1);
        $this->phase = 'closed';
    }

    private function __clone() {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['verification' => 'restricted'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new TenantAiStorageUnavailable;
    }
}
