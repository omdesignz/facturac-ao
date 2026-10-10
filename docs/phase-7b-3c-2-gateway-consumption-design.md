# Phase 7B.3c-2 — gateway credential resolution and invocation-authority design

Date: 2026-10-09. **Design only.** No runtime code, test, migration, dependency, configuration or frozen contract was changed. No provider request, live credential, provisioning or activation occurred. Provider verification and inference remain hard-disabled in checked-in configuration.

This document executes the handoff in §13.17 of the [control-plane supplement](phase-7b-3-tenant-ai-control-plane-supplement.md) and converts its §13.11 and §13.14 obligations into the smallest concrete consumer contract. "Astra" and "Sol" in the governing documents name the design/review and implementation responsibilities; no model switch is claimed.

## 1. Authority, baseline verification and limits

Governing text, in precedence order: the supplement's VC1 (§13, especially §13.5, §13.8, §13.11, §13.12, §13.14), CM1 (§3.3.1), §§6–9, the [gateway contract](phase-7b-multi-provider-ai-gateway-contract.md) §§3–5, §9 and §15, the [3c-1 design](phase-7b-3c-1-provider-verification-design.md) §10 and §18 (CR1), and the [3c-1 final checkpoint](phase-7b-3c-1-final-checkpoint.md).

**Checkpoint verification executed for this design (2026-10-09):**

| Check | Result |
| --- | --- |
| 802-file source inventory in the checkpoint evidence, SHA-256 per file | 802 present, 0 missing, **0 drift** |
| 14 frozen hashes | **14/14 exact** |
| 7 dependency/config hashes (composer, npm, phpunit, phpstan, pint) | 7/7 exact |
| Checkpoint report and project-plan hashes | both matched before this design touched the plan |
| Recorded `git_head` | `648703f…`; current HEAD is `4e77285…` |

The HEAD difference is one commit made after the checkpoint, at the owner's explicit request, containing 20 files under `videos/` only (marketing teaser assets). No inventoried path is under `videos/`; the content inventory, which the checkpoint declares authoritative over Git HEAD, is unchanged. The rest of the working tree is still heavily uncommitted and was not reset, stashed, discarded, committed or pushed.

The project plan (`README.md`) had SHA-256 `e25f059f129225182885d90805aebf1f715b16a583fe9c7704632fa18ac67330` at the checkpoint. This design appends one subsection to it (recorded in §18), so that file's hash now differs from the checkpoint record by design. The checkpoint evidence is not edited.

**Evidence limits preserved, not improved:** the checkpoint is a partitioned SQLite manifest and a broad concurrency run with one error followed by explicit reruns; two intermediate setup-migration logs are unavailable as raw bytes. It is not one uninterrupted clean execution, and this design does not represent it as one. M1, M2, the historical reconciliation, R2, R3 and CR1 counterexamples are treated as resolved on current evidence and are not reopened.

**What this design did and did not execute.** Executed: hash verification above; source inspection of the gateway, transport, ledger, verification, custody and lifecycle classes named in §3; inspection of the installed Laravel AI SDK (`laravel/ai` v0.11.2 per `composer.lock`) and of `Illuminate\Http\Client`; one local runtime probe (PHP 8.5.10, libcurl 8.22.0, `CURLOPT_PREREQFUNCTION` defined). Not executed: any test suite, any migration, any PostgreSQL experiment, any network request. Every acceptance item in §13 is a **future requirement, NOT RUN**.

## 2. Decisions

| Question | Decision |
| --- | --- |
| How is the credential route chosen? | Deterministically from primary `tenant_ai_settings` for the explicit workspace and the trusted deployment. Five closed routes (§4). Never from caller, model or browser input. |
| Fallback between routes | **None.** An unavailable customer route denies. It never becomes VAP, legacy, another version, provider, model or account. |
| VAP route | The accepted legacy adapter and its protected deployment secret reference, unchanged. No customer table is read on this route. |
| Customer route authority | Selected version is `active`, envelope present, unexpired, with an authentic promoted receipt whose committed generation equals the credential's and the connection's current generation, and the full immutable binding tuple matches (§5.2). |
| Does an active row or receipt grant invocation? | **No.** A separate request-bound, one-use `CredentialInvocationPermit` is issued only by a live invocation session after admission (§5.3). |
| Decryption | Just-in-time, purpose `assistant_intent`, exact admitted version, inside a short transaction, after admission and before DNS (§7 step 5). |
| Linearization point | The **commit** of the short prerequisite transaction run from the pinned transport's cURL prerequisite callback, after connect/TLS and before request bytes (§8). |
| Locks across the provider exchange | None. |
| Tool ceiling | The five CM1 flags, filtered per tool (not per permission), at planner schema, whole-plan validation, each local read and final disclosure. |
| SDK | Keep the accepted per-invocation `AnthropicGateway` subclass and isolated HTTP factory. `AiManager` stays bound to refuse. No secret in manager, config, cache, session or queue (§11). |
| Supported runtime for customer invocation | Synchronous PHP-FPM web request only. Console, queue and long-lived worker runtimes deny. |
| Schema | The frozen schema refuses customer inference. One additive successor migration is demonstrably necessary and is proposed in §12, behind the same pre-execution DDL review gate 3c-1 used. |
| Genuine design blockers | **None** (§16). |

## 3. Current implementation and reuse mapping

Line references are to the working tree verified in §1.

