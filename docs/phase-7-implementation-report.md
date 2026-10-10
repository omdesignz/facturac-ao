# Phase 7 — intent-only assistant bounded implementation

Implementation authority: `phase-7-provider-privacy-decision.md` P7-PC1 and its exact section 10 handoff, read together with `phase-7-live-inference-security-contract.md` and the closed Phase 6 foundation. This report covers only the disabled foundation. It is not provider certification, privacy approval or activation authorization.

## Decision and validation status

**PHASE 7 BOUNDED IMPLEMENTATION — READY FOR ASTRA REVIEW**

All runtime gates and final supplemental adversarial cases pass, subject to the explicitly inherited repository diagnostics below. No implementation blocker remains identified. No production activation is authorized. No real credentials were added, no live inference was performed, production egress remains disabled and Phase 6 tool results remain outside model context.

## Implemented boundary

The existing first-party POST interaction creates a request-local invocation with its original explicit context, execution guard and permission ceiling. The provider planner revalidates that context and primary deployment/tenant/user admissions. Local disclosure filtering constructs a closed, ephemeral DTO. The private SDK bridge constructs the actual final request; byte admission and durable accounting precede its single-use permit. Exactly one installed SDK `generateTextStep` can use the isolated synthetic-tested transport. Original response bytes and the complete alias plan are validated before any application tool. Existing Phase 6 capabilities retrieve facts and render them deterministically; a retained permit rechecks privacy/authority through final disclosure.

No tool result, customer/fiscal/AGT record, rendered answer, actor identity or context identifier is passed back to the adapter. The SDK receives no tools. Model prose is never a factual response. The global throwing `AiManager`, Composer discovery quarantine and empty general provider configuration remain intact. Only `AssistantIntentGateway.php` may import the six explicitly reviewed SDK symbols; the architecture regression retains the other quarantine checks.

## Contract mapping

