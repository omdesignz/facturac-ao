# Phase 7B.3c-1 — provider verification and active lifecycle implementation

Date: 2026-10-09. Current implementation resumes the bounded handoff under the frozen runtime design, VC1, R2/R3 and CR1. **Bounded implementation and required offline gates are complete; independent security review is next. This is not provider activation.** The historical B1 report is preserved verbatim below and in the evidence artifact. Its six-admission counterexample remains a demonstrated historical failure, not a current acceptance pass.

## Scope and implementation

The implementation adds an internal, synchronous verification session and authenticated active lifecycle. It adds no route, UI, command, scheduled task, job, migration, provider profile, real account approval, credential, production key or gateway credential consumer. No dependency changed. Verification and inference remain disabled by default. Tests use ephemeral synthetic credentials and the approved dev-only raw-wire seam; there were zero real provider API calls.

`TenantAiCredentialVerifier` is a final, transient, single-operation session. Its private registry owns separate decrypt/send and promotion handles. It creates the observation through the fixed adapter, constructs and signs the final receipt privately, and consumes promotion authority once in the same request. A public DTO, a guessed identifier, a valid historical MAC, or a structurally valid database row cannot reconstruct that registry. Closed sessions cannot be reused. No public signer, decrypt-by-ID service or persisted-receipt redemption endpoint exists.

`TenantAiVerificationContext` binds the original request, process, fiber, canonical actor A1, explicit workspace, legal entity, production environment and ten-second monotonic deadline. It uses the accepted fresh owner/MFA/email/work-session/POST/CSRF/reauthentication checks. Bearer, delegated, changed-request, expired-session and foreign-context paths fail closed. The current browser workspace is not an execution-context source. Context resolution and verifier preflight run in bounded read transactions; admission installs limits before fresh authorization reads. Lifecycle authorization and recovery discovery likewise execute only after their transaction limits are installed. A real PostgreSQL table-lock regression proves that blocked context/preflight reads fail without admission.

`TenantAiVerificationPolicyResolver` requires deployment-reviewed profile/account/cost/egress/disclosure metadata, enabled verification switches, current owner approval and acknowledgement, matching tenant/entity/deployment/profile/connection/payer, exact revisions, pending non-destroyed credential and current control/budget validity. A database profile alone does not authorize verification. Configuration is fingerprinted for the operation; a changed fingerprint prevents promotion. Actual customer mappings and approvals remain absent from defaults.

## CR1 serialization and accounting

The quota key is **A1 only**. Namespace `1180058417` and SHA-256 domain `facturac:tenant-ai:verification-admission:actor:v1` followed by LF implement the reviewed signed-int4 derivation. The published vector produces `-1169568379`. A deterministic collision pair is tested with key `434458243`; the collision adds serialization but never combines exact-actor counts.

The admission transaction is outermost primary PostgreSQL READ COMMITTED. Its first lock is the transaction-scoped A1 advisory lock, before any deployment root. The fresh bounded actor query executes in a separate statement after lock acquisition. It selects ordered gateway probe admissions newer than database clock minus 3,600 seconds, with `LIMIT 5`, no provider/root/tenant/credential/state qualifier and no upper time bound. The count, all nine conservative reservations, attempt, allocations and required audit commit together. Actor lock failure has no fallback or retry. The lock is released before JIT decryption and all provider work.

Canonical order then follows roots, provider/model controls, sorted budgets, settings, connection, sorted credentials, approval, acknowledgement, ordered windows, attempt/allocations and audit. Later phases, lifecycle and recovery never acquire CR1. Connection cooldown, workspace quota, unfinished-attempt exclusion, exact positive profile reservation/output, configured ceilings and checked integer addition remain independently enforced. Failed, unsent, stale and recovered admissions retain liability and actor quota. The actual SQL captured from the quota service is also exercised with rows immediately before, exactly at, and one microsecond after its rolling-hour boundary, plus a future-dated row: only the latter two qualify. No actual provider charge is inferred from the GET; actual monetary fields remain null.

This guarantee applies across participating processes, tenants, providers and deployment roots sharing one authoritative PostgreSQL writer/history. **It is not a cross-database or distributed control-plane guarantee.** Production topology must prove that prerequisite before any activation.

Quota-only races use schema-valid inert structural admissions, including a second provider's metadata. They do not create another adapter or claim provider authentication. Every positive runtime active credential is produced through the complete offline verifier, parser, signer, transaction and frozen constraints.

## Secret, wire and receipt boundaries

The restricted JIT consumer reloads authority under canonical locks, reads only the exact bound envelope through prepared primary PDO and authenticates the existing AES-256-GCM DEK/payload binding. It does not use Eloquent secret casts, application query events, `APP_KEY` fallback or the test decryptor. The private transient secret is released after the synchronous exchange and never enters an application result.

