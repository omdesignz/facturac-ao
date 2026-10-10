# Phase 4B implementation report

Date: 2026-10-07. Implementation scope: the corrected bounded Sol Medium handoff in `phase-4b-agt-status-read-contract.md`, with SA1 authoritative. This report requests Astra review; it does not constitute architectural sign-off, production rollout approval or AGT homologation evidence.

## Outcome and boundaries

Implemented the internal, persisted, evidence-qualified AGT projection, protected append-only observations, durable operation claims, deterministic historical reconstruction, bounded explanations and the SA1 receipt evidence gate. Operational readers converge on that projection. Existing V1 document responses and filters continue to use the explicitly named legacy workflow summary. No public qualified projection, route, scope, credential feature or other external capability was added. External access remains globally disabled.

The four layers remain separate: protected immutable evidence → qualified operational projection → receipt/fiscal eligibility checks → minimized human explanation. No operational projection is stored on the frozen fiscal document. Existing issued documents, receipts, fiscal payloads, print/export facts, submission attempts and events are preserved. Corrections retain their existing rules; this change does not require AGT acceptance for corrections.

## Evidence, projection and execution

`AgtEvidence` normalizes protected registration/poll evidence using the existing canonical JSON hashing conventions. Poll evidence must bind the schema, acknowledged request identity, NIF and target document to the immutable aggregate context. Supported matching V/I is distinguished from registration acknowledgement, transport success, deferred 7, processing 8, processing-cancelled 9 and unsupported/contradictory responses. Unknown status is never defaulted to I or V. Remote text and infrastructure messages do not enter the normalized representation.

`AgtObservationReducer` is the single versioned reducer. The persisted closed representation carries reconstruction classification, knowledge, reported state, synchronization/delivery facets, bounded reason, current qualifying observation, last-known historical state/reference, successful synchronization time, projection time and applied sequence. Supported current authority and retained historical knowledge are distinct. Opposing terminal evidence or a differing duplicate yields sticky uncertainty/conflict. Late evidence is retained without allowing an older generation to regain authority or refresh a newer successful observation.

`CurrentAgtState` provides the qualified object and query predicates, the explicitly separate legacy compatibility accessors and the receipt evidence predicate. Future observation timestamps fail safely. The 15-minute display freshness window does not revoke a proven acceptance; stale acceptance remains visibly historical in presentation. A frozen fiscal status or raw workflow Valid alone does not supply evidence.

Submit/Poll jobs now durably claim a captured operation UUID and increasing sequence before I/O, under document-before-submission locks. A two-minute lease permits bounded recovery. Network I/O occurs outside the database transaction. Completion/failure fences against the captured claim rather than mutable worker/browser state. First protected result, journal evidence, projection and required audit commit atomically. Exact duplicate completion is idempotent; differing duplicate protected response bytes are retained separately and classified as conflict. Old callbacks cannot fail or overwrite a newer claim. Existing retry budgets, scheduling eligibility, production flags, allowed operations and frozen outbound requests remain in force; terminal work is not automatically restarted.

The dispatcher uses durable lease expiration for abandoned Sending work. Refresh rechecks immutable binding under locks and cannot shorten an active claim. Due state survives dispatch failure. PostgreSQL uses `clock_timestamp()` for the actual operation time rather than transaction-start time. New journal/lease timestamps use an explicit UTC cast; writes to existing naive attempt/due columns retain the application's timezone convention. Regression coverage verifies those epochs agree.

## Receipt eligibility and compatibility impact

Source selection and final receipt issuance both use `acceptanceEvidenceSatisfied`. It requires the existing fiscal/context checks plus a current authoritative, known, valid, idle projection; consistent Valid workflow; no active operation or scheduled pending work; a bound qualifying journal reference; replay-consistent projection; and a protected matching authoritative QueryStatus V attempt. Missing, incomplete, conflicting, future or unsupported evidence denies authorization. Merely old proven acceptance remains eligible subject to all other checks.

Final issuance retains existing draft/series locks, then locks source documents and their submissions in ascending order, reloads primary evidence and holds those locks through commit. AGT claim/result/rebuild writers use document-before-submission locks and do not subsequently take receipt/series locks. A denial consumes no fiscal number, signature or delivery side effect.