| Requirement                         | Implementation and verification                                                                                                                                                                                                                                                                                                                                                              |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Pinned P7-PC1 profile/model/pricing | `AssistantProviderProfile` centralizes exact model, endpoint, version, policy, price expiry, rates and ceilings. No aliases, remote metadata calls or provider fallback. Profile and expiry tests plus body serialization assertions.                                                                                                                                                        |
| Minimal provider DTO                | `ProviderIntentInput` accepts only the original validated question/references and the permission-derived static schema. It emits schema version, transformed question, `r1`/`r2` kind references, server month and approved tool descriptions. No record lookup or hidden enrichment. DTO and gateway tests assert exact keys.                                                               |
| Deterministic disclosure            | `AssistantDisclosure` applies original character/byte bounds, UTF-8/control checks, exact admitted-ID alias substitution, normalized detection and the approved token/numeric rules. It blocks before body construction/reservation. Names/prose and undetected encodings remain explicitly accepted residual risks requiring human privacy approval.                                        |
| Request-local authority             | `AssistantProviderInvocation` binds original context/guard to the actual first-party route; console execution is denied outside tests. `AssistantProviderPermit` binds invocation, fiber, canonical body digest, permission ceiling, attempt and deadline. Serialization/cloning/reuse/wrong invocation fail. The digest is memory-only, never audit data.                                   |
| Fresh privacy and permission checks | Primary deployment control, current tenant-owner authority, exact policy/profile/expiry and actor/workspace acknowledgement are checked at admission, permit consumption, immediately before transmission, after response and through local facts. Original and fresh permissions intersect. Membership/acknowledgement/tenant/circuit loss tests withhold results.                          |
| One restricted SDK operation        | Private installed 0.11.2 gateway subclass calls its parent request builder and adds only disabled thinking, low effort and US geography. Native JSON schema is retained. No provider prompt, Agent, tool loop, global manager enablement, middleware or SDK queue.                                                                                                                           |
| Bounded network                     | Fixed HTTPS endpoint, TLS host/peer verification, no redirects/proxy/compression, bounded DNS subprocess, public unicast checks, pinned destination and peer/port check. Two-second connect and eight-second total ceilings intersect remaining monotonic deadlines. Curl progress aborts on observed disconnect/deadline. Testing environment denies the real wire before DNS/curl.         |
| Original response validation        | Header/body callbacks bound accepted data to 16 KiB/64 KiB; strict envelope rejects duplicate keys, wrong model/geography/tier/content, truncation/refusal and billing anomalies. Original plan is at most 4 KiB; exact alias translation precedes unchanged Phase 6 whole-plan validation. No SDK normalization can repair an invalid original plan.                                        |
| Tokens and money                    | Actual final serialized request uses bytes + 1,024 admission estimate <= 8,192 (not a tokenization guarantee); 16 KiB outer limit and 1,024 output cap remain. Reserve 552,816 integer micro-USD per attempt against full one-million input/high-tier US envelope. Actual validated cost uses integer ceiling arithmetic. No refund.                                                         |
| Atomic commercial limits            | Five unique UTC windows, stable deployment budget identity, global A1 user/day identity across workspaces, workspace day/month and deployment day/month. Lock control then windows in signed order; admission, attempt and required audit commit atomically. No database transaction spans HTTP. PostgreSQL independent-process races exercise every last affordable ceiling.                |
| Durable finalization/recovery       | Lock original windows and attempt, add usage once, preserve reserve, retain nullable unknown usage and close circuit for unknown/anomalous sent attempts. Proven not-sent failure retains reserve without inventing zero usage. Bounded recovery classifies at most 100 stale attempts, never resends/refunds. Crash and concurrent-finalization tests use independent PostgreSQL processes. |
| Audit                               | Required attempted commits before send; required received/failed before facts. Metadata is closed A1/context/interaction/attempt/profile/policy/outcome/count/reservation data. No text, aliases, digest, headers, key or exception chain. Audit failure prevents send or withholds all facts. Recovery uses anonymous system attribution plus immutable original A1 snapshot.               |
| Minimal UI                          | Versioned Portuguese disclosure, explicit acknowledgement and withdrawal, same authenticated/CSRF/context boundary, 1 KiB closed JSON action. No acknowledgement inferred from login. Default UI accurately reports unavailable; context changes cancel/reset local state. No transcript persistence or provisioning UI.                                                                     |
| Existing foundation                 | Five tools, bounded customer search, explicit production context, tenant isolation, Phase 6 admission quotas/nonces/lease, deterministic rendering and all-or-nothing facts are retained. The planner seam gains only an optional request-local invocation for test/unavailable compatibility.                                                                                               |

## Migration and operational behavior

`2026_10_08_183139_create_assistant_provider_metadata_tables.php` adds only the five authorized tables: controls, windows, attempts, tenant admissions and acknowledgements. No approval is seeded or backfilled. Controls default disabled/circuit-blocked; configuration defaults disabled, egress disabled, missing secret/approval/notice and six zero budgets.

Unique window/interaction keys, immutable binding/snapshot triggers, composite entity/workspace foreign key, restrictive deletes, nonnegative counts, closed states, UUID guards and terminal-outcome/monotonic-counter guards complement application checks. Tenant revocation preserves history and permits a later distinct active admission. A1 snapshots do not depend on a live user relationship. The migration is tested on SQLite and disposable UTF-8 PostgreSQL, including down/up preservation of existing domain records. It has not been applied to production or the local MySQL application database. PostgreSQL remains the supported runtime gate.

`assistant:recover-provider-attempts` is a bounded operator command, not a queued inference/retry path. It uses the existing guarded database boundary, classifies stale admitted liabilities and emits a fixed summary. No scheduler or retention purge is introduced. Operators must separately approve recovery scheduling and the 90-day metadata policy; current windows and unresolved liability must not be purged. Database restore reconciliation remains an activation/runbook obligation, not an automatically detectable database property.