The adapter performs only the approved bodyless model-metadata GET to `api.anthropic.com:443`, using the exact approved model and reviewed workspace header. Shared DNS security was mechanically extracted from the existing transport. DNS, peer pinning, TLS, connection and operation bounds are retained; redirects, proxying, retries, connection reuse and content decompression are disabled. Headers and body are bounded during reception. Duplicate/ambiguous claims or JSON, incompatible media, missing/mismatched account claims and alias model identities cannot authenticate. Provider organization UUIDs must be exact canonical lowercase strings; the contract does not invent a UUID-v4-only restriction for provider-issued identifiers. No case normalization occurs.

The signer uses the unchanged 47-position frozen array, domain, canonical decimal strings, UTC microseconds, sorted closed authority references, 8,192-byte limit and HMAC-SHA-256. Both R3 vectors and all 94 original signed-position mutations are verified. All HTTP 5xx codes map to provider unavailability; connection failures, timeouts, TLS/policy rejection and parser errors retain distinct closed outcomes. Native cURL denies whenever PHPUnit is loaded, even if a test changes APP_ENV. Lowercase public identifiers remain exact stored bytes. The current key and historical verify-only keys come from dedicated protected files, independently trusted for deployment/policy/validity. Reused KEK/APP_KEY material, untrusted/expired/missing keys, exposed roots and symlinks deny. No key cache or recoverable credential copy is added.

Promotion expiry includes observation + five seconds, operation + ten seconds, credential/profile/grant/account/budget validity and signer validity. Completion rechecks request/deadline and, before committing a promotion, database time against expiry and the immutable operation configuration. A test delays mandatory audit writes across authority expiry and proves full rollback. An old promoted receipt remains historical evidence after its promotion TTL; verify-only historical keys can authenticate it for rotation, while withdrawn trust prevents rotation. Emergency revocation remains available after trust withdrawal.

Success proves authenticated model-metadata visibility and the required account-routing claims only. It proves neither inference entitlement, billing ownership, free usage nor production readiness.

## Lifecycle, recovery and compatibility

Active rotation locks and reloads the original tuple, authenticates prior active history, retires/destroys A, activates B, advances generation and selection revisions, writes the authenticated receipt and required audits in one transaction. PostgreSQL enforces the single-active structural boundary independently. Injected failures at receipt, retirement, activation, selection and audit stages restore A's original envelope and retain the admission for recovery. Revocation addresses an exact credential; an old version cannot silently target a replacement. Disablement clears selection without inventing replacement authority.

Recovery is internal and purpose-scoped, bounded to 100 stale admissions older than 30 seconds. It records unknown/abandoned accounting once. It neither decrypts nor transmits, signs, promotes, refunds or reconstructs request authority. Crash tests cover admission, authorized send and response/observation boundaries. Recovery is not scheduled or exposed.

The legacy provider ledger now uses its discriminator and partial-index-aware acknowledgement upsert, root-fenced shared-unit accounting, immutable deployment/shared-budget mapping and correct control revisions. Its amount, protocol and inference semantics remain unchanged. Root emergency and shared approval loss deny legacy admission/send checks; finalization still retains liabilities. These changes are required frozen-schema coexistence work.

PostgreSQL test reruns use an explicit opt-in disposable-schema reset because Laravel `migrate:fresh` removes tables but leaves frozen SQL functions. The reset rejects non-testing, non-PostgreSQL and non-`facturac_test_verification_cr1_` databases. It adds no production schema behavior. Older empty migration-roundtrip tests now unwind/reinstall the successor migrations in order. The legacy lifecycle test still forbids issuer/consumer authority in the old service and all gateway wiring; its obsolete global assertion that no future permit class may exist was scoped to that original service. No permission, concurrency, quota or fiscal assertion was removed to obtain a pass.

## Requirements and regression mapping

| Governing requirement                                    | Implementation / primary evidence                                                                                                                                              |
| -------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Design §§3–4, explicit fresh request authority           | Context/request/policy/session classes; preflight, context/fiber, owner loss, signed-authority mutation and lifecycle tests                                                    |
| CR1 exact key and global serialization                   | Quota class; published vector, same/cross-root/tenant/provider/credential races, independent actors, collision, rollback, timeout and actual lock-order observation            |
| CR1 bounded authoritative history query                  | PostgreSQL retained-history 0/4/5 plans embedded in evidence; ordered actor index, bounded rows, no sort/spill                                                                 |
| Design §§5–6, fixed network and JIT custody              | Transport/adapter/buffer, shared DNS trait, secret/key readers; actual cURL option/peer callbacks, parser, CLI/FPM resolver, custody/tamper/redaction cases                    |
| Design §§7–8, unforgeable promotion and receipt fidelity | Private verifier registry/signer; forged/replayed handles, closed-session reuse, frozen vectors, all signed positions, exact IDs, signer withdrawal/verify-only history/expiry |
| Design §§9–10, reservations and active lifecycle         | Admission, lifecycle and recovery; nine allocations, no refunds, one winner, independent-process revoke/replace/disable/policy races, rotation failure matrix                  |
| Design §§11–12, failure and mandatory metadata audit     | Safe outcome projection, transactional audits; no raw provider error/header/secret in captured SQL/audit/result; admission/send/completion audit failures and late expiry      |
| Design §13, historical compatibility                     | Discriminator/upsert/shared-liability/revision tests; legacy, custody, lifecycle, gateway, fiscal and API regression selections                                                |
| Frozen schema / R2 / R3                                  | All 14 accepted hashes unchanged; fresh complete install, 166-case schema gate, six upgrade cases and independent schema contender gate; no migration changes                  |
| Operational and scope exclusions                         | Defaults, static gateway/service checks, fixture realm restrictions, zero real calls; no route/UI/commands/jobs/provider activation/7B.3c-2                                    |