| Existing component | What it does today | 3c-2 disposition |
| --- | --- | --- |
| `VapAiGateway`, `AiInferenceRequest`, `AiInferenceResult`, `AiGatewayFailure` | Application-owned intent boundary; result is an untrusted plan or a payload-free failure. | **Reuse unchanged.** The new resolver is one more `VapAiGateway` implementation. |
| `GatewayAssistantPlanner`, `AssistantPlanner` binding (`AppServiceProvider` lines 43–49) | Planner port delegates to the bound gateway only when `assistant.provider.enabled` is true. | Rebind `VapAiGateway` to the resolver; widen the planner switch to the two route switches (§12.2). |
| `LegacyAssistantAiGateway` → `AnthropicIntentPlanner` | Accepted VAP path: gates, protected-file secret, legacy permit, SDK bridge, legacy ledger. | **Reuse unchanged** as the adapter for both VAP routes. |
| `AssistantInteractionContext`, `AssistantProviderInvocation` | Explicit workspace/entity/production actor context; `fresh()` re-reads membership from primary; `permissions()` is the original∩current intersection; 10-second budget; request/route binding. | **Reuse** as the actor authority. Small generalization of the retained post-response authority (§12.2). |
| `AssistantPlan`, `ProviderIntentInput`, `AssistantInteraction`, `AssistantTools` | Original-question-only projection; whole-plan validation; fresh permission before each read and before disclosure; tool results never leave the server. | **Reuse.** Add an optional per-tool ceiling; with no ceiling behaviour is byte-identical (§5.4). |
| `AssistantIntentGateway` | The only SDK import boundary: anonymous `AnthropicGateway` subclass per call, exact body, header allowlist, original-response validation before SDK parsing. | **Reuse**, generalized over one narrow authority interface (§5.5). Body construction and validation unchanged. |
| `AssistantProviderTransport::wire()`, `ResolvesAnthropicAddress`, `AssistantProviderResponseBuffer`, `AssistantProviderResponse` | Pinned-address cURL, TLS 1.2+, no proxy/redirect/reuse, bounded streaming parse, prerequisite callback. `wire()` already takes only body, key, deadline, buffer and a readiness closure. | **Reuse byte-identical.** Only the public `exchange()` entry gains the authority-interface form. |
| `AssistantProviderLedger` | Legacy reserve/finalize/recover, root-fenced through `TenantAiLegacyAccounting`. | **Unchanged**; remains the ledger for VAP routes. |
| `TenantAiVerificationAdmission` | Gateway-v1 admission: canonical locks, nine immutable allocations, `limits()`, `instant()`, `time()`, `add()`, `ceiling()`. | **Reuse its public primitives**; the invocation admission is a sibling using the same allocation set and helpers. No second ledger. |
| `TenantAiVerificationPolicyResolver`, `TenantAiVerificationPolicy` | Resolves and optionally locks the full tuple for `connection_probe`. | Pattern reused by a sibling resolver for purpose `assistant_intent`; the verification resolver stays byte-identical. |
| `TenantAiVerificationReceipt`, `TenantAiVerificationReceiptKeyFile` | Pure canonical encoding, `authentic()`, protected receipt-key custody. | **Reuse unchanged** to authenticate the active credential's receipt. |
| `TenantAiVerificationSecret`, `TenantAiKeyFile`, `TenantAiEnvelope` | Restricted one-operation AES-GCM envelope consumer bound to the verifier session; KEK custody. | Custody files stay byte-identical. A sibling consumer applies the **same** format for the invocation purpose (§5.6). |
| `TenantAiActiveLifecycle`, `TenantAiLifecycle` | Promote, candidate, exact revoke, connection/workspace disable; each bumps revision and, for active authority, generation. | **Unchanged.** Their revision/generation writes are exactly what the prerequisite transaction detects. |
| `TenantAiCapabilities::COLUMNS` | CM1 static tool→column map, not yet connected to admission. | **Reuse** as the single tool-ceiling source. |
| `RequiredAudit`, `AssistantAudit` | Mandatory transactional audit. | **Reuse**; two events already reserved by §13.9. |
| `RecoverAssistantProviderAttempts`, `TenantAiVerificationRecovery` | Bounded recovery, never resend or refund. | Extend purpose-aware recovery to `assistant_intent` (§9.5). |

**Schema fact that shapes the design.** `TenantAiVerificationSchema` (frozen) admits gateway-v1 attempts only when `ownership_kind='customer_managed' AND purpose='connection_probe' AND vap_reference_id IS NULL` with `request_bytes=0` and null token/money actuals (attempt shape check, lines 386–388 and following); admits gateway-v1 owner approvals only for `purpose='connection_probe'` (lines 335–346); freezes token and money actuals on gateway attempts (lines 462–468); and its credential guard raises `Customer inference unavailable` whenever `last_used_at` is non-null (line 547). Customer inference therefore cannot be admitted, finalized or marked as used under the frozen schema. This is the intended 3c-1 boundary, not a defect.

## 4. Deterministic resolution

The resolver reads, from the primary and without locks, the `tenant_ai_settings` row for the invocation's workspace and `config('tenant_ai.deployment_id')`. Missing or mismatched deployment denies. Admission later reloads the same rows under locks and must agree.

| Settings state | Route | Credential source | Result when a requirement fails |
| --- | --- | --- | --- |
| No row | `legacy_ungoverned` | Accepted legacy adapter and its own gates, byte-identical to today. | Legacy behaviour (default-deny). |
| `mode=disabled` | none | none | `ai_disabled`. Overrides all five flags and any legacy approval. |
| `mode=vap_managed` | `vap_managed` | Same legacy adapter and protected deployment reference. Additionally requires the control-plane gates of §6 that do not concern a customer credential, the selected profile equal to the accepted VAP profile (`allows_vap`), and the tool ceiling. | `vap_unavailable`. Never reads `tenant_ai_connections` or `tenant_ai_credentials`. |
| `mode=customer_managed`, `credential_version_id` null | none | none | `credential_pending`. |
| `mode=customer_managed`, version selected | `customer_managed` | Exactly the selected version on the selected connection. | One of the closed reasons below. Never VAP, legacy, a pending candidate, a replaced version or another connection. |

Closed customer-route unavailable reasons (internal enum; the public response stays the fixed payload-free failure): `route_disabled`, `profile_unsupported`, `connection_unavailable`, `credential_not_active`, `credential_expired`, `receipt_untrusted`, `generation_mismatch`, `binding_mismatch`, `entitlement_missing`, `approval_missing`, `acknowledgement_missing`, `control_blocked`, `quota_exceeded`, `authority_changed`, `deadline_exceeded`, `runtime_unsupported`.

Rules:

- The gateway accepts no credential, connection, version, provider, model, URL, header or client from the caller or the model. The only inputs are the existing `AssistantProviderInvocation` and the resolver's own primary reads.
- The route is resolved **once** per interaction and bound into the permit. Any later difference invalidates the interaction; nothing is re-resolved.
- `legacy_ungoverned` exists because the supplement (§7) states that default-disabled gateway controls do not migrate or deactivate legacy tenants. It is a route for workspaces that never created a settings row, not a fallback: once a row exists it can never be reached again for that workspace.
- `profile_unsupported`: the accepted SDK bridge builds one exact body from `AssistantProviderProfile` constants. In 3c-2 a customer profile is admissible only if its provider, model and response model, protocol, endpoint policy, envelopes, byte limits, deadline and price keys equal those accepted constants. Any other profile needs a separately reviewed adapter parameterization and is denied until then.

## 5. Authority objects and interfaces

Names follow sibling conventions and may be adjusted; semantics may not.

### 5.1 Route

```php
enum AiCredentialRoute { case LegacyUngoverned; case VapManaged; case CustomerManaged; }

final class TenantAiGateway implements VapAiGateway   // bound in place of LegacyAssistantAiGateway
{
    public function infer(AiInferenceRequest $request): AiInferenceResult;
}
```

`infer()` resolves the route, delegates the two VAP routes to `LegacyAssistantAiGateway` (with the tool ceiling for `VapManaged`), and runs one `TenantAiInvocationSession` for `CustomerManaged`. Every failure maps to the existing `AiGatewayFailure` values; no reason text, revision, account or provider detail leaves the gateway.

### 5.2 Authentic active credential

For the customer route, both at admission (under locks) and again in the prerequisite transaction:

1. `settings.credential_version_id` selects a row of that connection with `state='active'`, `verification_state='verified'`, `secret_destroyed_at` null, envelope present, `encryption_schema=1`, and `expires_at` null or in the future by primary time.
2. `credential.activated_generation = connection.active_generation` and both are positive.
3. The attempt `credential.verification_operation_id` exists, is `gateway_v1`/`connection_probe`, `promotion_disposition='promoted'`, and its signed tuple equals today's: credential ID, version number and creation timestamp, connection, workspace public ID, legal-entity public ID, deployment, profile ID and manifest digest, provider, credential family, endpoint policy, account-mapping digest, and `committed_activated_generation = credential.activated_generation`.
4. `TenantAiVerificationReceipt::authentic(fields(row), receipt_mac, key)` is true with the key from `TenantAiVerificationReceiptKeyFile::read(receipt_key_id, deployment, verifier_policy_sha256)`. Unknown, retired or missing trust key denies.
5. `evidence_realm='provider_tls'`. `offline_fixture` is accepted only under the same dev-only harness conditions the verifier already enforces.

Per §13.5, the receipt's historical admission revisions are **not** compared with today's connection or settings revisions. Today's revisions are captured fresh in the permit instead. A forged, malformed or unpromoted receipt makes the row unusable regardless of database flags (`receipt_untrusted`).

The receipt binds one legal entity. The invocation's entity must equal it; another entity of the same workspace needs its own approval and receipt-bound grant and is denied (`binding_mismatch`).

### 5.3 Invocation session and permit

```php
/** One transient, closed invocation. Persisted data cannot recreate its object authority. */
final class TenantAiInvocationSession implements AiSendAuthority
{
    public function run(AssistantProviderInvocation $invocation): string;   // returns the untrusted local plan
}

/** Object identity is meaningful only while registered in its private invocation session. */
final class CredentialInvocationPermit {}   // no state, not cloneable, not serializable, redacted debug info
```

The session, not the permit object, holds the bound state: interaction ID, the `AssistantProviderInvocation` instance, PID, Fiber, actor A1 and membership, admitted permission list and tool ceiling, workspace/entity/production/deployment, route, profile and manifest digest, provider/account/endpoint, connection, credential version and activated generation, settings and connection revisions, entitlement revision, sorted control/budget/approval references with revisions, disclosure and acknowledgement IDs, attempt ID and its nine allocation window IDs, SHA-256 of the exact wire body, the configuration digest, and the monotonic deadline.

Properties, mirroring the verifier session:

- The session owns a private in-memory registry of the one permit it issued. Only its private post-admission path registers it. No public factory accepts a row, DTO, UUID, receipt, outcome or `active=true`.
- Stages advance one way and never repeat: `issued → decrypt_claimed → exchange_started → send_authorized → closed`. A stage is consumed even if its transaction rolls back.
- Every use checks the same invocation object, request, PID, Fiber and deadline. A correctly shaped unregistered permit fails.
- **Non-interchangeable by construction:** `TenantAiCredentialVerifier` accepts only its own `VerificationRequestPermit` and `CredentialPromotionPermit`; the invocation session accepts only its own `CredentialInvocationPermit`. Distinct final classes, distinct private registries, no shared base type or interface. A verification permit presented to an inference consumer fails the type and registry checks, and the reverse.
- One send maximum. A second prerequisite callback for the same permit aborts the transfer.
- No singleton, static registry or container binding. The session is constructed per interaction and discarded in `finally`.

### 5.4 Actor authority and the five-tool ceiling

Fresh actor authority is the existing mechanism, unchanged: `AssistantInteractionContext::fresh()` and `AssistantProviderInvocation::permissions()` (original ∩ current, primary reads, impersonation and automation refused).

The tool ceiling is new and exact. For tool `t` in {`searchCustomers`, `getCustomer`, `getFiscalDocumentSummary`, `getQualifiedAgtStatus`, `getMonthlyRecordedBilling`}:

`allowed(t) = TenantAiCapabilities::column(t) is a column whose value is exactly true in the admitted settings row AND AssistantPlan::PERMISSIONS[t] ∈ original ∩ current permissions`

An unknown tool, missing mapping, null or non-boolean value is denied. The admitted tool set is captured at admission and can only shrink.

| Enforcement point | Requirement |
| --- | --- |
| Planner-visible schema (`AssistantPlan::plannerInput`, `ProviderIntentInput::fromInput`) | Only admitted tools appear. Filtering by permission is insufficient: `searchCustomers` and `getCustomer` share `customers.read` and must be filtered independently. An empty set denies before admission. |
| Whole-plan validation (`AssistantPlan::fromJson`, `ProviderIntentInput::localPlan`) | Every proposed call's tool must be admitted; one disallowed call rejects the entire plan before any read. |
| Each local read (`AssistantInteraction`) | Tool still admitted, session `recheck()` passes, then the existing `fresh(permission)`. |
| Final disclosure | Same recheck for every executed tool. |

The ceiling is an optional parameter. With no ceiling (`legacy_ungoverned`) all four call sites behave exactly as today. Active credential eligibility never widens role, tool scope, financial access or entity grant.

### 5.5 One generalized send seam

The accepted bridge and transport are typed to the legacy permit and ledger. Rather than duplicating them, one narrow request-local interface carries the callbacks they already make:

```php
interface AiSendAuthority   // not serializable; implementations are request-local
{
    public function input(): ProviderIntentInput;
    public function body(): string;                 // admitted canonical bytes
    public function matchesBody(#[\SensitiveParameter] string $body): bool;
    public function consume(): void;                // one use, before any wire work
    public function recheck(): void;                // non-locking fresh gates + deadline
    public function authorizeSend(): void;          // final pre-egress authorization
    public function transmissionStarted(): bool;
    /** @param array{input:int,output:int}|null $usage */
    public function finalize(?array $usage, bool $received, bool $anomaly, bool $notSent): void;
}
```

- A legacy implementation wraps the existing `AssistantProviderPermit` and `AssistantProviderLedger` and performs **the same calls in the same order** as today. Its `authorizeSend()` is today's in-memory `markTransmission()`.
- The customer implementation is the invocation session. Its `authorizeSend()` is the durable linearization transaction of §8.
- `AssistantIntentGateway::execute` and `AssistantProviderTransport::exchange` call the interface. `wire()`, the body builder, the header allowlist, the response buffer and the response parser are not edited.
- The key format check stays the legacy `^[a-zA-Z0-9_-]{16,512}$`. The stored envelope allows printable ASCII; a customer secret outside the stricter set is denied before DNS, so no header injection is possible.

### 5.6 Purpose-bound secret consumer

```php
/** Restricted one-operation consumer of the existing AES-GCM envelope; purpose assistant_intent only. */
final class TenantAiInvocationSecret
{
    public static function load(TenantAiInvocationSession $session, CredentialInvocationPermit $permit): self;
    public function exchange(TenantAiInvocationSession $session, CredentialInvocationPermit $permit): string; // plan
}
```