This intentionally withdraws unsupported legacy eligibility under SA1. It does not retroactively rewrite issued receipts or fiscal obligations. The uncertainty inventory reports affected source documents and draft receipts for reconciliation; it never grants an override. The actual production count is unknown because no application or production database was inspected or migrated.

V1 remains exactly the legacy-summary contract: existing keys, enum values, provenance, GET/HEAD and filter behavior remain unchanged. Both OpenAPI specifications describe and deprecate the unqualified status field as unsuitable for AGT evidence or fiscal authorization. A regression uses the same unsupported raw-V source to prove that V1 still returns valid while actual receipt issuance fails without number/signature/outbox effects. No qualified public replacement was introduced.

## Migrations and reconstruction

Two additive migrations were added:

- `2026_10_07_160233_add_agt_observation_projection.php`: submission claim/lease/sequence/revision/version/projection columns, the append-only observation journal, scoped/replay indexes and integrity constraints.
- `2026_10_07_162420_reconstruct_agt_observation_projections.php`: deterministic historical import/reconstruction through the shared reducer.

Journal rows bind submission, workspace, legal entity, operation UUID/sequence and protected attempt references. Environment binding is checked against the immutable submission/document/connection context. PostgreSQL and SQLite guards reject mutation/deletion, cross-aggregate references, unsupported shapes/versions, invalid timestamps and result/failure rows without the corresponding bound claim. Normal and conflicting duplicate uniqueness is explicit. Differing duplicate raw bytes are encrypted and hashed, never copied into normalized JSON or logs. Projection references must belong to the same aggregate/context.

Preflight validates parent bindings before additive changes. Populated reconstruction requires maintenance/writers paused except in guarded tests. Consistent complete protected ordered history may reconstruct authoritative acceptance. Absent/local-only history becomes unknown. Missing hashes/times, decryption failure, ambiguous sequence/order, gaps, overlapping times, future/pre-issuance observations or workflow disagreement produce partial/unknown or conflicting classifications. Transport-only acknowledgement cannot manufacture acceptance. Imports retain original observation times and seed the next claim above historical attempt numbers. No submission is fabricated for a document without one.

Rebuild over unchanged journal inputs is deterministic and does not duplicate imports, alter original evidence, refresh historical observation age, notify recipients or contact AGT. `agt:rebuild-projections --writers-paused` provides maintenance-only forward reconciliation; `agt:projection-audit` is a restricted host-command read-only inventory. Maintenance operator attribution is recorded separately from the system actor. Populated rollback is deliberately refused rather than destroying journal/projection evidence; use forward repair. Empty-schema rollback remains supported.

Disposable tests exercise populated migration, preflight, interrupted reconstruction/resume, repeat rebuild, revision-CAS contention and rollback refusal. No production/application database migration or mixed-writer deployment was performed. Deployment still requires a backup, a representative disposable rehearsal, uncertainty inventory review, stopped old writers and a controlled single-version rollout.

## Reader convergence, explanations and audit

Document register, nested submission presentation, dashboard/document snapshots, global search, AGT submissions and operational analytics grouping use the shared qualified facade/presenter. Qualified status/attention query predicates are separate from legacy V1 filters. Financial cohorts, balances, allocations and accounting semantics remain unchanged. Existing local workflow duration measures are not represented as AGT effective time. Customer document status is explicitly labelled fiscal; historical print/export facts remain frozen.

The internal authorized Inertia representation is closed and bounded. Explanations expose controlled vocabulary/facets rather than upstream prose, raw codes, payloads, credentials, signing material or infrastructure errors. Frontend operational grouping no longer infers AGT authority from frozen fiscal V. Processing cancellation does not send rejection/correction advice. Accepted/rejected notifications are emitted only after committed recognized transitions; reads, migration and rebuild emit none. Recipient authority was not broadened.

Required projection audit is in the same transaction as evidence/projection changes, with trusted system attribution, context, version/revision, operation and separate correlation UUIDs. Ambient human/impersonation/workflow metadata is explicitly cleared; maintenance operator identity is separate. Historical issuer identity is untouched. A poisoned ambient-context regression proves that it cannot become the projection actor. Raw gateway bytes remain only in protected encrypted evidence storage.

