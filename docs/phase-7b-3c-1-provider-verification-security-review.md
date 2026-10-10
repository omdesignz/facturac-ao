# Phase 7B.3c-1 — independent provider-verification security review

Date: 2026-10-09. **REVIEW FAILED: one unresolved MEDIUM finding.** This is a review of the frozen implementation candidate, not a remediation or activation decision. No implementation, repository test, configuration, migration, frozen design, or historical report was edited. Review harnesses ran outside the repository. No provider API was called; two inert loopback HTTP experiments used no credentials.

## Finding M1 — conflicting HTTP framing can become authenticated active authority

**Severity: MEDIUM. Introduced in this increment.** Location: `app/Fiscal/TenantAiVerificationResponseBuffer.php:99–135`, particularly the framing checks at lines 99–110; transport callbacks are in `app/Fiscal/TenantAiVerificationTransport.php:100–102`.

The parser validates Content-Encoding and Content-Length but never rejects simultaneous Transfer-Encoding and Content-Length. A response carrying `Transfer-Encoding: chunked`, Content-Length equal to the decoded model JSON length, and otherwise correct model/account claims returns `provider_authenticated_model_visible`. The real offline verifier then signs a promoted receipt and commits an active credential. This contradicts frozen design §5's explicit rejection of ambiguous headers and §11's malformed-protocol failure boundary.

This is not merely a permissive test double. An independent loopback server sent the actual chunked HTTP response to the installed libcurl 8.22.0. cURL succeeded with errno 0, delivered **both** headers to the production buffer and decoded the chunks; the buffer returned the successful observation. Separately, the approved raw-wire seam exercised actual authorization, admission, decryption, parsing, signing, receipt finalization and promotion, yielding:

```text
result: verified-and-active
credential_state: active
receipt_authentic: true
promotion_disposition: promoted
```

Evidence: `framing-counterexample.json`, `local-framing.json`, the corresponding PHP harnesses and hashed command logs in the review evidence. The counterexample test deliberately asserts the observed unsafe behavior; its passing test result means **the defect reproduced**, not that the security gate passed.

RFC 9112 §6.3 identifies Transfer-Encoding plus Content-Length as conflicting framing that ought to be handled as an error. The frozen application contract is stricter than tolerating the transport library's framing precedence. [RFC 9112 §6.3](https://www.rfc-editor.org/rfc/rfc9112.html#section-6.3).

**Impact:** a protocol-invalid observation crosses the success/receipt/active boundary. This does not demonstrate bypass of TLS, account claims, owner authorization, MAC verification, CR1, or gateway isolation; a malformed response must still arrive through the approved authenticated exchange. Those limits justify MEDIUM rather than claiming a remote unauthenticated takeover. It remains an acceptance failure even with inference disabled.

**Required bounded remediation:** reject conflicting framing before producing any success observation. Keep legitimate fixed-length and ordinary chunked responses supported within the existing bounds. Add parser and real-verifier regressions for both header orders and mixed header case; assert no success receipt, no promotion/rotation, unchanged prior active credential and retained admitted liability. Include an inert local-cURL regression so the fix does not assume libcurl rejects the pair. Retain all existing size/deadline/duplicate-header rules. No SQL, canonical receipt, authority, account, endpoint or activation change is needed. Do not remediate other phases.

A separate local `Transfer-Encoding: gzip, chunked` experiment failed in cURL with errno 61 before successful parsing. It is **not** reported as an additional defect.

## Candidate and evidence integrity

The review began by hashing 802 application, test, configuration, route, database and governing artifact files. Implementation file hashes matched the submitted evidence. The review evidence embeds the full candidate inventory and its hash. All 14 frozen package hashes are checked separately. The six-admission historical CR1 counterexample, reconciliation MEDIUM, R2/R3 history and corrected CR1 design remain unchanged; none has been rewritten as a first-attempt pass.

189 retained implementation logs were found and all matched their submitted SHA-256 values. The broad implementation counts match the actual JSON results in those logs: PostgreSQL integration 1,543/11,301 assertions; concurrency 336/9,712; SQLite remainder 2,364/15,600, with 595 PostgreSQL-only skips and one warning; separately executed unchanged quota case 1/64. These are authenticated retained implementation results, not represented as fresh review executions. The earlier unpartitioned SQLite failure remains visible.

## Actual production call graph and authority trace

There is no controller, route, command or job for verification in this increment. The internal application entry is `TenantAiCredentialVerifier::verify`; a future thin owner POST entry remains unimplemented.