Same format, KEK rules and parser constraints as `TenantAiVerificationSecret`: sensitive raw-PDO select of the four envelope columns by exact `(id, connection_id, workspace_id, deployment_id)`; binding reconstructed independently from the fresh context; `TenantAiKeyFile::read`; exact key sets; 32-byte DEK; `rejectVapCopy`. No `decrypt(id): string`, no plaintext accessor, no Eloquent hydration, no APP_KEY fallback. The value lives in a `SensitiveParameterValue`, is handed to exactly one exchange and nulled in `finally`.

The 3a/3b/3c-1 custody and verifier files stay byte-identical, so the fixed envelope parser (about 25 lines) is restated in this sibling rather than extracted from frozen code. Drift is controlled by a mandatory differential test: identical ciphertext fixtures must open, and identical tampered fixtures must fail, through both consumers.

## 6. Independent gates

Each row is evaluated separately; none implies another. **A** = admission transaction (locked), **J** = JIT decrypt transaction, **S** = send-prerequisite transaction (locked, the linearization), **P** = after response, **T** = before each tool and before final disclosure. P and T are non-locking primary reads.

| Gate | Source of truth | A | J | S | P | T |
| --- | --- | --- | --- | --- | --- | --- |
| Route switches | `assistant.enabled`; `tenant_ai.inference.enabled`; `tenant_ai.inference.anthropic_egress_enabled` (proposed, default false) | ✓ | ✓ | ✓ | ✓ | ✓ |
| Root, provider and model controls | `ai_gateway_controls`: enabled, not circuit-blocked, approval unexpired, revision captured | ✓ | ✓ | ✓ | ✓ | ✓ |
| Actor | `AssistantInteractionContext::fresh()`, permission intersection ⊇ admitted set, request/PID/Fiber | ✓ | ✓ | ✓ | ✓ | ✓ |
| Tenant mode and selection | `tenant_ai_settings`: mode, profile, connection, version, revision | ✓ | ✓ | ✓ | ✓ | ✓ |
| Tool ceiling | Five flags of the admitted settings revision | ✓ | – | ✓ | ✓ | ✓ |
| Profile | `ai_model_profiles` row equals the code manifest digest, `allows_customer`, validity window, legacy-wire equivalence | ✓ | ✓ | ✓ | ✓ | – |
| Connection | enabled, not revoked, customer-owned, revision and `active_generation` captured | ✓ | ✓ | ✓ | ✓ | ✓ |
| Credential and receipt | §5.2 in full | ✓ | ✓ | ✓ | state only | state only |
| Account entitlement | `settings.entitlement_revision > 0`; code/deployment-reviewed inference account grant for this workspace, entity, connection, profile and purpose, unexpired; its mapping digest equals the receipt's | ✓ | ✓ | ✓ | ✓ | – |
| Owner approval | `assistant_provider_tenants` gateway-v1, `purpose='assistant_intent'`, exact entity/production/deployment/profile/account budget/connection/selection revision/entitlement revision/disclosure, unexpired, unrevoked, approving owner still an active owner | ✓ | ✓ | ✓ | ✓ | ✓ |
| Disclosure and acknowledgement | Inference disclosure ID from the grant; acting member's own unrevoked gateway-v1 acknowledgement of exactly that disclosure | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admission | One attempt per interaction; nine immutable allocations | ✓ | – | attempt still `admitted`, send mark null | – | – |
| Quota | Existing `AssistantBudget` limits upstream (unchanged); shared usage windows; tenant attempt/output caps | ✓ | – | – | – | – |
| Budget | Conservative profile reservation against customer aggregate and account windows; tenant money caps; budget controls enabled and unexpired | ✓ | – | controls only | – | – |
| Kill switches | Any circuit on root/provider/model/usage/aggregate/account; `connection.disabled`; mode disabled | ✓ | ✓ | ✓ | ✓ | ✓ |
| Deadline | 10 s planner, 30 s interaction, 8 s wire; monotonic clock and primary time for persisted expiries | ✓ | ✓ | ✓ | ✓ | ✓ |

Notes:

- The inference disclosure and acknowledgement are **not** the verification ones. §13.9 states verification disclosure is not inference consent; VAP and customer acknowledgements never substitute for each other.
- Verification approval and inference approval are different purposes on different rows. A successful verification satisfies no inference gate.
- The acknowledgement is per acting member. The approval is per owner. Neither is created by this stage; offline tests supply synthetic rows through fixtures, as 3c-1 did.
- Reservation is the unchanged conservative amount (552,816 micro-USD for the accepted envelope), never refunded on failure, unsent or timeout.

## 7. Ordered invocation flow (customer route)

Transaction and secret boundaries are explicit. "Canonical order" is §9.1.

1. **Context (no transaction).** `AssistantInteraction` creates the invocation as today. `requireHttp()`; runtime check (§11.3); existing `AssistantBudget` consumption and lease are unchanged and precede everything here.
2. **Resolve (non-locking primary reads).** Determine the route (§4). Compute the admitted permission list and tool set. Build `ProviderIntentInput` from the **original question only** and the admitted tools, then the exact body with the accepted builder; estimate with `AssistantProviderProfile::estimate`. No tool result, history or business record can enter: the projection type accepts none.
3. **Pre-check (short read transaction).** Resolve the full policy without locks and authenticate the receipt, so an obviously ineligible interaction denies before reserving.
4. **Admit (transaction 1, canonical locks).** `limits()`; lock root → provider/model controls → budgets → settings → connection → pending and active credentials (ID columns only) → owner approval → acknowledgement; reload and validate every gate in column A; insert-or-ignore then lock the nine windows; enforce ceilings; insert the `assistant_intent` attempt and its nine allocations; audit `assistant.ai.invocation_credential_selected` (outcome `admitted`); `SET CONSTRAINTS ALL IMMEDIATE`; commit. **No decrypt and no network before this commit.** On denial after policy resolution, audit `invocation_credential_denied` in its own short transaction; nothing is reserved.
5. **Issue permit (memory).** The session registers one `CredentialInvocationPermit`.
6. **JIT decrypt (transaction 2, short).** Claim the decrypt stage; `limits()`; re-resolve with locks in canonical order and compare with the admitted snapshot (column J); read the four envelope columns through the sensitive PDO path; unwrap and decrypt; commit and release all locks. Failure consumes the permit and denies. **Plaintext now exists in one `SensitiveParameterValue` in this request only.**
7. **Exchange (no transaction, no lock).** `consume()`; resolve and pin one validated public address; connect and complete TLS within the existing bounds.
8. **Send prerequisite (transaction 3, canonical locks) — the linearization, §8.** Runs inside the cURL prerequisite callback after the remote address and port match the pin. On commit, libcurl sends the request once.
9. **Observe (no lock).** Bounded streaming read; original-response validation; usage extraction. Plaintext and all SDK objects are released in `finally` here, on every outcome.
10. **Post-response gate (P).** `recheck()`; then translate and validate the complete plan against the admitted tool set. Failure discards the plan.
11. **Finalize (transaction 4).** Locks root → budgets → the attempt's nine windows → attempt. Reads the **persisted** allocations, never today's settings. Writes usage or unknown, terminal state, counters and audit. Does not require current authority, so it succeeds after revoke or disable.
12. **Local execution (T).** Existing loop: for each call, tool admitted + `recheck()` + `fresh(permission)` + the capability's own authorization, then the deterministic local read. Final disclosure repeats the checks. Answers are assembled locally from tool results; the model is never called again and never sees them.

