# Phase 4C — qualified external AGT read implementation

Implementation date: 2026-10-07. Executes only §14 of `phase-4c-qualified-agt-read-contract.md`. The full governing Phase 1–4B reads are reused; content hashes verify those contracts, amendments, implementation reports and reviews remain unchanged. The complete Phase 4C report, including its normative OpenAPI and handoff, was read before implementation. The existing working tree was preserved.

**PHASE 4C IMPLEMENTATION — READY FOR ASTRA REVIEW**

Readiness is for bounded trust-boundary review only; it is not phase sign-off or external enablement.

## Implemented boundary

Shared `DocumentCapabilities::readQualifiedAgtStatus(DocumentReadContext, DocumentReadCommand)` authorizes `documents.agt-status.read` itself. The adapter also checks current authority before selector validation. `QualifiedAgtStatusRead` performs one explicitly selected primary statement joining the document to its submission by document/workspace/entity/environment. It preloads the submission relation; neither qualified facade nor presenter lazily fetches history. No row locks, evidence replay/decryption, receipt-gate evaluation or AGT call occurs. The primary clock is captured immediately after materialization, with one UTC second-precision evaluation instant. `as_of` is evaluation time, not a guarantee that all earlier commits were included.

Only this external route was added, named `integrations.v2.documents.agt-status.show`:

```text
GET/HEAD /api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status
```

There is no list, batch, search, filter, session mirror, refresh, mutation or general V2 document API. Public selectors are validated ULIDs, normalized to lower case; selectable environments are exactly production/homologation. All query input is rejected. Correct context with absent, foreign or unresolved targets gives generic 404. Well-formed binding mismatch gives 403 before target SQL. Malformed input gives generic 422 after capability authority; no target existence query occurs. Explicitly bound mutable drafts return not_applicable.

## Exact disclosure

The successful envelope has only `data` and `meta`; meta has only request_id/workspace_public_id/legal_entity_public_id/environment from the trusted context. Data has exactly these twelve required fields, including nullable keys:

```text
document_public_id, knowledge, reported_state, synchronization,
freshness, observed_at, last_successful_sync_at, as_of, effective_at,
provenance, explanation_code, reconciliation_required
```

The approved enums/nullability and ordered mapping are implemented over `CurrentAgtState` and `AgtStatusPresentation`. Missing/malformed/future/unsupported representations become unknown/state_unavailable with no salvaged timestamps. Conflict and partial history remain explicit conflicting_evidence/insufficient_evidence. Transport acknowledgement is workflow_only, never validation. Historical V/I during refresh or failed synchronization is masked; known processing may remain exposed while synchronization is pending. Supported old observations retain their reported state but become stale at exactly 900 seconds. Dates are UTC RFC3339 seconds with +00:00; effective_at is null. Safe historical last_successful_sync_at can remain during supported uncertainty, without exposing its previous result.

The entire representation is checked before required success audit and last-use; JSON encoding is checked too. The resource uses an explicit allowlist, so added model/DTO attributes cannot enter the response. Only the approved fifteen explanation codes can appear. Human messages, recommended actions and retryability were deliberately excluded by the signed twelve-field design despite the broader wording in the user's implementation request.

No eligibility/authorization field, raw response/journal, upstream text, internal ID, hash, signing material, credential, amount, customer/contact information or retained historical reported state is disclosed. `reconciliation_required=false`, HTTP 200 and known valid are never authority for a consequential operation. Existing SA1 fiscal/receipt gates, immutable evidence, projection reducer and worker behavior are unchanged.

## Identity, scopes, audit and protocol

The exact independent scope `documents:agt-status:read` must exist on both integration ceiling and credential. It implies none of the prior scopes; none implies it. Defaults remain documents:read. The existing backend lifecycle accepts the new scope, including homologation, but rotation still cannot widen retiring/parent grants. No new provisioning surface or credential storage was introduced; secret format, one-time disclosure, hashing, attribution snapshots, immutable binding, expiry/revocation and rotation rules remain unchanged.

Each capability revalidates the original sponsor membership, active owner/admin role, verification/MFA, grants and credential/integration state on the primary. Browser cookies/current workspace/ambient impersonation cannot supply machine authority. The internal ExecutionContext adds the named read permission using its existing document-view roles and support attribution. Customers/catalogue remain production-only; the broad old non-document production condition was narrowed to those two permissions specifically.

The existing bearer boundary and exception-report sanitization now recognize explicit V1/V2 families before routing. V2 successful-read dependency/audit/serialization failures return generic 503; required authenticated denial-audit failures return 500. V1's error behavior is preserved. Known-path unsupported methods, including Laravel's otherwise automatic OPTIONS response, return 405 with Allow: GET, HEAD. The established 401/403/404/422/429 and no-store/correlation behavior applies. GET and HEAD execute the same authority/read/representation/quota/audit/last-use work; HEAD has no response bytes, including errors. Conditional headers do not bypass work or produce 304/ETag/Last-Modified.