The evidence artifact contains exact commands, environments, exit statuses, counts, log hashes, changed-file hashes, retained plans, failed runs and the diagnostic-object comparison. Test counts overlap across focused and broad selections and must not be summed as unique cases.

## Validation and inherited debt

| Gate                                                               | Result                                                                     |
| ------------------------------------------------------------------ | -------------------------------------------------------------------------- |
| CR1 PostgreSQL quota / bounded plans                               | PASSED: 9 passed; 141 assertions                                           |
| Complete PostgreSQL verifier / active lifecycle                    | PASSED: 77 passed; 1050 assertions                                         |
| Quota, receipt and transport units                                 | PASSED: 56 passed; 297 assertions                                          |
| Frozen PostgreSQL schema matrix                                    | PASSED: 166 passed; 1698 assertions                                        |
| Populated pre-3c upgrade/reconciliation                            | PASSED: 6 passed; 88 assertions                                            |
| Frozen independent contender race                                  | PASSED: 1 passed; 15 assertions                                            |
| Unpartitioned final SQLite run (inherited flake retained)          | FAILED: 2364 passed; 15661 assertions; 1 failed; 593 skipped; 1 warning(s) |
| Unchanged quota test, isolated                                     | PASSED: 1 passed; 64 assertions                                            |
| Complete SQLite remainder, excluding only that separately run test | PASSED: 2364 passed; 15600 assertions; 595 skipped; 1 warning(s)           |
| Complete PostgreSQL integration selection                          | PASSED: 1543 passed; 11301 assertions                                      |
| Complete PostgreSQL concurrency selection                          | PASSED: 336 passed; 9712 assertions                                        |
| Affected legacy/custody/lifecycle integration                      | PASSED: 184 passed; 1615 assertions                                        |
| Focused PostgreSQL legacy/custody/lifecycle                        | PASSED: 39 passed; 576 assertions                                          |
| Earlier focused SQLite gateway/security                            | PASSED: 179 passed; 1283 assertions                                        |
| Additional recovery/late-worker regression                         | PASSED: 2 passed; 64 assertions                                            |
| Additional full-path cross-root / independent budgets              | PASSED: 4 passed; 30 assertions                                            |
| Blocked context/preflight reads                                    | PASSED: 2 passed; 16 assertions                                            |
| Retained plans and exact rolling-hour/future-row boundaries        | PASSED: 1 passed; 71 assertions                                            |
| Corrected DNS branch fixture, full protocol file                   | PASSED: 33 passed; 169 assertions                                          |
| PHP syntax / Pint                                                  | PASS; 41 changed PHP files; exit 0                                         |
| PHPStan                                                            | 21 inherited diagnostics; exact diagnostic objects MATCH                   |
| Type checking / assistant UI                                       | PASS; exit 0; 12 UI tests passed                                           |
| Repository ESLint                                                  | 9,719 inherited errors; exit 1                                             |
| Resource formatting                                                | Four inherited failures; exit 1                                            |

Inherited PHPStan remains 21 diagnostics with exact diagnostic-object equality against the accepted baseline, not merely an equal count. ESLint remains 9,719 errors and resource formatting fails on the same four files. No unrelated debt was repaired. Warnings are recorded exactly as emitted; the compact runner may provide only a count. They are not silently omitted or relabeled as failures caused by this phase. The initial quota-window flake and OpenSSL random-state harness failures are retained as failures; targeted reruns and the final full SQLite run are separately identified. No timing assertion was weakened. New synthetic profile/grant lifetimes use primary database time rather than a fixed next-day expiry; accepted frozen fixtures and vectors remain unchanged.

## Failed implementation and rehearsal attempts

All intermediate failures are retained in the evidence/log inventory. They include structural fixture timestamp/timezone mistakes, disabled synthetic connection setup, PostgreSQL SUM/EXPLAIN JSON numeric-type expectations, a missing Pest dataset, a pending rather than finalized disposition expectation, frozen functions surviving table-only resets, obsolete migration-roundtrip/global-class assumptions, default PHP memory exhaustion, OpenSSL random-state path, the inherited external quota-window flake, and an incorrect multi-group exclusion that included dedicated schema tests in the broad concurrency database. None was relabeled as a passing result. Final inspection also found that context/preflight reads preceded transaction timeout setup; they were moved inside bounded transactions and tested under a real table lock. Runtime checks were tightened for current authority expiry through final audit/commit and contract-specific callback-abort classification (size bounds versus malformed protocol); frozen design/SQL/vectors were not changed.