Secret lifetime is bounded by steps 6–9: at most the remaining planner budget, in practice under ten seconds, in one process and one Fiber.

## 8. Final pre-egress linearization and serialized outcomes

**Linearization point: the commit of transaction 3.** Under the canonical locks it:

- re-resolves every row and requires equality with the admitted snapshot (column S), including the configuration digest and every captured revision;
- re-authenticates the receipt and re-checks generation equality;
- re-checks the actor freshly and that the current permission intersection still contains the admitted set;
- requires the attempt to be `admitted` with `send_authorized_at` null, and the monotonic deadline to have room;
- writes `attempt.send_authorized_at = clock_timestamp()` and `credential.last_used_at` to the same instant (never earlier than its current value; no revision or generation change);
- records `assistant.ai.invocation_credential_selected` (outcome `send_authorized`) through `RequiredAudit`;
- forces deferred constraints and commits.

Audit failure rolls the transaction back and denies the send. After commit the callback re-checks the deadline and returns success; any failure returns abort and no request bytes are sent.

`send_authorized_at` means **send authorized, possibly transmitted**. It is never evidence that the provider received anything.

| Competing commit | It commits **before** transaction 3 | Transaction 3 commits **first** |
| --- | --- | --- |
| **Replace** (a pending candidate is stored) | Connection revision differs → no send. The active version is untouched and serves the next interaction. | Send proceeds with the admitted version. Candidate storage is unaffected. |
| **Rotate** (candidate promoted, old version retired and destroyed) | Selection, both revisions and generation differ → no send. The admitted body is never combined with the new key. | One send with the old key's in-memory copy. The at-rest envelope is destroyed immediately; that neither recalls nor extends the request. Post-response gate fails. |
| **Revoke** (exact active version) | Credential terminal, selection cleared, mode disabled, generation advanced → no send. | One send may complete. Post-response gate fails. |
| **Disable** connection or workspace | Disabled flag or cleared selection and revision → no send. | One send may complete. Post-response gate fails. |
| Flag, cap, entitlement or mode change | Settings revision differs → no send. | Send proceeds; a reduced tool set or disabled mode fails gates P/T. |
| Approval revoked, acknowledgement withdrawn, control circuit, role or membership loss | Fresh read fails → no send. | Send proceeds; P/T deny. |

For every "before" case: the transaction rolls back with nothing written, the transfer aborts, the attempt finalizes as not sent with the reservation retained, `invocation_credential_denied` is audited with outcome `authority_changed`, and the user receives the fixed unavailable result. There is no retry and no reselection.

**Post-response denial.** When authority changed after the send was authorized: the response is read within its bounds only to finalize accounting; the plan is discarded; no tool runs; nothing is disclosed; the attempt finalizes with reported usage, or `usage_unknown`, against its original immutable allocations and original account. No refund, no key reselection, no second request.

**Truthful cancellation.** There is no atomic database-and-network cancellation. Revoke, rotate and disable guarantee that no request is *authorized* after they commit, and that nothing from an in-flight request is used. They do not guarantee that a request authorized up to about eight seconds earlier was not delivered. Results, audit text and any future UI copy for those operations must say "takes effect for new requests; a request already authorized may still complete at the provider" and must never say in-flight requests were cancelled. Provider-side key revocation remains the customer's action; this system never claims it.

## 9. Transactions, locks, replay, expiry and recovery

### 9.1 Transactions and lock order

Primary PostgreSQL only, READ COMMITTED, one attempt per transaction, outermost level, `lock_timeout` 250 ms and `statement_timeout` 1 s bounded by the remaining deadline (`TenantAiVerificationAdmission::limits`). SQLite keeps its reviewed closed successor boundary.

Canonical order, unchanged from supplement §7 and 3c-1 design §10: deployment root → provider and model controls (UUID order) → budget controls (UUID order) → settings → connection → credentials (UUID order, never active-first) → owner approval → acknowledgement → windows (budget, scope rank, C-byte scope key, UTC start) → attempt and allocations → required audit. Discover IDs without locks, then lock and reload. Never acquire an earlier class late.

CR1's actor advisory lock is specific to the count-based verification admission quota and is **not** taken here: inference actor limits are the existing `AssistantBudget` controls and the row-locked `user_day` usage window.

Legacy and gateway writers already share the root fence, so VAP and customer admissions serialize correctly against each other and against lifecycle operations.

### 9.2 Replay and one-use

- One attempt per interaction, enforced by a partial unique index on `interaction_id` for this branch (§12.1) and checked under locks.
- Permit stages are one-way and consumed on rollback.
- `send_authorized_at` is write-once (existing immutability rule) and required null in transaction 3.
- A copied permit in another process, request or Fiber fails the identity checks; a forked child fails the PID check.
- Body digest binds the admitted bytes; the handler refuses a different body.

### 9.3 Expiry

Evaluated by primary time at A, J and S and by the monotonic clock throughout: credential `expires_at`; profile validity; owner approval; account grant; control and budget approvals; receipt trust-key availability; work-session deadline; 10 s planner and 8 s wire budgets. Expiry between A and S denies the send; the reservation stays.

### 9.4 Failure accounting

Terminal states reuse the existing vocabulary: `received`, `failed` (`not_sent` or provider failure), `usage_unknown`. Terminal rows are monotonic; a delayed worker cannot overwrite one. On `usage_unknown` after a send was authorized, or a profile-mismatch anomaly, the **customer account budget control** for that workspace is circuit-blocked with a revision increment. The root and shared-usage controls are not, so one tenant's provider anomaly cannot stop other tenants.

### 9.5 Recovery

Purpose-aware extension of the existing bounded recovery: an `assistant_intent` attempt still `admitted` 120 seconds after admission is finalized under the same locks as `failed/not_sent` if `send_authorized_at` is null, otherwise `usage_unknown`. Recovery never decrypts, resends, refunds, reselects or issues authority, and a stalled worker cannot commit after it.

## 10. Audit and redaction

Two events already reserved by supplement §13.9: `assistant.ai.invocation_credential_selected` (outcomes `admitted`, `send_authorized`) and `assistant.ai.invocation_credential_denied` (outcome is one closed reason from §4). Finalization reuses the existing received/failed events with the gateway metadata shape.