One necessary PostgreSQL compatibility fix casts the legacy TEXT notification data to JSONB when applying the workspace predicate. The table was not changed. SQLite/PostgreSQL tests preserve both user and workspace isolation. This unambiguous reader fix supports the approved notification convergence; it introduces no new capability or contract decision.

## Tests and required gates

New feature coverage in `AgtObservationProjectionTest.php` exercises 42 cases: weak legacy V denial, bound acceptance, response-shape matrix, protected journal/context guards, duplicates/conflicts, late generations, atomic rollback/audit failure, durable leases, deterministic reconstruction, staleness/future time, query/object agreement, populated/interrupted migrations, timezone integrity, notification scoping and system/operator attribution.

New `PostgresAgtObservationConcurrencyTest.php` supplies 19 cases using independent worker processes and real locks: poll/poll; retry/poll; identical/conflicting completion; older opposite evidence in both arrival orders; replay/rebuild; receipt versus poll/rebuild in both lock orders; refresh/receipt; rollback; worker termination before/within/after persistence and lease recovery; and populated query-plan/transaction measurements. Existing fiscal and integration concurrency cases remain mandatory.

Existing positive fiscal fixtures that merely assigned Valid now obtain complete bound fake-gateway evidence while preserving their fiscal assertions. Negative tests retain the unsupported old setup. Dashboard/register/search expectations distinguish qualified operational status from fiscal/V1 summary. The V1 raw-V/final receipt denial adversarial case is explicit. No tests were removed or weakened. PostgreSQL migration roundtrip rollback steps changed from six to eight solely to retain the same six earlier migrations after adding these two migrations.

CI's PostgreSQL API gate includes the new observation suite and existing AGT/receipt regressions; the PostgreSQL group includes the new concurrency file. SQLite skips PostgreSQL-only cases intentionally and is not evidence of PostgreSQL locking.

### Final validation

- Complete SQLite suite: **1,174 passed / 7,122 assertions**, 1,224 cases total, **50 PostgreSQL-only skipped**, 132.721 seconds; one inherited warning.
- PostgreSQL 18.6 concurrency/fiscal/integration group: **50 passed / 599 assertions**, no skips, 49.129 seconds; one inherited warning. All 19 new independent-process cases and all 31 inherited cases passed. Populated additive migration, interrupted/resumed reconstruction, rollback refusal, earlier migration roundtrip and integrity gates are covered.
- PostgreSQL session/external API and AGT/receipt suites: **410 passed / 3,087 assertions**, no skips or warnings, 83.825 seconds. This includes the complete preserved Phase 1–4A API/identity/customer/catalogue selection plus observation, schema-two and receipt issuance suites.
- PHPStan: **21 inherited diagnostics, zero new and zero removed** versus the saved Phase 4A diagnostic baseline. Its exit status remains 1; no baseline suppression/deletion was added. Diagnostic comparison uses file/message/identifier; both outputs contain 21 instances (18 distinct diagnostic tuples).
- `npm run types:check`: passed. Changed five TS/Vue files: ESLint and Prettier passed; both changed OpenAPI JSON files: Prettier passed. Pint dirty: passed. All **39 changed PHP files**: syntax passed. `git diff --check`: passed.
- One prior full SQLite run failed an unchanged PDF QR-image assertion. The unchanged complete PDF test file and then the full suite passed on rerun. Cause is unproven; this is a disclosed transient failure, not a weakened assertion. The inherited PHP `use DomainException` warning in `PhaseFiveSubscriptionBillingTest.php` remains; npm warns about existing user config `user` and `unsafe-perm`.
- No live AGT calls, credential recovery, production migration or production/external enablement occurred. Version-specific Boost documentation retrieval was unavailable due DNS; installed package sources were used for relevant APIs. Signed governing reports were preserved.

The final disposable PostgreSQL measurement used a 300-row journal: **1,211 SQL statements / 719.851 ms** inside the measured reconstruction transaction. Replay used a Sort over a sequential scan (planning 0.316 ms, execution 0.093 ms); scoped aggregate lookup used a sequential scan returning one bound row (planning 0.339 ms, execution 0.009 ms). These are actual fixture measurements, not a production throughput or latency guarantee. Full replay and evidence verification scale with historical volume; representative rollout measurement remains necessary.

## Files changed in this bounded task