## Validation corrections and evidence interpretation

The first broad PostgreSQL integration run failed with 1,506 passes, 10 assertion failures and 27 errors. It exposed legacy test assumptions: unscoped control/window queries after shared accounting was introduced, updates lacking the approved revision increment, a fixed application test clock used for a new DB-clock approval, old storage downgrade order, and a deferred integrity constraint not forced inside the test transaction. The tests now select the legacy budget, retain all original monetary/window/circuit assertions, advance control revisions under the root fence, give shared approval a database-clock lifetime, unwind successors before predecessor roundtrips, and force deferred constraints before asserting rejection. No guard, migration or production authorization rule was loosened.

An intermediate runtime rerun also exposed an obsolete malformed-response expectation for an oversized header and an expiry fixture that did not reliably force its intended boundary. The final test explicitly proves database time has crossed its shorter expiry and still requires complete promotion rollback. Failed logs remain separate evidence. The first complete SQLite remainder run then exposed a new test-fixture timeout: the standalone DNS address-validation test's artificial two-second subprocess allowance elapsed before address validation. Its test-only allowance is now ten seconds so the intended rejection branches must execute; all original rejection assertions remain. Production still supplies its unchanged one-second DNS deadline. The full protocol file and complete partitioned SQLite suite were rerun; the failed remainder log was preserved. A PHPStan invocation without the repository's debug mode exited 1 with no diagnostic output; it is not a passing gate. The final documented debug invocation supplies the actual diagnostic-object comparison.

The final unpartitioned SQLite run retained the inherited ExternalIntegrationTest minute-window quota failure (2,364 passes, one failure, 593 PostgreSQL-only skips, one warning). No assertion or source in that test was changed. Final complete-suite execution is partitioned into that exact unchanged test and every other case, using a negative filter matching only its full name. Both command results are reported independently; this is not described as a clean single unpartitioned invocation. The complete SQLite suite runs at the signed closed successor boundary; PostgreSQL-only cases intentionally skip there. Real successor runtime/schema/races run separately on disposable PostgreSQL. Structural admission fixtures isolate CR1 across inert provider metadata; they never establish authenticated active authority. An additional full verifier race starts at four admissions and exercises real policy, admission, allocations, parser, signer and active lifecycle across two tenant/deployment roots: one fifth admission and one active credential.

The broad concurrency suite refreshes an older Phase 6 plan artifact as a test side effect. Its fresh output was retained under the scratch evidence directory and the historical repository artifact restored byte-for-byte from the verified task-entry backup. The frozen migration tests were copied only into the disposable execution area to substitute their exact database-name safety guards; original files and accepted hashes remain unchanged.

## Remaining limitations and operational gates

- Live customer profile, account/model eligibility, payer/account ownership, separate metadata request-cost bound, egress, privacy/geography and disclosure approvals remain unprovisioned and unverified.
- Dedicated production KEK/signing trust files, ownership/permissions, historical verification-key retention and compromise procedures require deployment evidence.
- One authoritative PostgreSQL primary/history for all participating roots/instances is mandatory; no distributed quota guarantee is claimed.
- Production FPM/APM/core-dump/debug/profiler isolation still needs deployment rehearsal. Automated FPM/CLI resolver tests are not production-process evidence. PHP reference clearing is not guaranteed memory zeroization.
- Long-lived workers, asynchronous verification and alternate provider endpoints are unsupported. Missing required real provider claims require Astra review, never an API fallback.
- An active credential authorizes neither gateway consumption nor inference. Verification activation, provider activation and subsequent roadmap work require separate authorization.

## Exact independent security review handoff

> Perform the independent Phase 7B.3c-1 provider-verification/active-lifecycle and CR1 execution review. Read the frozen runtime design including §18 CR1, VC1, accepted migration/execution package, this current report/evidence and the preserved historical B1 counterexample. Inspect actual code and fresh logs/plans rather than treating this report as proof. Falsify A1-only serialization across roots/tenants/providers/credentials, exact key/collision behavior, first-lock ordering, separate post-lock snapshot, atomic count/reservation/admission, failure retention, primary topology and absence of network under locks. Review the closed registry, original request/PID/fiber/owner/context freshness, JIT custody, fixed raw-wire contract, dedicated signer and frozen canonical bytes, historical MAC validation, one-use same-request promotion, expiry through transactional audit/commit, exact active revocation, atomic rotation/destruction/rollback, recovery and legacy shared accounting. Reproduce adversarial PostgreSQL races and the full relevant quality gates, distinguish structural fixtures from genuine offline runtime positives, and compare PHPStan diagnostic objects and existing frontend debt. Confirm all 14 frozen hashes, preserved historical failures, zero real provider calls, no customer gateway consumption and disabled activation. Report blockers and the smallest bounded correction, or approve this increment for its next separately designed step. Do not enable verification/inference, provision live secrets, change frozen SQL, begin 7B.3c-2 or expand scope.