```text
TenantAiVerificationContext::resolve
  bounded primary read transaction; exact HTTP request / A1 / membership / entity / production
TenantAiCredentialVerifier::verify
  bounded preflight read transaction: reviewed policy + signer custody
A: TenantAiVerificationAdmission::admit
  outermost READ COMMITTED transaction
  A1 advisory lock → root → provider/model controls → budgets → settings
  → connection → UUID-ordered credentials → approval → acknowledgement → windows
  fresh quota query → nine reservations → attempt / allocations → required audit
  COMMIT: all A1 and row locks released
JIT: TenantAiVerificationSecret::load
  one-use session handle → bounded canonical-lock recheck → direct prepared PDO envelope read
  authenticated unwrap/decrypt: PLAINTEXT BEGINS
  COMMIT: row locks released
B: fixed Anthropic adapter / transport
  bounded DNS → pinned TLS → prerequisite callback
  bounded send-authorization transaction: current authority + send audit → COMMIT
  one bodyless GET → bounded response parser → typed observation
  finally: release secret value, headers/handle/closure/buffer references: PLAINTEXT ENDS
C: registered promotion object claimed and removed
  bounded canonical-lock transaction → snapshot/authority/history revalidation
  private final receipt MAC → attempt finalization → old retirement/destruction
  → candidate active / generation / selection → metadata audits
  force deferred integrity → final deadline/authority checks → COMMIT
safe result; verifier registry and captured state cleared in finally
```

| Responsibility | Production implementation |
| --- | --- |
| Owner authorization and request identity | `TenantAiVerificationContext::resolve/assertRequest/authorize`, backed by `TenantAiContext::authorize`; exact request, PID, fiber, A1, original membership, owner, MFA, email, CSRF, reauthentication and work-session checks |
| Immutable CAS assertions | `TenantAiVerificationRequest`; identifiers and revisions are assertions, never authority |
| Independent approved policy | `TenantAiVerificationPolicyResolver::resolve`; code manifests/account mapping plus current primary controls, grant, acknowledgement, profile and candidate |
| CR1 key and lock | `TenantAiVerificationQuota::key/acquire`; domain + LF + exact A1, SHA-256 signed int4, namespace 1180058417 |
| Post-wait quota and admission commit | `TenantAiVerificationQuota::assertAvailable`, `TenantAiVerificationAdmission::admit`; separate statement, DB clock, exact actor, LIMIT 5; atomic nine allocations, attempt and audit |
| JIT custody | `TenantAiVerificationSecret::load/exchange`; private SensitiveParameterValue, exact envelope binding, raw PDO, no generic plaintext accessor |
| Provider-neutral boundary | `TenantAiVerificationAdapter` and `CredentialVerificationEvidence`; no SDK/HTTP response objects in application authority |
| Fixed provider operation | `AnthropicCredentialVerificationAdapter::observe`, `TenantAiVerificationTransport::exchange/wire/options`, shared `ResolvesAnthropicAddress` |
| Observation validation | `TenantAiVerificationResponseBuffer`; otherwise bounded and allowlisted, but M1 violates required framing rejection |
| Cryptographic evidence | `TenantAiVerificationReceipt::canonical/fields/authentic`; exact 47 positions, domain-separated HMAC-SHA256, canonical lowercase IDs, sorted references |
| Key custody / private issuer | `TenantAiVerificationReceiptKeyFile::read`; canonical protected files and trust; `TenantAiCredentialVerifier::completePromotion/receiptKey` owns signing |
| Request-local authority | Private session identity/state, `VerificationRequestPermit`, `CredentialPromotionPermit`; `claimDecrypt`, `authorizeSend`, `consumePromotion`; scalars/MACs cannot register objects |
| Finalization and rotation | `TenantAiActiveLifecycle::promote` delegates to the same session's `completePromotion`; `lockCaptured`, `validateCaptured`, `authenticateActive`, `rotate` |
| Active-aware local lifecycle | `TenantAiActiveLifecycle::candidate/revoke/disableConnection/disableWorkspace`; exact-version CAS, root fencing and transactional envelope destruction |
| Required audit | `TenantAiVerificationAdmission::audit`, lifecycle/recovery audit, `RequiredAudit`; transaction-local, explicitly minimized metadata |
| Recovery | `TenantAiVerificationRecovery`; bounded stale-attempt scan, unknown/abandoned once, no secret/send/sign/promotion/refund |
| Legacy coexistence | `TenantAiLegacyAccounting` and discriminator/predicate-aware `AssistantProviderLedger`; no customer secret resolver |

Plaintext lifetime is bounded reference retention, not guaranteed PHP memory zeroization. Production FPM/APM/core-dump/profiler controls remain operational gates. Public DTO constructors and receipt authentication do not grant a private registry entry. Arbitrary reflection or deployed PHP replacement is outside the approved application-call threat boundary; reflection is confined to offline test composition.

## Independent CR1 experiments

A reviewer-authored harness, separate from `quotaRace`, used forked PHP workers with separate PostgreSQL connections. Worker 1 held the actual production A1 transaction lock; the parent observed worker 2 waiting in `pg_locks` before releasing worker 1. Each worker acquired its distinct root after A1 and used the production post-lock quota check. Structural pending fixtures and actual schema constraints were retained; no guard was disabled.