The signed candidate commercial ceilings are maximum configurable values in this profile; smaller approved limits may be set. Larger values require a reviewed replacement. Budget identity must survive key rotation and process restarts; a new key or profile string cannot reset existing counters through the implemented path. Exact pinned price expiry disables further admission.

## Secret and logging boundary

Only a protected deployment file reference is configured, never key content. Future file access checks absolute regular-file identity, restrictive permissions, size and inode/device consistency before bounded reading. No real key was installed. Tests create short-lived inert sentinel files and delete them.

The SDK dispatcher and HTTP factory are isolated from global listeners/recorders. Native curl carries the fixed authentication header; only bounded validated response bytes return to the bridge. Failures are converted to existing closed HTTP outcomes without raw error chains. This does not prove infrastructure/APM logging or operating-system isolation; those require deployment evidence. The application gate is not a sandbox against a developer who can rewrite trusted PHP.

## Tests and validation

Machine-readable command/exits/counts, log hashes, changed-file hashes and dependency preservation are recorded in `phase-7-implementation-evidence.json`. Historical counts in earlier reports are not presented as fresh Phase 7 executions.

New suites: `AssistantProviderContractTest`, `ProviderIntentInputTest`, `AssistantProviderProtocolTest`, `AssistantProviderExecutionTest`, `PostgresAssistantProviderTest`; shared synthetic fixtures and four additional actual-page UI cases. Existing quarantine and deterministic planner fixtures were minimally adapted. No test was deleted or relaxed to obtain a pass.

| Fresh gate                                              | Result                                                                                                                                         |
| ------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Complete SQLite, final runtime                          | Exit 0; **2,109 passed, 314 PostgreSQL-only skipped, 13,899 assertions**, 762,746 ms; one inherited discovery warning.                         |
| Complete UTF-8 PostgreSQL concurrency group             | Exit 0; **313 passed, 9,374 assertions**, 857,705 ms; one inherited discovery warning.                                                         |
| Preserved PostgreSQL integration selection plus Phase 7 | Exit 0; **1,336 passed, 9,764 assertions**, 1,398,886 ms; no warnings/skips.                                                                   |
| Final Phase 7 PostgreSQL supplement                     | Exit 0; **157 passed, 827 assertions**, 119,074 ms: final 18 provider/concurrency cases plus 139 protocol/HTTP cases.                          |
| Final adverse Phase 6 PostgreSQL customer-search gate   | Exit 0; **33 passed, 1,165 assertions**, 68,090 ms. Full fresh plans copied into Phase 7 evidence; original Phase 6 artifact restored exactly. |
| Final Portuguese vectors / actual SDK framing           | Exit 0 on both SQLite and PostgreSQL; **22 passed, 54 assertions** each. Includes the final eight synthetic Portuguese evaluation vectors.     |
| Focused Phase 7 SQLite security suites                  | Exit 0; **139 passed, 566 assertions**, 32,608 ms, before the final eight vector-only additions covered above.                                 |
| UI                                                      | Exit 0; **12 passed** (eight preserved, four added).                                                                                           |
| Types / changed ESLint / changed Prettier               | Exit 0 each.                                                                                                                                   |
| Pint / diff whitespace                                  | Exit 0 each; no previously untouched PHP file was reformatted.                                                                                 |
| PHPStan                                                 | Exit 1; **exactly the 21 inherited file/line/message/identifier diagnostics**, no new diagnostics; byte-for-byte diagnostic-object comparison. |
| Full ESLint                                             | Exit 1; **9,719 inherited errors** in 215 files, all unchanged against the starting hashes; changed-file gate clean.                           |
| Full resource Prettier                                  | Exit 1; same four inherited failures, all unchanged.                                                                                           |

These runs overlap; do not sum them as unique tests. The complete suite ran against final runtime code. Test-only post-finalization-crash and Portuguese-vector additions were validated by the explicit later PostgreSQL/SQLite supplements rather than misrepresented as part of the earlier full-run counts. No runtime code changed afterward.