Success and named-route denial events are documents.agt-status.read / documents.agt-status.read.denied with capability_version=2, named operation and HTTP method. Success includes trusted machine/sponsor/context/correlation, a distinct operation UUID and scoped document public ID. Human direct invocation retains existing human/support attribution and gets a distinct operation UUID. Denial excludes caller target/context selectors. No response/projection/state/payload/secret is logged. Required audit persists before last-used and return; failures do not advance successful use. Last-used remains monotonic under concurrency.

The unchanged database limiter identities and limits are shared across V1/V2, resources, scopes, GET/HEAD and rotation: IP 120/minute, integration 60/minute, workspace 300/minute. These are native-clock fixed windows, with permitted boundary bursts. A new real-request PostgreSQL test measures each actual window and explicitly exercises sixty requests before and sixty after the boundary. No Carbon-only freeze or sliding-window claim is introduced.

## Migration and OpenAPI

One additive migration: `2026_10_07_201201_extend_qualified_agt_read_scope.php`. It changes only the two scope allowlists from three to four exact values. PostgreSQL swaps CHECK constraints transactionally; SQLite copies both tables transactionally, preserving rows, FK/composite keys and credential-grant immutability triggers, and runs foreign_key_check. Unsupported drivers refuse. There are no grant backfills, fiscal columns or new indexes. Down refuses new grants or retained V2 success/denial audit, including generic authenticated V2 denial metadata and revoked credentials. Populated roundtrip, raw SQL rejection and interrupted rollback/resume were tested on SQLite and disposable PostgreSQL. Historical migrations were not edited. Deployment still requires maintenance/writers paused; no application/production migration was run.

Published `openapi-external-qualified-agt-v2.json` from the complete embedded normative specification. Tests cover references, conditional JSON Schema constraints, exact enums/keys, runtime semantic matrix, status/code/header associations, bearer operation scope and bodyless HEAD responses. The test validator covers the schema keywords used here; no general-purpose OpenAPI validator dependency was added.

Both V1 specs receive only legacy-summary/deprecation/bearer-description corrections. Canonical comparisons excluding description/deprecated annotations confirm unchanged schemas, paths, parameters, security and operation behavior. V1 runtime resource/controllers and legacy interpretation remain unchanged. A regression proves the same source can retain V1's legacy valid ten-field summary while V2 reports insufficient_evidence and the existing evidence gate denies it. No silent V1 substitution, redirect or scope implication occurs. Versioned opt-in and release holds remain the signed Phase 4C contract's disposition.

## Tests and validation

Added 126 SQLite-capable cases: 109 in QualifiedAgtStatusApiTest, 16 in QualifiedAgtScopeMigrationTest and one lifecycle rotation regression in the existing IntegrationLifecycleTest. Added 18 independent-process PostgreSQL cases in PostgresQualifiedAgtReadTest. CI's existing PostgreSQL group discovers these cases; the API selection explicitly includes both new feature files and retains the prior complete selection.

Coverage includes all sixteen scope subsets; scope reduction, sponsor/verification/MFA/credential withdrawal; direct capability and internal support attribution; cross-context and scoped-target enumeration; disclosure/time/future safety; no fiscal/evidence/queue/network side effects; GET/HEAD/correlation/conditional behavior; required audit refusals/exceptions/buffering and serialization failure; generic error/spec parity; populated scope integrity/downgrade/interruption; read versus actual poll completion, conflict, refresh claim, reconstruction and draft issuance; authority withdrawals before versus after the signed authorization point; rotation/monotonic last-use and shared real-clock quotas. Unsupported projections that current DB guards prohibit are tested in-memory without disabling those guards.

Final validation results:

- Complete SQLite: **1,319 passed / 8,014 assertions**, **1,389 total**, **70 PostgreSQL-only skips**, **130.645 seconds**, one inherited warning. Includes the unchanged PDF tests.
- Complete PostgreSQL 18.6 fiscal/integration/observation/qualified-read group: **70 passed / 905 assertions**, **87.878 seconds**, no skips, one inherited warning. This final complete gate includes the strengthened missing-cache reconstruction interleavings and the explicit real-clock boundary-burst case. An earlier targeted eight-case snapshot rerun also passed **8 / 152**; The final complete gate ran after both edge-case corrections.
- Focused SQLite API/migration/lifecycle selection before final test-isolation cleanup (then superseded by the complete passing suite): **165 passed / 1,052 assertions**, **15.064 seconds**.
- Final PostgreSQL API/identity/lifecycle/master-data/AGT/receipt selection: **555 passed / 3,978 assertions**, **90.031 seconds**, no failures, skips or warnings.
- PHPStan: **21 inherited diagnostic instances**, **zero new and zero removed** by file/message/identifier multiset comparison against the final Phase 4B review. No ignores or baseline changes. Its nonzero exit remains the inherited baseline, not a clean PHPStan claim.
- `npm run types:check`: passed. No frontend file changed.
- Pint dirty formatting, changed PHP syntax, Prettier for changed specifications/workflow and git diff --check: passed; the final report formatting also passed. Changed-file JS lint is not applicable because no JS/TS/Vue file changed.