Starting with exactly four committed A1 admissions, both cross-root/cross-tenant/cross-credential and cross-provider-metadata cases produced one committed transaction, one quota refusal, final A1 count five. Actual backend PIDs, transaction IDs, roots and overlap evidence are embedded. Cross-provider coverage is intentionally inert metadata; it does not approve or implement another adapter. A different-actor control committed worker 2 while worker 1 still held its actor lock, proving independent serialization in execution, not only different key strings.

Fresh submitted quota tests additionally covered same-root races, timeout, rollback, collisions, exact rolling-hour and future-record semantics, unsupported isolation and lock ordering. Fresh full-verifier cross-root coverage exercised actual policy, reservations, signer and active lifecycle, not only structural fixture insertion. The guarantee is limited to processes/roots sharing one authoritative PostgreSQL writer and retained A1 history, not independent databases.

## Fresh review execution

| Gate | Fresh result |
| --- | --- |
| units | `{"tool":"pest","result":"passed","tests":56,"passed":56,"assertions":297,"duration_ms":1303}` |
| utf8-quota | `{"tool":"pest","result":"passed","tests":9,"passed":9,"assertions":143,"duration_ms":21084}` |
| utf8-runtime | `{"tool":"pest","result":"passed","tests":77,"passed":77,"assertions":1050,"duration_ms":153037}` |
| utf8-independent | `{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":24,"duration_ms":1367}` |
| utf8-forgery | `{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":4,"duration_ms":223}` |
| utf8-framing | `{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":16,"duration_ms":435}` |
| utf8-preservation | `{"tool":"pest","result":"passed","tests":190,"passed":190,"assertions":1673,"duration_ms":282178}` |
| isolation | `{"tool":"pest","result":"passed","tests":1,"passed":1,"assertions":19,"duration_ms":716}` |

Review harness sources, commands, exit statuses, database identities, log hashes and raw summaries are embedded in the evidence artifact. Existing runtime tests were copied outside the repository only to substitute the disposable database-name guard and fixture include paths; their assertions and production code were not changed.

Two review setup failures are retained: the first copied runtime suite still referenced the original fixture's database guard; the first databases inherited SQL_ASCII from the cluster template. Six custody cases explicitly refused that encoding. Corrected review setup used new databases created from template0 with explicit UTF8, and reran the acceptance-critical and preservation suites. Earlier results are retained separately, not described as UTF8 passes.

Independent receipt reproduction used Python JSON/HMAC, rather than the application canonicalizer: both frozen vectors matched exact bytes/MACs; all 94 changed positions changed the original MAC. Fresh PHP receipt tests additionally rejected invalid shapes, case, bounds and substitutions. The database-forgery experiment committed a structurally valid active/receipt fixture with an invalid MAC, confirmed it was unauthentic, and proved a new verifier plus constructed permit could not authorize promotion. SQL guards are integrity constraints, not MAC verifiers.

Fresh runtime coverage includes before-send and post-observation replace/revoke/workspace-disable/connection-disable/policy races, revision/config/approval staleness, one admitted contender, real active rotation, verify-only historical keys, rollback faults at receipt/retirement/activation/selection/audit, expiry, crash/recovery and late-worker refusal. The independent canary experiment additionally scanned query/audit/dump/application-log surfaces, rejected a new-request permit and ran the existing gateway against a genuinely offline-verified active row: unavailable result, no last-used write, no credential consumption.

No live provider request is necessary or used. Native verification wire rejects PHPUnit-loaded/testing execution; the only positive exchange seam is a test-only raw wire substitute. Loopback experiments demonstrate cURL parsing, not provider origin, TLS entitlement, real account ownership or production readiness.

## Gateway, custody and frozen-schema preservation

`AppServiceProvider` still binds `VapAiGateway` to `LegacyAssistantAiGateway`, which delegates to `AnthropicIntentPlanner` and the legacy invocation/permit/transport chain. Its key source remains the separately approved VAP private-file reference. None of those dependencies resolves `TenantAiVerificationSecret`, an active tenant credential or receipt-derived plaintext. Shared accounting is a quota dependency, not a secret-consumption bridge. New active rows do not authorize inference.

The frozen schema retains initial-admission rules, immutable snapshots/terminal receipts, nine exact allocations, exact public-ID bytes, reciprocal deferred receipt/credential bindings and immediate active uniqueness. Direct database structure alone intentionally cannot authenticate a MAC. Runtime checks complement those constraints. R2/R3 corrections and original failures remain intact.

Both verification switches remain false; manifest/account/signing entries remain empty. Inference enabled/egress defaults remain false. No credential, grant, activation approval, production environment file, migration, route, queue, schedule or external model provisioning was changed.