This inventory compares content against the task-start working-tree snapshot, not HEAD: earlier phases already contained extensive uncommitted work. Those unrelated files were preserved.

- `.github/workflows/tests.yml`
- `app/Actions/IssueFiscalDocument.php`
- `app/AgtOperationalStatus.php`
- `app/Analytics/DocumentSnapshot.php`
- `app/Console/Commands/AuditAgtProjectionEvidence.php`
- `app/Console/Commands/DispatchDueAgtSubmissionPolls.php`
- `app/Console/Commands/RebuildAgtProjections.php`
- `app/Fiscal/DocumentCapabilities.php`
- `app/Fiscal/Documents/AgtEvidence.php`
- `app/Fiscal/Documents/AgtObservationReducer.php`
- `app/Fiscal/Documents/AgtReconstruction.php`
- `app/Fiscal/Documents/AgtStatusPresentation.php`
- `app/Fiscal/Documents/AgtSubmissionExecution.php`
- `app/Fiscal/Documents/CurrentAgtState.php`
- `app/Fiscal/Documents/FiscalDocumentRegister.php`
- `app/Fiscal/Documents/UtcEvidenceTimestamp.php`
- `app/Http/Controllers/AgtSubmissionController.php`
- `app/Http/Controllers/AgtSubmissionRefreshController.php`
- `app/Http/Controllers/AnalyticsController.php`
- `app/Http/Controllers/FiscalDocumentController.php`
- `app/Http/Controllers/GlobalSearchController.php`
- `app/Jobs/PollAgtSubmissionStatus.php`
- `app/Jobs/SubmitAgtDocument.php`
- `app/Models/AgtSubmission.php`
- `app/Models/AgtSubmissionObservation.php`
- `app/Notifications/DocumentAcceptedByAgt.php`
- `app/Notifications/DocumentRejectedByAgt.php`
- `app/Notifications/NotificationFeed.php`
- `database/migrations/2026_10_07_160233_add_agt_observation_projection.php`
- `database/migrations/2026_10_07_162420_reconstruct_agt_observation_projections.php`
- `docs/openapi-external-read-v1.json`
- `docs/openapi-read-v1.json`
- `resources/js/lib/document-status.ts`
- `resources/js/pages/Agt/Submissions/Index.vue`
- `resources/js/pages/Customers/Show.vue`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/Documents/Index.vue`
- `tests/Feature/AgtObservationProjectionTest.php`
- `tests/Feature/AgtSchemaTwoContractTest.php`
- `tests/Feature/DashboardTest.php`
- `tests/Feature/FiscalDocumentRegisterTest.php`
- `tests/Feature/GlobalSearchTest.php`
- `tests/Feature/PhaseFourFiscalIssuanceTest.php`
- `tests/Feature/ReadFirstDocumentApiTest.php`
- `tests/Pest.php`
- `tests/Unit/PostgresAgtObservationConcurrencyTest.php`
- `tests/Unit/PostgresFiscalConcurrencyTest.php`
- `docs/phase-4b-implementation-report.md`

## Remaining review and operational requirements

No new architectural deviation is proposed. SA1's intentional withdrawal of unsupported receipt eligibility and V1's deliberately retained legacy semantics require review as implemented. Existing outbound/production AGT permissions were not enabled or broadened.

Fake-gateway/schema-two fixtures do not establish homologation. Real AGT request identity, target result shape, supported code meanings and timing/retry behavior still require the signed homologation evidence before production certification. Reconciliation must obtain trustworthy evidence, not manually mark a source valid. The measured disposable journal is not a production capacity benchmark; representative historical volume, inventory, backup/restore and maintenance duration remain rollout checks.

**PHASE 4B IMPLEMENTATION — READY FOR ASTRA REVIEW**

The bounded implementation and required local gates are complete. No new signed-contract decision or implementation blocker remains identified. This conclusion requests review rather than authorizing production deployment or the next phase.

Recommended next action: Astra Medium reviews only this bounded Phase 4B implementation against SA1 and the corrected handoff, including evidence/parser binding, reconstruction uncertainty, receipt locking/fail-closed behavior, worker recovery, reader convergence, safe explanation/redaction and unchanged V1 semantics. No Phase 4C or public status design/implementation should start before that review.