PostgreSQL evidence: **18.6 (Homebrew), UTF8, C/C collation/ctype**, two separate disposable `facturac_test_phase7_*` databases on local port 55439. No production database was queried or migrated. The inherited warning is the ineffective `DomainException` import at unchanged `tests/Feature/PhaseFiveSubscriptionBillingTest.php:20`; a direct lint reproduction is retained. Four inherited format files: `resources/js/pages/Establishments/Index.vue`, `resources/promo/coming-soon.html`, `resources/promo/render.mjs`, `resources/promo/switch.html`. None of this debt weakens the provider trust boundary, and none was suppressed or repaired here.

The evidence artifact lists every changed path with before/after hashes, the exact commands and logs, complete diagnostic comparison, installed dependency metadata and the fresh inherited query plans. Temporary test-generated changes to the Phase 6 evidence were restored; no signed contract was rewritten.

## Failed attempts and corrections

- PostgreSQL repeated `migrate:fresh` initially found existing trigger functions after table cleanup. Migration functions now use `CREATE OR REPLACE`; down removes only its own functions.
- A cross-workspace fixture omitted required `joined_at`; corrected the fixture. Its first exception assertion passed an interface to Pest, which treated it as message text; replaced with explicit caught status 429 and persisted-budget assertions.
- Initial Wayfinder generation omitted `--with-form`, causing generated helper type failures. Regeneration with the established option restored helpers; no dependency change.
- New UI SSR harness initially loaded the real Inertia Head context; isolated its test stub correctly. A disabled-state regex accidentally matched a CSS variant; now checks the actual HTML attribute.
- Initial static analysis found missing new PHPDoc generics/array types and an unnecessary nullsafe access; corrected without suppressions. JSON-format invocations also exited with no report; the established debug command supplied inspectable diagnostics.
- The actual nested-JSON framing fixture initially assumed a single Unicode escape width; corrected it to measure the installed SDK framing. A Portuguese search vector initially used an unsupported `prefix` argument; corrected it to the unchanged Phase 6 `q`/`status` contract.
- PostgreSQL 18 environment inspection required database catalogue collation fields rather than the removed `lc_collate` setting; corrected the read-only evidence query.
- Changed-file ESLint found eleven new style diagnostics; corrected only the Phase 7 files. Inherited repository-wide diagnostics are recorded separately.

## Exclusions and remaining activation gates

No real inference, real credentials, production egress, external integration enablement, new tools, writes/fiscal actions, overdue analytics, model synthesis of results, history, streaming, retries/failover, BYOW/BYOK, agents, webhooks, public API or credential/admin UI. No dependencies/vendor files changed. Existing Phase 1–6 signed contracts are preserved.

P7-PC1 section 8 remains wholly gated: legal/privacy acceptance of names/prose/residual detector limits; commercial account/DPA/subprocessors; retention or confirmed ZDR exceptions; actual US workspace/model/native schema compatibility; budget and metadata-retention owners; approved localized notice; independent Astra review and separately authorized synthetic provider rehearsal; expiring secret-store key/rotation; deployment egress/TLS/DNS/logging controls; recovery/restore/kill-switch incident runbooks; and named dated activation signoff. Local fake-envelope tests establish no model accuracy, real billing behavior, live latency, provider availability or retention guarantee. Refresh pricing before its sealed expiry.

## Changed-file inventory