The inherited DomainException import warning and npm user/unsafe-perm config warnings remain. The initial final SQLite run hit the inherited native fixed-window quota boundary flake (expected 429, received 200); the full suite was rerun unchanged. No inherited tests were weakened. Intermediate new-test runs failed while fixing fixture evidence/environment binding, imports, outermost-transaction placement, an incorrect rotation exception expectation and a callback capture. A PostgreSQL API rerun also caught fixture grants committed by the new lifecycle case contaminating later migration tests; guaranteed cleanup of that guarded disposable test schema resolved it. The original migration assertions were retained unchanged. These were corrected without disabling DB guards, changing signed behavior, deleting or weakening existing tests. Final self-review also found that the partial-evidence workflow exception was too broad: only recognized never-known pending/sending/received workflow can use it. Empty/failed partial evidence now remains insufficient_evidence, with two exact regression cases. Final self-review also extended populated downgrade refusal to generic authenticated V2 denial history, with an additional regression. The final gates were rerun after these corrections. Initial V2 audit-error tests exposed the 500/503 mapping issue; its bounded V2 remediation is covered by regression tests. Boost hosted search-docs failed DNS resolution; installed Laravel/package source and existing migrations were inspected instead. No dependency was changed.

## Changed files and review boundary

The manifest below is relative to the implementation-start content snapshot, not the much larger inherited Git diff. The pre-existing Phase 1–4B work was retained.

- `app/Fiscal/DocumentCapabilities.php`
- `app/Fiscal/Documents/QualifiedAgtStatusRead.php`
- `app/Fiscal/ExecutionContext.php`
- `app/Fiscal/IntegrationCredentials.php`
- `app/Fiscal/IntegrationReadContext.php`
- `app/Fiscal/ReadOperationAudit.php`
- `app/Http/Controllers/Api/V2/ExternalQualifiedAgtStatusController.php`
- `app/Http/Middleware/ExternalIntegrationBoundary.php`
- `app/Http/Resources/QualifiedAgtStatusResource.php`
- `database/migrations/2026_10_07_201201_extend_qualified_agt_read_scope.php`
- `docs/openapi-external-qualified-agt-v2.json`
- `docs/openapi-external-read-v1.json`
- `docs/openapi-read-v1.json`
- `routes/integrations-v2.php`
- `tests/Feature/QualifiedAgtScopeMigrationTest.php`
- `tests/Feature/QualifiedAgtStatusApiTest.php`
- `tests/Unit/IntegrationLifecycleTest.php`
- `tests/Unit/PostgresQualifiedAgtReadTest.php`
- `.github/workflows/tests.yml`
- `bootstrap/app.php`
- `docs/phase-4c-implementation-report.md`

## Deviations, assumptions and next review

No signed architecture or wire contract was changed. There is no new evidence reducer, eligibility rule, fiscal engine, credential identity scheme or authority shortcut. The only schema change is the expressly authorized scope allowlist. Existing governing reports, original migration files, projection/evidence/receipt/worker runtime, Inertia application and limiter remain intact. Tests reuse existing group fiscal/integration/observation fixtures rather than introducing another business engine.

The qualified read relies on the existing bound aggregate and enforced projection-reference/database guards; it does not certify raw administrator tampering or replay evidence. PostgreSQL testing used only the guarded disposable local PostgreSQL 18.6 instance (`facturac_test_phase1` on loopback port 55439). The disposable server was stopped after validation. Production clock/primary/proxy/quota/audit deployment verification, paused-writer migration rehearsal, rollback/backup/restore and operational release gates remain mandatory. Genuine AGT homologation evidence was not obtained by fake/offline tests; no live AGT request or production operation was performed.

External access remains disabled. Deliberately unimplemented: fiscal/receipt/correction/eligibility APIs, raw evidence endpoints, reconciliation writes, V2 lists/search/batch/general documents, customer/catalogue mutations, analytics APIs, provisioning UI/live customer credential rollout, webhooks, agent tools, BYOW/autonomy, production AGT configuration and external production enablement. No subsequent roadmap phase is authorized or started.

Next action: Astra Medium performs the bounded Phase 4C version/disclosure/identity/scope/audit/quota/migration trust-boundary review of this report, the signed Phase 4C design and implementation/tests. No production enablement or Phase 5 handoff is inferred from passing gates. Stop for that review.

**PHASE 4C IMPLEMENTATION — READY FOR ASTRA REVIEW**

External access remains disabled. Stop for Astra Medium review.