## Files changed

All paths are relative to the repository root. No migrations, dependencies, routes, UI, gateway resolver or frozen design files changed.

- `app/Fiscal/AnthropicCredentialVerificationAdapter.php`
- `app/Fiscal/AssistantProviderLedger.php`
- `app/Fiscal/AssistantProviderTransport.php`
- `app/Fiscal/CredentialPromotionPermit.php`
- `app/Fiscal/CredentialVerificationEvidence.php`
- `app/Fiscal/ResolvesAnthropicAddress.php`
- `app/Fiscal/TenantAiActiveLifecycle.php`
- `app/Fiscal/TenantAiCredentialVerifier.php`
- `app/Fiscal/TenantAiLegacyAccounting.php`
- `app/Fiscal/TenantAiVerificationAdapter.php`
- `app/Fiscal/TenantAiVerificationAdmission.php`
- `app/Fiscal/TenantAiVerificationContext.php`
- `app/Fiscal/TenantAiVerificationPolicy.php`
- `app/Fiscal/TenantAiVerificationPolicyResolver.php`
- `app/Fiscal/TenantAiVerificationQuota.php`
- `app/Fiscal/TenantAiVerificationReceipt.php`
- `app/Fiscal/TenantAiVerificationReceiptKeyFile.php`
- `app/Fiscal/TenantAiVerificationRecovery.php`
- `app/Fiscal/TenantAiVerificationRequest.php`
- `app/Fiscal/TenantAiVerificationResponseBuffer.php`
- `app/Fiscal/TenantAiVerificationResult.php`
- `app/Fiscal/TenantAiVerificationSecret.php`
- `app/Fiscal/TenantAiVerificationTransport.php`
- `app/Fiscal/VerificationRequestPermit.php`
- `config/tenant_ai.php`
- `tests/AssistantProviderFixtures.php`
- `tests/Feature/AiGatewayFacadeTest.php`
- `tests/Feature/AnthropicGatewayMigrationTest.php`
- `tests/Feature/AssistantProviderExecutionTest.php`
- `tests/Feature/TenantAiLifecycleTest.php`
- `tests/Feature/TenantAiStorageTest.php`
- `tests/TenantAiVerificationQuotaFixtures.php`
- `tests/TenantAiVerifierFixtures.php`
- `tests/TestCase.php`
- `tests/Unit/PostgresAssistantProviderTest.php`
- `tests/Unit/PostgresTenantAiStorageTest.php`
- `tests/Unit/PostgresTenantAiVerificationQuotaTest.php`
- `tests/Unit/PostgresTenantAiVerifierTest.php`
- `tests/Unit/TenantAiVerificationProtocolTest.php`
- `tests/Unit/TenantAiVerificationQuotaTest.php`
- `tests/Unit/TenantAiVerificationReceiptTest.php`
- `docs/phase-7b-3c-1-provider-verification-implementation-report.md`
- `docs/phase-7b-3c-1-provider-verification-implementation-evidence.json`

Full current and task-entry hashes are in the evidence artifact. Its own hash is omitted to avoid a self-referential checksum.

## Current implementation verdicts