Allowed properties: operation/attempt/interaction UUIDs, actor A1, public workspace and entity IDs, production, deployment, connection/version/profile IDs, provider, ownership mode, purpose, captured revisions and generation, fixed outcome. Token counts and the reserved amount appear only on finalization events, as today.

Never recorded, logged or returned: the key or any fragment or fingerprint, ciphertext, wrapped DEK, KEK or receipt-key paths, receipt MAC, raw account claims, request or response bodies, the question, headers, User-Agent, IP, SDK objects, raw SQL, upstream error text. Missing, foreign and unauthorized identifiers are indistinguishable to the caller. Every `Throwable` inside the session is mapped to a fixed failure without chaining.

## 11. SDK composition and process isolation

### 11.1 Installed-version evidence (`laravel/ai` v0.11.2)

| Finding | Source | Consequence |
| --- | --- | --- |
| `AiManager extends MultipleInstanceManager` and caches named provider instances built from `config('ai.providers')`. | `vendor/laravel/ai/src/AiManager.php` | Unsuitable for tenant secrets. The application binds `AiManager` to throw and ships empty `ai.providers`. **Keep.** |
| The SDK's default client is built with the process-wide `Http` facade. | `Gateway/Concerns/CreatesClient.php` (`Http::baseUrl(...)`) | The shared factory carries global middleware, fakes and recording, and dispatches `RequestSending`/`ResponseReceived` with full headers to application listeners. A customer key must never pass through it. |
| `PendingRequest` dispatches those events only when its factory has a dispatcher; `new Factory` has none. | `Illuminate/Http/Client/Factory.php` constructor; `PendingRequest::dispatchRequestSendingEvent` | The accepted bridge's `new PendingRequest(new Factory)` emits no events. **Keep exactly this.** |
| `AnthropicGateway` and `AnthropicProvider` have public constructors taking a dispatcher and a config array; `generateTextStep` builds the body, calls the overridable `client()`, validates and parses. | `Gateway/Anthropic/AnthropicGateway.php`, `Providers/Provider.php` | Per-invocation construction with an explicit key is supported SDK usage. No vendor edit or upgrade is needed. |
| Static state in the gateway/provider tree is limited to `ParentInvocation::$current` (two invocation IDs) and a list of Bedrock error patterns. | `grep` over `vendor/laravel/ai/src` | No static credential or client cache exists. |
| `Provider` holds the key in a protected config array and has no redacting debug handler. | `Providers/Provider.php` | The provider object must stay private to the bridge and never reach a dumper, event or log. It does today. |

**Composition rule (unchanged from the accepted bridge, now also for customer keys):** for each admitted invocation construct a new anonymous `AnthropicGateway` subclass with a new `Dispatcher`, a new `AnthropicProvider` with the key in its constructor config, and a `client()` override returning `new PendingRequest(new Factory)` whose handler is the session closure. Do not call `AiManager`, the `Ai` facade, `Promptable`, tool loops, conversations, failover lists, SDK defaults, queueing or the `Http` facade. Never write a customer secret to `config()`, the container, a static property, cache, session, a job payload or `AiManager`.

If a future SDK version changes any of these seams, that is a new review, not an in-place adaptation.

### 11.2 Where the plaintext exists

Exactly these request-local places, all unreachable after the exchange returns: the secret holder's `SensitiveParameterValue`; the `#[\SensitiveParameter]` arguments of the bridge and transport; the handler closure's captured variable; the anonymous gateway's private property; the provider's config array; the pending request's header option; the PSR-7 request inside the handler; the cURL header list; libcurl's internal buffers. PHP cannot guarantee memory zeroization; the commitment is bounded retention and absence from every persistent or shared store, not erased process memory.

### 11.3 Process isolation

- **Supported:** synchronous PHP-FPM web requests. Each request rebuilds userland state; nothing the session creates survives it.
- **Denied (`runtime_unsupported`):** console and queue (already refused by `requireHttp()`), and any long-lived application worker. No Octane, Swoole, RoadRunner, FrankenPHP worker mode, Horizon, Telescope, Pulse or error-reporting SDK is installed (`composer show`). The session must refuse when it detects a persistent-worker runtime rather than assume request teardown.
- Because nothing secret-bearing is registered anywhere shared, correctness does not depend on teardown. Enabling a long-lived runtime nevertheless requires its own explicit isolation rehearsal first.
- No queued customer invocation is authorized. No event, job, session, cache entry or callback carries a credential, decrypt handle, observation or permit.
- `zend.exception_ignore_args` was observed **off** on the local CLI. Sensitive-parameter attributes and fixed failure mapping protect the designed paths, but production must set it on (§15).

## 12. Demonstrably necessary future changes — proposals only

Nothing in this section was created or applied.

### 12.1 One additive successor migration (`invocation_authority_v1`)

Necessary because of the four frozen constraints in §3. New migration files only; the three frozen 3c-1 migrations and `TenantAiVerificationSchema` stay byte-identical. **No new table and no new column are required**: the existing gateway attempt columns already hold the full snapshot.

| Structure | Proposed change |
| --- | --- |
| Attempt shape check | Add a third branch: `gateway_v1`, `customer_managed`, `purpose='assistant_intent'`, `vap_reference_id` null; `request_bytes` 1–16,384; `estimated_tokens` 1–8,192; `prior_selected_version_id = credential_version_id`; `prior_active_generation ≥ 1` (the captured activated generation); `proposed_activated_generation` and `verifier_policy_sha256` null; `verification_outcome='not_observed'` permanently; every receipt, observation and promotion field null; terminal states `received` / `failed` / `usage_unknown` with the legacy token and charge bounds; `send_authorized_at` chronology as today. The `connection_probe` and legacy branches are restated byte-equivalently. |
| Attempt immutability guard | For the intent branch only, allow `input_tokens`, `output_tokens` and `actual_micro_usd` to be written once at the terminal transition. Probe attempts keep today's list. |
| Owner approval shape check | Add `purpose='assistant_intent'` (account-mapping digest required, verifier digest null). The existing active-tuple unique index already includes purpose. |
| Credential guard | Replace the unconditional `last_used_at` refusal with: allowed only on an `active` row, strictly non-decreasing, changing nothing else but `updated_at`; plus a deferred check that a gateway-v1 `assistant_intent` attempt for the same version/connection/workspace/deployment has `send_authorized_at` equal to it. No revision or generation change. Retirement keeps the historical value. |
| Indexes | Partial unique `(interaction_id)` for the intent branch; partial `(admitted_at, id)` for intent attempts in `admitted` state (recovery); reuse `ai3c_attempt_workspace_idx`. |
| Allocation integrity | **Reuse unchanged.** The existing deferred check derives the nine-row set from attempt fields and is not purpose-specific. |
| Attempt binding check | **Reuse.** The existing deferred check ties each gateway attempt to its profile, connection, credential, owner approval and acknowledgement, and compares purpose and both digests null-safely, so an intent attempt can only bind an intent approval. The review package must confirm line by line that it carries no probe-only assumption; if it does, restate it per branch rather than relax it. |
| Down migration | Refuse while any intent attempt, intent approval or `last_used_at` exists. |