- `app/Console/Commands/RecoverAssistantProviderAttempts.php`
- `app/Fiscal/AnthropicIntentPlanner.php`
- `app/Fiscal/AssistantDisclosure.php`
- `app/Fiscal/AssistantExecutionGuard.php`
- `app/Fiscal/AssistantIntentGateway.php`
- `app/Fiscal/AssistantInteraction.php`
- `app/Fiscal/AssistantPlanner.php`
- `app/Fiscal/AssistantProviderInvocation.php`
- `app/Fiscal/AssistantProviderLedger.php`
- `app/Fiscal/AssistantProviderPermit.php`
- `app/Fiscal/AssistantProviderProfile.php`
- `app/Fiscal/AssistantProviderResponse.php`
- `app/Fiscal/AssistantProviderResponseBuffer.php`
- `app/Fiscal/AssistantProviderTransport.php`
- `app/Fiscal/ProviderIntentInput.php`
- `app/Fiscal/UnavailableAssistantPlanner.php`
- `app/Http/Controllers/AssistantController.php`
- `app/Http/Middleware/AssistantBoundary.php`
- `app/Providers/AppServiceProvider.php`
- `config/assistant.php`
- `database/migrations/2026_10_08_183139_create_assistant_provider_metadata_tables.php`
- `resources/js/pages/Assistant/Index.vue`
- `routes/web.php`
- `tests/AssistantFixtures.php`
- `tests/AssistantProviderFixtures.php`
- `tests/Feature/AiExecutionQuarantineTest.php`
- `tests/Feature/AssistantProviderContractTest.php`
- `tests/Feature/AssistantProviderExecutionTest.php`
- `tests/Feature/AssistantProviderProtocolTest.php`
- `tests/Feature/ProviderIntentInputTest.php`
- `tests/Unit/PostgresAssistantProviderTest.php`
- `tests/assistant-ui.test.mjs`

The two new deliverables are this report and `docs/phase-7-implementation-evidence.json`. Generated Wayfinder helpers were regenerated with `--with-form`; they remain generated/ignored artifacts. The evidence contains before/after hashes for every nonignored changed path.

## Exact Astra independent final-review handoff

> Perform the Astra Medium independent final review of the Phase 7 intent-only assistant bounded implementation. Read in full `docs/phase-7-live-inference-security-contract.md`, authoritative P7-PC1 `docs/phase-7-provider-privacy-decision.md`, the closed Phase 6 contract/SDK review and final remediation evidence, then `docs/phase-7-implementation-report.md` and `docs/phase-7-implementation-evidence.json`. Treat reports as claims, not proof. Inspect actual changed source, migration, fixtures and exact validation evidence.
>
> Attempt to falsify the one-use request-local permit; fresh human/context/privacy/owner authority; exact local disclosure/alias policy; complete SDK body/header allowlist; pinned profile/expiry; zero tools/results/model factual prose; strict original envelope and whole-plan validation; bounded TLS/DNS/redirect/response/deadline behavior; all five durable commercial windows and immutable A1 attribution; atomic admission/audit, no network transaction, idempotent completion, no refunds and unknown-liability recovery. Challenge concurrent last-affordable reservations, duplicate interactions/finalization, rotation/restart, UTC rollover, audit failures, crashes, withdrawal during response/final facts and no retry. Distinguish PHP-observed cancellation from unperformed deployment proxy rehearsal.
>
> Verify minimal CSRF-protected notice/acknowledgement/withdrawal, exact closed UI errors, global SDK quarantine outside the one bridge, default-disabled egress/zero budgets/missing approvals, no real credentials or provider requests, unchanged five Phase 6 tools and search bounds. Re-run full SQLite, UTF-8 PostgreSQL concurrency/integration and final adverse customer-search gates, UI/types/changed lint/format/Pint and exact inherited PHPStan comparison. Do not mislabel inherited warnings/lint/format debt as new regressions or weaken tests. Review every recorded limitation and any requirement not convincingly proven by fake transport tests.
>
> Report blocking/important findings with exact source evidence and the smallest remediation. Do not activate inference, obtain a key, accept legal terms, call a real provider, expand tools or start a subsequent increment. If the bounded implementation satisfies the signed contract, approve only this implementation and explicitly retain every P7-PC1 section 8 human/operational activation gate. Otherwise return the precise bounded correction. Stop after independent review.