| Boundary                                          | Verdict                                                 |
| ------------------------------------------------- | ------------------------------------------------------- |
| ACTIVE SEMANTICS                                  | PROVIDER-VERIFIED (offline fixture realm only in tests) |
| DATABASE SELF-AUTHORIZED PROMOTION                | ABSENT                                                  |
| VERIFICATION AUTHORITY                            | EXPLICIT                                                |
| PROVIDER VERIFIER CONTRACT                        | PROVIDER-NEUTRAL                                        |
| CUSTOMER SECRET DECRYPTION                        | JUST-IN-TIME                                            |
| SECRET SERIALIZATION                              | PROHIBITED                                              |
| VERIFICATION NETWORK OPERATION                    | BOUNDED                                                 |
| USER/TENANT BUSINESS DATA IN VERIFICATION CONTEXT | ABSENT                                                  |
| VERIFICATION EVIDENCE                             | AUTHENTICATED                                           |
| EVIDENCE BINDING                                  | COMPLETE                                                |
| EVIDENCE REPLAY                                   | FAIL CLOSED                                             |
| STALE EVIDENCE                                    | FAIL CLOSED                                             |
| TOCTOU REVALIDATION                               | COMPLETE                                                |
| VERIFY-VS-REVOKE                                  | FAIL CLOSED                                             |
| VERIFY-VS-REPLACE                                 | FAIL CLOSED                                             |
| VERIFY-VS-DISABLE                                 | FAIL CLOSED                                             |
| SIMULTANEOUS PROMOTION                            | SERIALIZED                                              |
| ACTIVE ROTATION                                   | ATOMIC                                                  |
| VERIFICATION RATE/BUDGET CONTROL                  | DEFINED AND ENFORCED                                    |
| AUDIT                                             | METADATA-ONLY                                           |
| TEST SYNTHETIC AUTHORITY                          | PRODUCTION-UNREACHABLE                                  |
| FROZEN 7B.3c-1 SCHEMA                             | SUFFICIENT                                              |
| AI GATEWAY CREDENTIAL CONSUMPTION                 | ABSENT                                                  |
| PRODUCTION INFERENCE                              | DISABLED                                                |
| ACTOR QUOTA KEY                                   | A1 ONLY                                                 |
| ACTOR QUOTA SCOPE                                 | CROSS-TENANT CROSS-PROVIDER CROSS-ROOT                  |
| A1 ADVISORY SERIALIZATION                         | ENFORCED                                                |
| A1 LOCK BEFORE ROOTS                              | VERIFIED                                                |
| COUNT + ADMISSION COMMIT                          | SAME SERIALIZATION BOUNDARY                             |
| SAME-ROOT 4→5 RACE                                | MAX 5                                                   |
| CROSS-ROOT 4→5 RACE                               | MAX 5                                                   |
| CROSS-TENANT 4→5 RACE                             | MAX 5                                                   |
| CROSS-PROVIDER 4→5 RACE                           | MAX 5                                                   |
| CROSS-CREDENTIAL 4→5 RACE                         | MAX 5                                                   |
| DIFFERENT ACTORS                                  | INDEPENDENT                                             |
| PROVIDER NETWORK UNDER A1 LOCK                    | NO                                                      |
| CR1 DATABASE SCOPE                                | DOCUMENTED                                              |

## Preserved historical B1 report — not the current decision

The following report is retained verbatim as historical evidence. Its former stop decision is not rewritten into a pass. CR1 and the current execution above are a separate successor.

# Phase 7B.3c-1 — provider-verification implementation blocker

Date: 2026-10-09. **Implementation blocked before runtime changes.** No verifier, decryption consumer, signer, promotion service or new runtime configuration was added. The required stop condition applies to an unresolved quota-scope/serialization decision in the approved design. This is not a finding that frozen SQL must change.

## B1 — actor quota scope and serialization are underspecified

**Severity: BLOCKER for implementation approval. Classification: security-design gap, not an introduced runtime defect.**

The [approved runtime design §9](phase-7b-3c-1-provider-verification-design.md) requires five admissions per trailing 3,600 seconds per actor A1 across workspaces, under primary root locks. Section 10 specifies PostgreSQL READ COMMITTED and deployment-root locking. [Supplement §13.7](phase-7b-3-tenant-ai-control-plane-supplement.md) repeats the cross-workspace actor limit. Neither specifies a deployment qualifier for that actor limit, a cross-deployment actor serialization lock, nor a single-deployment-per-database restriction.

Relevant exact locations at this checkpoint:

- `docs/phase-7b-3c-1-provider-verification-design.md:127`: actor limit and bounded LIMIT 1/5 probes.
- `docs/phase-7b-3c-1-provider-verification-design.md:133`: READ COMMITTED and canonical lock order.
- `docs/phase-7b-3-tenant-ai-control-plane-supplement.md:576`: actor A1 across workspaces.
- `app/Fiscal/TenantAiSchema.php:35`: root/control uniqueness is `(deployment_id, kind, subject_key)`.
- `app/Fiscal/TenantAiSchema.php:42`: settings are workspace-bound and carry a deployment.
- `app/Fiscal/TenantAiVerificationSchema.php:210`: actor history index is `(actor_attribution_id, purpose, admitted_at, id)`, partial on gateway probes; no global actor admission uniqueness/serialization guard.
- `tests/Unit/PostgresAiVerificationMigrationTest.php:71`: existing tests expressly exercise independent deployments in one database.

An actor can therefore have workspaces assigned to distinct deployment roots in the same accepted schema. Locking each request's deployment root serializes that deployment but does not serialize the unqualified actor history predicate across roots. READ COMMITTED plus LIMIT 5 does not lock the missing fifth admission. The one-inflight constraint protects a connection, not this actor-wide boundary.

### Fresh PostgreSQL counterexample

A new disposable PostgreSQL 18.6 database, `facturac_test_verification_quota_20261009`, was created from template0 with UTF8/C locale. The actual complete migration chain installed successfully; inspection confirms 86 migrations, zero unvalidated public constraints and zero disabled user triggers on attempts/allocations.

The scratch test prepares schema-valid pending credentials, bindings, approvals, windows and allocations using a modified **scratch copy** of the frozen structural fixture. One shared actor has distinct workspaces/connections/deployments. Four probe admissions already exist. Two independent PHP/PDO worker processes:

1. begin READ COMMITTED transactions with 250 ms lock and 1 s statement timeouts;
2. lock their respective deployment controls, budgets, settings, connection, credentials and approval/acknowledgement rows in class order;
3. each queries the same actor's trailing-hour gateway probe history using ordered LIMIT 5;
4. synchronize after both observe four rows;
5. each inserts its fifth-perceived admission and all nine allocations, forces deferred constraints and commits.

Both committed. The final count is **six admissions, one actor, six workspaces, six deployments**. No constraints were disabled, no active credentials were manufactured and no provider call or decryption occurred. Pest result: **1 passed, 15 assertions**. This pass means the counterexample was reproduced; it is **not** a passing security acceptance gate.

The harness tests the proposed read/count/insert serialization strategy against actual frozen persistence. It is not an execution test of a completed runtime service. Structural fixture approvals/controls are not live grants; the harness deliberately does not claim fresh human authorization, audit integration, real verification or receipt authenticity. The additional fixed policy checks and metadata audit do not supply the missing shared actor lock. Same-deployment requests are not shown vulnerable by this counterexample.

### Required Astra decision

Resolve precisely one scope/lock decision before implementation resumes:

1. **Preserve an actor-wide limit across deployments sharing the database.** Specify a bounded common serialization resource for the same A1, its canonical position relative to deployment roots and existing writers, timeout/failure behavior, actor deletion/deactivation handling, and independent-process acceptance tests. An existing-row or advisory-lock design may avoid schema changes, but requires explicit review of lock ordering and coverage.
2. **Define the limit as deployment-scoped.** Explicitly amend the actor quota semantics, query predicates and tests. Assess whether the frozen actor index still supports the required bounded work when other deployments have large histories. This is a contract change, not a harmless query optimization.
3. If isolation is intended to rely on **one deployment per database**, specify and enforce that prerequisite; the current schema and governing tests do not establish it.

Do not silently add a deployment predicate, introduce an unreviewed actor/advisory lock, switch to SERIALIZABLE, or lock every deployment root. The latter also needs a bounded lock-set and root-creation protocol. No option is approved by this implementation attempt. Prefer preserving the intended actor abuse boundary, but Astra must decide its exact scope and serialization mechanism.

## Work performed and preservation

Read the pasted implementation request and governing runtime design; checked relevant VC1, frozen DDL, execution-review evidence, actual context/custody/lifecycle/transport/catalogue/ledger/schema and tests. Applied Laravel and Pest skills. Boost search-docs tools were unavailable. No production code was edited.

Verified the design report hash and all **14 frozen artifact hashes** against accepted design evidence before investigation and again at completion. The **847 preexisting files** inventoried under app/config/database/routes/tests/docs remain unchanged. R2, R3, canonical receipt vectors, reconciliation and all historical reports remain byte-identical. No prior failure is rewritten as a pass.

Only this report and `phase-7b-3c-1-provider-verification-implementation-evidence.json` are added to the repository. Reproduction source, exact commands, output and hashes are embedded in that evidence; scratch files remain under `/private/tmp/verification-quota-design-check`. The disposable database is retained for inspection. Test framework storage is isolated under `storage/framework/testing/verification-quota-design-check`.

## Validation and unsuccessful attempts

| Check                                                       | Result                                                                         |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------ |
| Approved design and 14 frozen hashes                        | PASS, exact match                                                              |
| Fresh complete migration chain                              | Exit 0; 86 migrations present                                                  |
| Two-process quota counterexample                            | Exit 0; 1 test, 15 assertions; both workers saw 4 and committed; final count 6 |
| PostgreSQL constraint inspection                            | Zero unvalidated constraints; zero disabled attempt/allocation user triggers   |
| Preexisting source/document preservation                    | PASS, 847 files unchanged                                                      |
| New report/evidence format and JSON validation              | Recorded in evidence                                                           |
| Full runtime verification, receipt, rotation and race gates | NOT RUN; blocked before implementation                                         |
| Complete SQLite/PG integration/concurrency regressions      | NOT RUN; no runtime changes and explicit stop condition reached                |
| PHPStan, Pint, types, repository ESLint/format gates        | NOT RUN; no PHP/JS/runtime changes; no new passing claim                       |

Initial sandboxed PostgreSQL connection was denied by the filesystem/network sandbox. Authorized local-test escalation succeeded. The first scratch test failed before admission setup because its copied fixture lacked the `Workspace` import: 0 passed, 1 error, 1 assertion. Fixed only the scratch import and reran; the final counterexample passed. The first successful installation command/status is retained; its console log was overwritten by the later no-op migration run, so the evidence does not claim to retain the original complete installation transcript. The current migrations table independently confirms all 86 installations.

Inherited 21 PHPStan diagnostics, 9,719 ESLint errors, four resource-format failures and historical SQLite/PostgreSQL warnings were not remeasured. Nothing in this attempt changes their source, fixes them or establishes a fresh baseline. They are separate from B1.

## Implementation status and required verdicts