No backfill, no seeded grant, profile, approval, acknowledgement or enabled control. Validation is staged (`NOT VALID` then `VALIDATE`) under bounded lock timeouts, as in 3c-1. Like 3c-1, the concrete DDL needs an independent pre-execution review before it is applied (supplement §6).

### 12.2 Code and configuration

| Change | Purpose |
| --- | --- |
| New: `TenantAiGateway`, `AiCredentialRoute`, `TenantAiInvocationSession`, `CredentialInvocationPermit`, `TenantAiInvocationPolicyResolver`, `TenantAiInvocationPolicy`, `TenantAiInvocationAdmission`, `TenantAiInvocationSecret`, `AiSendAuthority` and its legacy wrapper | The consumer contract of §§4–9. |
| Edit: `AssistantIntentGateway::execute`, `AssistantProviderTransport::exchange` | Accept `AiSendAuthority`. `wire()`, body builder, allowlists and parsers untouched. |
| Edit: `AssistantProviderInvocation::retain/verify` | Hold the post-response authority through the interface so tool-time rechecks reach the session. |
| Edit: `AssistantPlan::plannerInput/fromJson`, `ProviderIntentInput::fromInput/localPlan`, `AssistantInteraction` | Optional tool ceiling. |
| Edit: `AppServiceProvider` | Bind `VapAiGateway` to `TenantAiGateway`; planner enabled when either route switch is true. |
| Edit: recovery command | Purpose-aware intent recovery. |
| Config: `tenant_ai.inference` | `enabled=false`, `anthropic_egress_enabled=false`, empty `profiles` and `accounts`. Default-closed keys only. |
| Unchanged | Every 3a/3b/3c-1 custody, verifier, receipt, lifecycle and schema file; all 14 frozen files; the legacy ledger; dependencies. |

## 13. Concurrency and adversarial acceptance criteria — all NOT RUN

Each item needs a named test and an implementation mapping, not a count.

**Resolution and authority**

1. Each of the five settings states yields exactly its route or denial; a customer-route failure of every closed reason never reaches the VAP or legacy adapter (assert zero calls and zero legacy ledger rows).
2. Arbitrary, foreign, pending, replaced, revoked and other-connection credential IDs cannot be selected by any caller or model input.
3. Receipt: tamper every signed field, MAC and key ID; unknown/retired trust key; unpromoted disposition; valid receipt of another version, connection or generation; `offline_fixture` realm under production composition. All deny as `receipt_untrusted` or `generation_mismatch`.
4. A structurally coherent hand-written active row with forged evidence yields no invocation.
5. Binding substitution: workspace, entity, environment, deployment, provider, account mapping, profile, model. Each denies independently.
6. Expiry of credential, profile, approval, grant and control approvals at A, between A and S, and at S.

**Tool ceiling**

7. All five flags independently: with exactly one flag true only that tool is planner-visible and executable, including `searchCustomers` without `getCustomer` and the reverse.
8. Mixed plans: one allowed plus one denied call rejects the whole plan with zero reads.
9. A flag true without the permission, and the permission without the flag, both deny. Unknown tool, null and non-boolean flag deny.
10. Flag cleared between plan validation and a tool, and between the last tool and disclosure: no further tool, no disclosure.

**Permit**

11. A verification request permit and a promotion permit fail at the invocation session; an invocation permit fails at the verifier and lifecycle.
12. Unregistered look-alike permit, second use of every stage, wrong request, wrong Fiber, forked process, post-deadline use: all fail. A stage stays consumed after its transaction rolls back.
13. No public factory can mint a permit from a row, receipt, DTO or flag.

**Linearization — independent PostgreSQL workers with explicit barriers, real lock waits, both orders**

14. Transaction 3 versus each of: candidate store, promotion/rotation, exact active revoke, connection disable, workspace disable, mode change, flag change, cap decrease, entitlement change, approval revocation, acknowledgement withdrawal, control circuit, membership/role loss. Lifecycle first: zero request bytes, attempt not sent, reservation retained. Authorization first: exactly one request, post-response denial.
15. Admission versus the same set, both orders.
16. Never more than one transmission per attempt, including a repeated prerequisite callback and a worker killed between commit and first byte (no recovery resend).
17. After authority loss: no tool, no disclosure, finalization against the original account and allocations, no refund, no reselection.
18. Rotation never combines the admitted body or reservation with the new key.
19. Rollback injected at each write of transactions 1, 3 and 4 leaves no partial state; the admitted liability survives a failed finalization.

**Secret custody and isolation**

20. Sentinel secret absent from SQL listeners, query logs, model/resource output, exceptions and traces, logs, audit, session, cache, queue payloads, `config()`, container bindings, events and the provider/model context; present only in the designated header inside the transport.
21. Decrypt is refused before admission, for another purpose, for another version and after any gate change.
22. Envelope differential: identical fixtures open and identical tampered fixtures fail through both consumers.
23. Sequential tenants A→B→A in one process; interleaved Fibers; exception on every step; reused container: no identity or secret bleed, and every secret-bearing object is released (weak-reference assertion).
24. Persistent-worker, console and queue runtimes deny.

**Wire and inference boundary**

25. Customer-route wire bytes equal the legacy wire bytes except the key value: same method, URL, headers, body, bounds, DNS/TLS/redirect/proxy behaviour. Existing legacy wire and security differential tests stay green unmodified.
26. The model input contains only the original question, reference aliases, month and admitted tool schemas. A test that plants tool-result and business-record sentinels proves none reaches the body.
27. Factual answers are produced locally and are identical for the same tool results regardless of route.
28. Provider output proposing a URL, credential, permission, extra tool or prose is rejected by the unchanged validators.

**Accounting**

29. Exact nine-allocation set; missing, extra and duplicate roles rejected by the database; last-slot and concurrent admission races; UTC boundaries; nonrefundable reserve on unsent, failed, timed-out and denied attempts.
30. Customer money never enters VAP windows; legacy and customer admissions interleave correctly under the root fence.
31. Account circuit trips on unknown usage after authorization and on anomaly; root and shared controls do not.
32. Recovery classifies unsent and possibly-sent attempts correctly and never decrypts, resends or refunds; a late worker cannot overwrite a terminal row.

**Migration**

33. Legacy and `connection_probe` branches are constraint-equivalent before and after; pre-existing rows validate; invalid rows stop the migration; down is refused with retained data; interrupted staged validation recovers. Direct-SQL tests for every new branch, the `last_used_at` successor and the immutability change.