## Diagnostics and findings outside M1

- **INFORMATIONAL — inherited PHPStan:** fresh result is the exact same 21 diagnostic objects as the accepted baseline, including paths, lines and identifiers. No new diagnostic and no suppression.
- **INFORMATIONAL — inherited lint/format debt:** retained evidence reports 9,719 ESLint errors and four resource-format failures. This PHP review changes none of those files; no repository-wide lint/format repair was attempted. These do not explain or excuse M1.
- **INFORMATIONAL — retained SQLite warning/quota flake:** one warning and the historical minute-window test failure are preserved; successful partition execution is reported separately. No assertion was weakened. This is separate from CR1's PostgreSQL A1 rolling quota.
- **INFORMATIONAL / operational:** real account/model/cost/privacy/geography approval, dedicated signing/KEK custody, production FPM/APM isolation and single-writer topology attestation remain unresolved activation prerequisites. Offline fixtures do not satisfy them.

No additional BLOCKER/HIGH finding was reproduced. This review does not claim an exhaustive proof against arbitrary privileged host/PHP compromise. M1 alone prevents acceptance; passing regression counts do not override it.

## Required verdicts

| Boundary | Verdict |
| --- | --- |
| ACTIVE SEMANTICS | INCOMPLETE — M1 malformed framing can become active |
| CR1 ACTOR QUOTA KEY | A1 ONLY |
| CR1 CROSS-ROOT RACE | MAX 5 |
| CR1 CROSS-TENANT RACE | MAX 5 |
| CR1 CROSS-PROVIDER/CREDENTIAL SCOPE | PRESERVED |
| A1 LOCK BEFORE ROOTS | VERIFIED |
| COUNT + ADMISSION COMMIT | SAME SERIALIZATION BOUNDARY |
| PROVIDER NETWORK UNDER A1 LOCK | NO |
| VERIFICATION AUTHORITY | ENFORCED |
| CUSTOMER SECRET DECRYPTION | JUST-IN-TIME |
| SECRET LEAKAGE | ABSENT |
| BUSINESS DATA IN VERIFICATION REQUEST | ABSENT |
| VERIFICATION NETWORK OPERATION | BOUNDED — framing acceptance defect M1 remains |
| VERIFICATION EVIDENCE | AUTHENTICATED |
| EVIDENCE BINDING | VERIFIED |
| DATABASE-FORGED PROMOTION | REJECTED |
| REQUEST-LOCAL PROMOTION AUTHORITY | ENFORCED |
| FINALIZATION + PROMOTION | SAME TRANSACTION |
| PHASE-C FAILURE ATOMICITY | VERIFIED |
| RECEIPT REPLAY | FAIL CLOSED |
| STALE EVIDENCE | FAIL CLOSED |
| VERIFY-VS-REPLACE | FAIL CLOSED |
| VERIFY-VS-REVOKE | FAIL CLOSED |
| VERIFY-VS-DISABLE | FAIL CLOSED |
| VERIFY-VS-POLICY-CHANGE | FAIL CLOSED |
| SIMULTANEOUS PROMOTION | SERIALIZED |
| ACTIVE ROTATION | ATOMIC |
| ROTATION ROLLBACK | PRESERVES PRIOR ACTIVE |
| AUDIT | METADATA-ONLY |
| SYNTHETIC TEST AUTHORITY | PRODUCTION-UNREACHABLE |
| FROZEN SCHEMA | PRESERVED |
| 7B.3a CREDENTIAL CUSTODY | PRESERVED |
| 7B.3b LIFECYCLE | PRESERVED |
| AI GATEWAY CREDENTIAL CONSUMPTION | ABSENT |
| ACTIVE CREDENTIAL IMPLIES INFERENCE AUTHORITY | NO |
| REAL PROVIDER CALLS DURING REVIEW | ZERO |
| PROVIDER ACTIVATION | UNCHANGED |
| PRODUCTION INFERENCE | DISABLED |

## Exact bounded remediation handoff

> Remediate only independent review finding M1 in the Phase 7B.3c-1 response-framing boundary. Read this review/evidence and frozen provider-verification design §§5, 11 and 14. Preserve CR1, migrations, receipt bytes, request-local authority, fixed provider operation and all activation exclusions. Reject conflicting Transfer-Encoding plus Content-Length before creating a successful observation. Cover both header orders and casing, legitimate chunked/fixed-length controls, actual local cURL behavior and the real offline verifier. A malformed response must never sign a success receipt or promote/rotate; prior active state and admitted liability must remain correct. Do not call a real provider or alter the frozen design. Run affected parser/runtime/security and preservation gates, record exact evidence, and return for independent re-review. Do not begin 7B.3c-2 or activate inference.

PHASE 7B.3c-1 SECURITY REVIEW FAILED — REMEDIATION REQUIRED