The requested binary runtime verdicts cannot honestly be assigned ENFORCED/VERIFIED/ATOMIC before implementation. “Not implemented / not verified” below is deliberate, not a passing substitute.

| Required boundary                     | Current evidence                                                                      |
| ------------------------------------- | ------------------------------------------------------------------------------------- |
| ACTIVE SEMANTICS                      | Approved PROVIDER-VERIFIED meaning preserved; runtime authority not implemented       |
| DATABASE SELF-AUTHORIZED PROMOTION    | No new consumer; runtime rejection not implemented/tested                             |
| VERIFICATION AUTHORITY                | NOT IMPLEMENTED / NOT VERIFIED                                                        |
| PROVIDER VERIFIER CONTRACT            | Approved provider-neutral design; NOT IMPLEMENTED                                     |
| CUSTOMER SECRET DECRYPTION            | No production consumer added; JUST-IN-TIME requirement unimplemented                  |
| SECRET SERIALIZATION                  | No new secret-bearing objects; runtime gate NOT VERIFIED                              |
| VERIFICATION NETWORK OPERATION        | NOT IMPLEMENTED; no network operation executed                                        |
| BUSINESS DATA IN VERIFICATION CONTEXT | ABSENT from reproduction; runtime context not implemented                             |
| VERIFICATION EVIDENCE                 | Authenticated runtime issuer NOT IMPLEMENTED                                          |
| EVIDENCE BINDING                      | Runtime gate NOT VERIFIED; frozen vectors unchanged                                   |
| REQUEST-LOCAL PROMOTION AUTHORITY     | NOT IMPLEMENTED / NOT VERIFIED                                                        |
| FINALIZATION + PROMOTION              | Required same transaction; NOT IMPLEMENTED                                            |
| RECEIPT REPLAY                        | Runtime gate NOT VERIFIED                                                             |
| STALE EVIDENCE                        | Runtime gate NOT VERIFIED                                                             |
| VERIFY-VS-REVOKE                      | Runtime gate NOT VERIFIED                                                             |
| VERIFY-VS-REPLACE                     | Runtime gate NOT VERIFIED                                                             |
| VERIFY-VS-DISABLE                     | Runtime gate NOT VERIFIED                                                             |
| VERIFY-VS-POLICY-CHANGE               | Runtime gate NOT VERIFIED                                                             |
| SIMULTANEOUS PROMOTION                | Runtime gate NOT VERIFIED; frozen structural evidence preserved                       |
| ACTIVE ROTATION                       | Runtime gate NOT VERIFIED                                                             |
| ROTATION ROLLBACK                     | Runtime gate NOT VERIFIED                                                             |
| VERIFICATION RATE/BUDGET CONTROL      | BLOCKED: unqualified actor quota admits 6 under separate deployment-root locks        |
| AUDIT                                 | No runtime audit integration added; reproduction is not an audit gate                 |
| SYNTHETIC TEST AUTHORITY              | PRODUCTION-UNREACHABLE: scratch structural fixture only, no issuer or runtime binding |
| FROZEN SCHEMA                         | PRESERVED                                                                             |
| AI GATEWAY CREDENTIAL CONSUMPTION     | ABSENT; dependency graph unchanged                                                    |
| REAL PROVIDER CALLS DURING TESTS      | ZERO                                                                                  |
| PROVIDER ACTIVATION                   | UNCHANGED                                                                             |
| PRODUCTION INFERENCE                  | DISABLED; hardcoded provider enabled/egress flags remain false                        |

The exact approved GET, destination, secret boundary, receipt/key custody, transaction sequence, remaining quotas, failure mapping and audit allowlist remain in the signed design; none is claimed delivered by this report. No model, endpoint, SDK, key, credential, provisioning, UI, route, command, job or next-phase functionality was introduced.

## Exact narrow Astra review handoff

> Resolve only B1 in `docs/phase-7b-3c-1-provider-verification-implementation-report.md` and its evidence. Read approved runtime design §§9–10, supplement §13.7, frozen root/actor-index definitions and the embedded fresh PostgreSQL counterexample. Decide whether five actor-A1 admissions per trailing hour spans deployments sharing one database or is deployment-scoped. If global, specify the common serialization resource and complete canonical lock order, boundedness, lifecycle and failure rules; if deployment-scoped or single-deployment topology is intended, explicitly amend and enforce that boundary and assess query-plan implications. Preserve every unrelated decision. Do not modify frozen SQL unless a separately reviewed correction is genuinely required. Define mandatory two-process cross-deployment regression criteria and return an exact corrected bounded implementation handoff. Do not implement runtime verification, make provider calls, enable inference or begin 7B.3c-2.

PHASE 7B.3c-1 PROVIDER-VERIFICATION IMPLEMENTATION BLOCKED — SECURITY DESIGN REVIEW REQUIRED

## Current final decision

PHASE 7B.3c-1 PROVIDER-VERIFICATION IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT SECURITY REVIEW