**Preservation**

34. All 3a/3b/3c-1 tests pass against their frozen baseline with no assertion removed or weakened. All 14 frozen hashes exact. Both verification switches and both inference switches remain false in checked-in configuration.

## 14. Required implementation and quality gates

- Pre-execution independent review of the concrete migration DDL before it is applied anywhere.
- Full SQLite manifest; disposable UTF-8 PostgreSQL integration; full `FISCAL_PG_GATE=1` concurrency; focused custody, lifecycle, gateway, quarantine, Phase 6–7 audit/wire/CLI/FPM suites; assistant UI and type checks.
- Changed-file syntax and Pint; PHPStan compared object-by-object with the inherited 21 diagnostics, zero new, no suppression.
- Record exact commands, environment, exits, counts, durations, versions, fixture provenance, log hashes, failed attempts and pre/post hashes. Partitioned or rerun gates are labelled as such, never as one clean run.
- Positive customer-route tests are **offline protocol and lifecycle proof** with synthetic manifests, grants and fixture trust roots. They are not evidence of a live account, model or entitlement.
- Inherited ESLint, format and warning debt stays separately classified. No unrelated repair, no test weakening.
- Query plans for every new admission, prerequisite, finalization and recovery path, with bounded index use.
- Independent security review of the finished increment before any further stage.

## 15. Operational prerequisites — unproven, not design blockers

None of these is established by this design or by offline tests, and none is authorized here.

1. **Entitlement:** exact customer organization, workspace, payer, least-privilege key scope and the exact model's Messages and structured-output compatibility, with account-specific evidence. A reviewed customer inference profile and grant (none exists; the only materialized profile is VAP-only).
2. **Accountable owner and budget:** named technical, operations, privacy and billing owners; approved bounded attempt, output and money caps per tenant and for shared usage.
3. **Privacy, retention and geography:** the inference disclosure text and version for customer mode; provider data-handling, region (`inference_geo`) and retention decisions; metadata and backup retention.
4. **Runtime-secret custody:** production KEK and receipt-key custody, separation, access, recovery and rotation evidence; `zend.exception_ignore_args=On`; FPM, APM and log redaction evidence; no persistent-worker runtime.
5. **Single-primary evidence:** attestation that every participating instance uses one authoritative PostgreSQL primary and retained history for the root fence; restored or cloned databases cannot admit production work.
6. **Controlled rehearsal:** an explicitly authorized, bounded rehearsal with rollback and kill-switch procedure and reviewed results, including revocation timing against the truthful-cancellation statement.

## 16. Design blockers and deferred work

**Genuine design blockers: none.** No signed invariant conflicts with this design. The frozen schema's refusal of customer inference is resolved by the additive successor the supplement itself anticipates (§13.6: a future 3c-2 successor must tie use to its own admitted, transmission-marked inference attempt).

Deferred, not authorized by this design:

- Recording inference acknowledgements and showing the customer-mode notice (needs UI); creating inference approvals, grants and profiles; setting mode and tool flags through a product surface.
- Operator path to clear a tenant account circuit.
- Gateway-v1 accounting for VAP Managed (`vap_reference_id`, VAP aggregate/account windows); VAP routes stay on the accepted legacy ledger.
- Customer profiles that are not wire-equivalent to the accepted Anthropic profile; additional providers; OpenAI and compatible endpoints; cross-provider failover.
- Long-lived worker enablement; queued invocation.
- KEK rewrap and key-retention tooling.
- Production activation and every prerequisite in §15.
- Unrelated lint and format cleanup.

## 17. Exact bounded implementation handoff

> Begin **Phase 7B.3c-2 gateway credential consumption**, implementation responsibility, with its mandatory **pre-execution migration review checkpoint first**. Read this design in full, VC1 §13 (especially §13.5, §13.8, §13.11, §13.12, §13.14), CM1 §3.3.1, supplement §§6–9, gateway contract §§3–5, §9, §15, the 3c-1 design §10 and §18, and the 3c-1 final checkpoint. Verify the 802-file inventory and all 14 frozen hashes before starting and report drift precisely. 3c-1 stays frozen. Verification and inference stay disabled.
>
> **This run's exact boundary:** prepare a concrete, reviewable migration package for §12.1 of this design: the additive `invocation_authority_v1` DDL (third attempt branch, intent approval purpose, `last_used_at` successor guard, intent immutability allowance, two partial indexes, down refusal), old/new constraint equivalence for the legacy and `connection_probe` branches, a transaction and lock matrix, a populated-data rehearsal plan, the implementation-file map of §12.2, the test plan of §13 and frozen hashes. Supply draft migrations as listings in the report, not as runnable auto-discovered files. **Do not apply any migration and do not write runtime code or tests in this run.** Produce `docs/phase-7b-3c-2-migration-review-package.md` with JSON evidence, label every proposed test NOT RUN, and stop with `PHASE 7B.3c-2 MIGRATION PACKAGE — READY FOR PRE-EXECUTION REVIEW` or the precise unresolved conflict.
>
> **Retained specification for the subsequent, separately authorized completion — not permission to proceed now:** implement only the consumer contract of §§4–11: deterministic route resolution with no fallback; authentic active receipt and generation validation; the five-tool ceiling at schema, plan, tool and disclosure; independent gates; the request-bound one-use invocation permit; purpose-bound JIT decrypt; the transaction-3 linearization with durable send mark and `last_used_at`; post-response denial; finalization against persisted allocations; purpose-aware recovery; the two audit events. Reuse the accepted SDK bridge and pinned transport through the single `AiSendAuthority` seam; do not duplicate them. Keep all 3a/3b/3c-1 custody, verifier, receipt, lifecycle and schema files and all 14 frozen files byte-identical. Synthetic wire, manifests, grants and fixture trust roots only. Run every gate in §14, map every item in §13 to a named test, and stop with `PHASE 7B.3c-2 — READY FOR INDEPENDENT REVIEW` or `PHASE 7B.3c-2 — NOT READY`.
>
> **Excluded throughout:** provider requests, live credentials, provisioning of real profiles/grants/approvals/acknowledgements, enabling any switch, additional providers, OpenAI or compatible endpoints, failover, new UI or routes, new tools or capabilities, mutation functionality, long-lived worker enablement, dependency changes and unrelated cleanup. Return for a design decision if the guard cannot bind `last_used_at` without weakening PA1, an ordinary caller can mint invocation authority, the SDK seam cannot enforce the exact profile, or the send seam cannot be generalized without changing legacy wire bytes or ordering.

## 18. Design validation and disposition

This is a documentation-only task. Its claims about existing behaviour rest on the source and hash inspection listed in §1; its acceptance criteria are future obligations. Files changed by this task: this document, and one appended subsection in `README.md` recording the disposition and deferred work. No historical report or evidence file was edited.

PHASE 7B.3c-2 DESIGN — READY FOR IMPLEMENTATION
