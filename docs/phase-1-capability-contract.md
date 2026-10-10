# Phase 1 capability contract and security gate

> **Status-authority amendment SA1 — 2026-10-07:** Read this historical report together with [SA1](phase-4b-status-authority-amendment.md). Only its AGT evidence/status, receipt-eligibility and inherited v1 status semantics are superseded as listed in SA1’s downstream register. Unrelated decisions, historical findings and test results are unchanged. The qualified evidence gate is fail-closed; an unqualified legacy status is not fiscal authorization.

Baseline: `f887e69`. Review date: 7 October 2026.

**PHASE 1 — SIGNED OFF** for the internal security and capability contract. This is a bounded architecture determination, not production deployment or fiscal-exposure approval. The final evidence, remaining B/C/D gates and exact Sol Medium handoff are recorded below.

This document records the Phase 1 closure review requested by the product owner. The master prompt's Architecture Contract / Session 1 is called Phase 1 here. No API routes, credentials, agent tools, BYOW engine, outbound webhooks, or production enablement are part of this change.

## Finding disposition

Finding classifications: Defect = confirmed defect requiring remediation; Gap = architectural gap requiring a new contract; Acceptable = existing behavior requiring documentation/tests; False positive = disproved/not applicable. Remaining work is independently classified A/B/C/D in the closure table below; do not confuse those gate categories with finding classifications.

| ID  | Classification                                                   | Evidence and disposition                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                        | Required regression acceptance                                                                                                                                                                                                                                                   |
| --- | ---------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C01 | Gap, internal primitive implemented                              | Private-constructor immutable `ExecutionContext` reloads actor/entity and active membership, verification/MFA, server-defined permissions and relevant environment. `authorize` re-resolves authority on each capability call. `DocumentCapabilities` proves scoped document read and recurring approval without browser workspace dependence. Unsupported integration/delegation adapters have no credential/authority path.                                                                                                                                   | Browser switches do not change context; revoked/downgraded membership is denied after context creation; foreign entity/environment and unresolved documents are not returned; viewers cannot approve; correlation and real/effective actor are audited.                          |
| C02 | Defect, corrected                                                | `RecurringInvoiceController::run` called a global generator. It now calls `executeFor(entity, actor)`, which rechecks active writing membership and scopes BOTH generation and expiration to workspace/entity.                                                                                                                                                                                                                                                                                                                                                  | Other workspace AND sibling legal entity profiles do not generate, advance, close, notify, or issue. Unauthorized direct action call fails. Scheduler retains explicit global behavior.                                                                                          |
| C03 | Defect, corrected for existing recurrence                        | Versioned standing approvals bind profile revision, canonical configuration hash, approver, environment and expiry. Hash includes customer fiscal/delivery fields and entity identity/currency. Explicit auto-issue save grants/renews 30 days; old flags are not backfilled. Material changes invalidate approval. Runtime rechecks current approver authority. Missing/expired/revoked/changed grants leave one draft with refusal audit and no number allocation.                                                                                            | Missing, expired, revoked, modified or downgraded approval prevents issuance; no fiscal number or submission is created. Renewal records a new grant; already completed occurrences never reissue.                                                                               |
| C04 | Defect, corrected for operational readers                        | Added shared `CurrentAgtState` object/query projection. Receipt issuance/preparation, register filters/counts, dashboard counts/focus and explanations now prefer submission state, falling back only for legacy records without submissions. Frozen issue/print evidence remains unchanged.                                                                                                                                                                                                                                                                    | Real issue → fake AGT acceptance → valid poll → receipt preparation/issuance succeeds without editing source fiscal fields. Invalid/rejected/failed/pending never masquerade as validated. All consumers agree. Legacy records without submissions have explicit fallback tests. |
| C05 | Defect, corrected at web boundary                                | Delivery controller authorized `view`; viewers could send. Dedicated `deliver` policy now requires active fiscal writing role and a numbered, non-draft document. Customer-page control uses the same policy. Internal send action is NOT an independently authorized public capability.                                                                                                                                                                                                                                                                        | Viewer can read but cannot send or see send control; each writing role can send; foreign workspace denied; no notification/send-count change on denial. Before agent exposure, enforce capability-level approval/idempotency and disclose issuance auto-send.                    |
| C06 | Gap                                                              | Existing submission/payment/import deduplication does not provide request-result replay. API idempotency protocol defined below, not implemented.                                                                                                                                                                                                                                                                                                                                                                                                               | Concurrent same-key/same-command requests commit one result; changed command conflicts; retry after response loss replays; revoked caller cannot replay; rollback is retryable; completed fiscal tombstone cannot expire into a duplicate issue.                                 |
| C07 | Defect, corrected                                                | Required draft revision is compared under row lock before mutation. The form retains the edited revision until successful save. PostgreSQL proves simultaneous updates and update versus issue contention.                                                                                                                                                                                                                                                                                                                                                      | Exactly one same-revision update commits; loser has explicit stale conflict. Issued/frozen evidence cannot be overwritten. No lost lines, revision or committed notes.                                                                                                           |
| C08 | Gap, internal concurrency gate complete                          | PostgreSQL 18.6 disposable gate now has 12 cases, actual forked connections and guarded destructive setup. CI service is pinned to 18.6. Production version, hosted execution and branch protection remain operational Category C.                                                                                                                                                                                                                                                                                                                              | Receipt allocation/numbering/replay, stale updates, series closure, recurring deduplication, rollback/death, identity constraints and populated backfill are demonstrated. New high-risk capabilities must extend this matrix.                                                   |
| C09 | Defect, corrected                                                | Durable environment on series, documents, submissions and connection checks; environment-scoped number/code/request uniqueness and composite ownership/environment foreign keys. Immutable bound connection identity. Sync targets entity + environment + code. Deterministic linked-evidence backfill aborts conflicts and quarantines unknown documents.                                                                                                                                                                                                      | Same code/number across environments is isolated; duplicates within one identity are rejected; mismatched links fail; source corrections/receipts cannot cross environments; unknown records cannot enter scoped reads/outbound AGT; frozen bytes survive populated migration.   |
| C10 | Defect, customer gap corrected; broader audit contract specified | Customer create/update/deactivation now emit dirty-change audit, with personal values redacted while field names remain visible. Web customer mutation and audit share a transaction. Subject/explicit tenant takes precedence over ambient workspace; real/effective actor and impersonation are retained. Recurring approval/revoke/refusal/issue events carry authority and automation identity. New recurring fiscal logs propagate correlation and occurrence reference. Integration/agent attribution is specified but those principals do not exist yet. | Customer changes identify subject tenant, real/effective actor and changed fields; personal values are redacted. Audit failure rolls back the customer mutation. Future adapters must implement the complete attribution envelope.                                               |
| C11 | Defect, constrained for current reporting                        | Company receivables, dashboard, aging and customer summaries now limit native-currency amounts to the legal-entity currency; foreign amounts are never summed into that label. Pages disclose the limitation. Analytics explicitly labels current balances/receipts for selected invoice cohorts, not historical as-of balances or bank movement. No FX conversion or historical ledger API is claimed.                                                                                                                                                         | Mixed AOA/USD never sums as AOA. Current cohort balances are labelled as current, not historical. Future historical/payment-date reports need separate verified queries.                                                                                                         |
| C12 | Defect, corrected for existing recurrence                        | Locked profile reload precedes generation and cursor updates. Unique (profile, scheduled date) occurrence and unique document linkage commit in the same transaction as the draft/issue. Completed calls return the existing document; revoked/changed approval cannot regenerate an occurrence. Fiscal queue dispatch, configured delivery and recurrence notification wait for outer commit. PostgreSQL tests prove direct-operation deduplication and one scheduler cursor advance.                                                                          | Three workers produce one occurrence/document and one cursor advance. Rollback/death leaves no committed partial operation; retries preserve completed identity. External effects wait for outer commit.                                                                         |
| C13 | Acceptable, verified; bounded future work                        | Transactional issuance, document/series/source locks, recalculation, immutable payloads, one submission per document, frozen prints and after-commit dispatch remain reusable. Actual PostgreSQL contention now demonstrates critical existing transitions. This does not assert exactly-once remote AGT processing.                                                                                                                                                                                                                                            | Preserve all existing tests and the 12-case PostgreSQL gate. External acknowledgement/replay evidence remains B; new command ledger and crash-point tests attach to future consequential capabilities, D.                                                                        |
| C14 | Gap, governing boundary implemented                              | Representative internal read and approval capabilities resolve/revalidate explicit context before scoped access or action delegation. Existing controllers need not all be rewritten now. Domain actions remain internals, never a public reflection surface.                                                                                                                                                                                                                                                                                                   | Incrementally wrap additional capabilities with typed validated commands, current permission checks, safe outputs/audit and scope tests before routing them. No generic SQL or arbitrary-action tool.                                                                            |
| C15 | Gap, intentional phase boundary                                  | No v1 API, external credentials, OpenAPI, outbound webhook product or agent engine. Inbound WiPay callback and session JSON search are not a public capability API.                                                                                                                                                                                                                                                                                                                                                                                             | Do not add these in Phase 1. Future read-only slice must prove deny-by-default scopes and tenant isolation first.                                                                                                                                                                |
| C16 | Acceptable / False positive                                      | SAF-T generation and checksum-verified local XSD validation already exist; README “planned” wording is stale. Any claim that this capability is absent is false. Existing payment models primarily represent SaaS subscriptions, not arbitrary customer payment registration.                                                                                                                                                                                                                                                                                   | Preserve SAF-T tests. Keep subscription payments separate from customer receipts/settlements. Do not build a generic customer payment endpoint over subscription fulfillment.                                                                                                    |
| C17 | Defect, corrected; future adapters bounded                       | Known web consequential routes reject impersonation. Shared capability authorization also rejects consequential execution with impersonation, including a context constructed before impersonation begins. Read attribution preserves real/effective actors.                                                                                                                                                                                                                                                                                                    | Middleware regressions and capability refusal tests pass. Future adapter delegation must be validated explicitly and may not manufacture a human context.                                                                                                                        |

## Execution context

UI controllers, future REST adapters and future tools invoke the same application capabilities. UI need not make HTTP requests to the public API. Domain calculators, number allocation, signing, fiscal snapshots and outbox remain authoritative.

The immutable execution context is resolved by a trusted adapter and contains:

- principal kind and stable principal ID; human actor ID where applicable;
- integration ID, agent identity and workflow execution ID only when those systems actually exist;
- explicit workspace ID, legal entity ID, environment and optional establishment;
- effective role and capability grants, request/correlation ID and causation ID;
- real/effective actor and delegation or impersonation reference;
- approval reference and expected resource revision for consequential work.

Never accept a caller-provided role, permissions list, approval result or tenant object as authority. Resolve public IDs against workspace/entity ownership and active membership. Membership currently grants access across the workspace's legal entities; entity context narrows each operation and credential grant. Per-entity user membership is a future product decision, not silently assumed to exist.

Effective authority is the intersection of active principal, active membership, role policy, explicit credential/delegation scope, entity/environment grant and action approval. No wildcard or owner role bypasses environment, immutable-state, or fiscal restrictions. No credential or agent may create a context by changing `User.current_workspace_id`.

Human UI adapters may translate their selected workspace into an explicit context. Non-browser adapters require explicit context and never fall back to another workspace or oldest entity. Jobs re-resolve authorization when executed; serialized permissions are not proof. Internal outbox delivery of an already committed fiscal issue uses narrowly scoped system authority so revoking a human does not erase a legal delivery obligation.

The global recurring scheduler remains an internal system entry point. Tenant callers use only `executeFor`. Its tenant guard and the shared immutable execution context have different responsibilities: enumeration versus per-capability authority. Neither derives non-browser authority from selected workspace.

## Recurrence and fiscal authority

Draft preparation, standing-instruction management and fiscal issuance are separate permissions. Manual generation must be scoped to an explicit entity. A scheduler enumerates tenants under system authority but does not inherit an unrestricted human identity.

Existing auto-issue profiles now require explicit renewal; the additive migration intentionally creates no historical approvals. A durable standing authorization must bind the approver, profile revision, workspace/entity/environment, document type, series constraints, amount ceiling, recurrence and validity window. Record grant/revoke/expire events. Validate current approver authority at execution; invalidate authorization when consequential profile content changes. An agent may prepare a profile but may not grant this authority.

Saving an active profile with the explicitly labelled 30-day auto-issue option creates a new approval and revokes the previous one. MFA, verification and current writing membership are required. Approval covers the exact current template and its pending scheduled dates within starts/ends; it is not a historical reconstruction of earlier template versions. A completed draft is never automatically reissued when an approval is renewed; it remains for manual review. Future integrations/agents remain excluded.

The implementation now uses a transactionally locked profile plus unique (profile ID, scheduled date) occurrence identity. Document creation, occurrence linkage and cursor advance commit together. The scheduler/manual action must recheck due date, active status, ownership and authority after locking. No irreversible email/network effect may occur before the outermost transaction commits.

## AGT state projection

Frozen fiscal facts and operational AGT state are different records. `FiscalDocument` retains issue evidence; submissions and append-only attempts/events retain transport and acknowledgement evidence.

Define one shared projection with: issue state, transport state, AGT validation state, needs-attention flag, safe reason, last observation time and evidence reference. For a document with a submission, its persisted submission status is authoritative for current AGT state. Legacy document fields are a fallback ONLY when no submission exists, with provenance marked legacy. Never prefer a legacy `Valid` field over an existing failed/pending/invalid submission.

Map pending/sending/retrying/received/processing to unvalidated; valid to validated; invalid to invalid; rejected/cancelled/failed to needs-attention without claiming the fiscal document was legally cancelled. Contingency remains distinct and must not imply validation. Drafts have no AGT validation status.

Receipt eligibility, register, dashboard, analytics, attention explanations and future read tools must use this projection. Query-side filters and object-side projection must share an exhaustive mapping tested against every enum case. Historical signed/printed evidence must not change merely because current transport state changes. Do not add a shortcut that mutates frozen document fields.

## Idempotency protocol

No implementation or credential design is authorized by this contract alone.

1. Require an opaque key (1–128 printable ASCII characters) for consequential POST commands, including future issue/payment/delivery commands. Bind uniqueness to environment, workspace, entity, stable principal/integration identity, capability version and key. Token rotation retains integration identity; unrelated integrations never share replay access.
2. After authentication, authorization and validation, construct a versioned typed canonical command. Reject unknown fields and duplicate JSON keys. Resolve identifiers; normalize validated dates, enum values, decimal strings and defaults once. Include expected revision, approval ID, target, recipient and all consequential inputs. Sort object keys recursively, preserve array order, distinguish null from absent unless schema normalization makes them equivalent, serialize UTF-8 deterministically and forbid floats. Hash canonical bytes with SHA-256; store canonicalizer version. Existing fiscal CanonicalJson is a candidate, not an assumed RFC canonicalizer or a reason to change signed AGT bytes.
3. Acquire the idempotency row through a database unique constraint and transactional locking, not a check-then-insert. Recheck current authority before returning any replay. Same key/different hash returns conflict. Same key/same hash in flight waits within a bounded timeout, then returns an explicit in-progress conflict/retry hint; it must never start a second command.
4. Commit domain result, immutable response snapshot/reference, audit and durable outbox atomically. Same completed command replays original application status/body/resource IDs; the new transport request ID is separate from the original operation ID. Never regenerate numbers, signatures or business timestamps on replay.
5. A rolled-back transaction leaves no successful replay claim. Unknown network delivery after commit is resolved from durable operation/outbox state; never reset an uncertain fiscal command and retry as new work. Malformed, unauthenticated and unauthorized requests do not create business idempotency records.
6. Retain successful irreversible-operation identity/hash/result reference for the fiscal retention period. Response payload retention may be shorter only if a tombstone still prevents re-execution. Exact legal retention duration remains a compliance decision. Scope, revoke and redact replay access like normal resource access.

## Optimistic draft updates

Every update requires the revision the caller actually edited; a loaded-at-save revision is not a precondition. Re-read and lock the draft, authorize the resolved entity, compare expected revision, validate the command and replace lines/settlements within the same transaction. Increment once on success. Missing revision is rejected; stale revision returns a stable conflict with current revision and reload instruction. Do not silently merge fiscal fields. Create starts at revision one.

The web form now carries its displayed revision and refreshes it only after a successful save or explicit reload. The current Inertia adapter returns a `revision` validation error (redirect for HTML, 422 for JSON), including the current revision and reload instruction; a future API adapter must map stale preconditions to a stable 409 conflict. Update all existing direct action callers and tests in the same bounded remediation. No API endpoint is required to fix this defect.

## Database and uniqueness contract

PostgreSQL is the intended production acceptance target. Do not overwrite developer `.env`, convert existing data, or run migrations against an unidentified database. Local MySQL observations do not establish the deployed engine. SQLite remains for fast deterministic tests, never concurrency certification.

Target identities are:

- fiscal document number: (legal entity, AGT environment, document number);
- series authorization: (legal entity, AGT environment, exact AGT series code), with establishment/type/year attributes validated against that authorization;
- allocated sequence: (series identity, sequence);
- submission: one registration operation per issued document, with UUID uniqueness and immutable request bytes;
- external AGT request ID: scoped by entity/environment, pending confirmation of AGT uniqueness guarantees;
- relationships: workspace/entity and relevant environment consistency through composite constraints plus application checks.

Do not normalize/rewrite AGT series codes or reassign existing numbers. Before a uniqueness migration, inventory collisions and prove each existing document/series environment from its connection and evidence. Contradictory ownership/environment links block migration. Missing evidence produces `unresolved` quarantine, never a guessed production/homologation assignment. Draft environment must be explicit before exposure, and issuance must match document, series and connection environment. Homologation data must never appear in production business summaries.

The environment-dimension migration is implemented and tested on populated SQLite and PostgreSQL fixtures. Linked connection/series/submission evidence must agree; unlinked historical documents remain `unresolved`. Durable known environment metadata is respected by subsequent audits. The separate entity-number migration needs no backfill: the former workspace constraint was stricter, so existing data already satisfies entity uniqueness. It creates the new unique index before dropping the old one. Rollback checks for newly permitted cross-entity duplicates and refuses rather than modifying fiscal data.

### Mandatory PostgreSQL gate

Use a dedicated disposable database, separate credentials and explicit `pgsql`/test-database guards. Never run RefreshDatabase or destructive setup against local application/production databases. The CI job must fail if PostgreSQL is unavailable; skipping is not a passing gate. The tested policy is PostgreSQL 18.6; before production deployment, record the actual server major/minor and run this same gate against that version. A different production major requires a passing supported-version CI matrix before rollout. Do not infer production parity from local MySQL configuration or a passing SQLite tier.

Run actual independent connections/processes synchronized at contention points; parallel test-file execution alone is insufficient. No enclosing per-test transaction may hide fixtures from worker processes. Cover:

- simultaneous issue of the same draft: one committed issue/number/submission;
- different drafts in one series: unique contiguous committed allocations, no lost writes;
- different series/tenants: no unnecessary shared global/workspace lock;
- key/signing/calculation failure and deadlock retry: complete rollback, no consumed number;
- duplicate idempotency commands including response loss: one result and outbox;
- concurrent receipts against the same source balance and concurrent recurring occurrences;
- stale updates against issuance; revoked authority and cross-entity/environment references;
- migration backfills, composite foreign keys, uniqueness and mixed-currency aggregates;
- process death after commit/before queue dispatch and before/after external acknowledgement: durable recovery with identical bytes.

PostgreSQL 18.6 was run in a dedicated cluster at `/private/tmp/facturac-phase1-pg`, port 55439, database `facturac_test_phase1`. Gate setup asserts testing environment, pgsql driver, a `facturac_test_` database prefix and pcntl support before destructive setup. Every case applies all migrations, safely rolls back the three new migrations on empty evidence, and reapplies them. Forked workers purge inherited connections and synchronize at a start barrier; fixture transactions do not hide committed setup.

Twelve cases now cover:

1. Different drafts in one series: unique sequences 1/2/3, committed next number and untouched foreign tenant.
2. Same draft replay: one submission/number; rollback then retry consumes the next number once.
3. Same recurring occurrence: one result/document/occurrence across three workers.
4. Row-lock exclusion: critical-section intervals do not overlap.
5. SIGKILL before outer commit: no durable partial number/evidence; retry succeeds once.
6. Concurrent schedulers: one occurrence and one cursor advancement.
7. Full receipts against one source through distinct series: only one settlement commits, loser is a fiscal conflict, no overpayment or duplicate replay allocation.
8. Partial receipts in one series: both commit distinct numbers and sum to the source gross and tax exactly.
9. Update versus issue: either updated revision wins and issue conflicts, or issue wins and update cannot mutate frozen evidence.
10. Same-revision updates: one committed revision/notes/line set, one stale conflict.
11. Series closure versus issue: serialization yields either a committed number before closure or refusal without number consumption; terminal series stays closed.
12. Populated legacy migration: contradictory evidence fails before DDL, agreeing evidence backfills without changing any prior fiscal byte, unknown remains quarantined, environment FK rejects reassignment and populated rollback refuses.

The closure race models the same locked series-state update used by synchronization; it does not call AGT. Both valid serialization orders are accepted and their business invariants asserted. HTTP request replay is still an unimplemented, governed future protocol; duplicate existing draft/occurrence execution is what this gate proves. Deliberately injected deadlocks and death after an actual external acknowledgement are not claimed. Those are acceptance criteria for the later affected high-risk/outbound capabilities, not blockers to the read-first foundation.

The installed Homebrew runtime was incomplete. Two missing symlinks were restored, `/opt/homebrew/share/postgresql@18` and `/opt/homebrew/lib/postgresql@18`, pointing to the already-installed 18.6 resources. No package was installed or upgraded. No application database or `.env` was modified. The temporary server is stopped after validation.

## Audit contract

Record a durable operation ID, request/correlation/causation IDs, capability and contract version, workspace/entity/environment, actor kind/ID, human user if any, integration/agent/workflow identity where applicable, real/effective actor, delegation/impersonation ID, approval ID/revision, resource public ID and before/after revision, command hash/idempotency reference, outcome/error code and UTC timestamp.

Separate requested, denied, approved/rejected, committed, replayed and external-delivery outcomes. Replays may create an access/audit event but never a second domain event. Successful mutation audit commits with its mutation. Denials must be recorded outside rolled-back domain transactions. System actors must be explicit; a null human actor is not an unexplained attribution gap.

Never log credentials, tokens, private keys, unrestricted prompts, full fiscal/customer payloads or raw payment callbacks. Store minimal safe differences and evidence hashes. Audit read access to sensitive exports and integration data. Use tenant-aware retention, protected access and append-only evidence; Eloquent event guards alone do not enforce database immutability.

The ordinary Customer CRUD audit gap is closed. Delegated/integration/agent identities remain contract requirements for future, currently unsupported adapters; they must not be impersonated as unexplained human users. Request Context is diagnostic propagation, not the source of authorization, and must not overwrite an explicitly validated tenant with unrelated ambient state.

## Analytics definitions

All results identify entity, environment, currency, date basis, time zone, as-of instant and metric version. No combined total of different native currencies. Future capability reads must return separate currency buckets; current browser summaries explicitly restrict themselves to the entity currency; AOA conversion is a separately labelled metric using frozen document exchange rates with deterministic integer rounding, never current FX rates.

- Issued: immutable, numbered fiscal documents successfully committed locally. This does not assert AGT validation or legal collectability.
- AGT-validated: issued documents whose authoritative current projection is valid. Report pending/attention separately.
- Invoiced: gross and net amounts for the explicitly listed billable document types, with credits shown separately and as a derived net figure; do not count receipts as new revenue.
- Collected: fiscal invoice-receipts and receipt settlements recorded through the fiscal engine, counted once by payment/receipt date. Label this as recorded collections, not bank-confirmed cash. SaaS subscription receipts are excluded.
- Outstanding: document-currency billable amount less applicable credits and settlements, with unapplied credits/refunds separately visible; the receipt/credit allocation rules must reconcile with the ledger, not be independently invented by analytics.
- Overdue: positive outstanding balance whose due calendar date is strictly before today's date in Africa/Luanda. Due today is not overdue. Null due date is not silently overdue.

Use document date for invoicing periods, payment date for collections and an explicit as-of instant for balances. Reporting “as of” a past instant requires filtering later receipts/credits; today's live balance is not a historical balance. Date-only values remain dates; timestamp periods use half-open intervals converted from Luanda boundaries. Mark unknown legacy environment/status rather than silently folding it into production/validated data.

## Migration safety and rollout

- `2026_10_07_104140_add_recurring_authority_and_occurrence_integrity`: additive profile revision and new approval/occurrence tables. No fiscal rewrite, deletion or implicit approval backfill. Existing auto-issue profiles leave drafts until renewed. Foreign keys protect approval/document evidence; unique occurrence identity prevents retries from becoming new fiscal operations. Rollback is allowed only before approval/occurrence evidence exists, otherwise a forward migration is required.
- `2026_10_07_105649_scope_fiscal_document_numbers_to_legal_entity`: creates entity/document-number uniqueness then removes the overly broad workspace constraint. Existing values already satisfy the new constraint. Rollback refuses once the newly allowed cross-entity duplicates exist. PostgreSQL rollback testing caught and fixed a strict GROUP BY issue that SQLite accepted.
- Applied only to disposable SQLite/PostgreSQL tests. Deployment requires reviewing the legacy reapproval effect, database version, lock/index-build time and backup/rollback procedure. No production migration was run.

## Validation record — final closure pass

SQLite full suite: **824 passed / 4,351 assertions**, 46.128 seconds; **12 PostgreSQL-only cases skipped in SQLite** and executed separately. This exceeds the 811 / 4,301 baseline with all preceding tests retained.

PostgreSQL 18.6: **12 passed / 186 assertions**, including independent-process concurrency and populated legacy migration. This expands the preceding 6 / 88 gate. No production/application database was migrated. The disposable server is stopped after validation.

`npm run types:check`, targeted ESLint and Prettier for every changed Vue file, workflow/document formatting, `vendor/bin/pint --dirty --format agent`, and `git diff --check` pass. PHPStan: **21 pre-existing diagnostics, zero new**, compared by file/message against the preceding baseline. Workflow YAML was statically parsed by Prettier; this does not prove hosted execution.

All 811 preceding SQLite cases and their assertions are preserved. The new environment/capability regressions cover legacy backfill, contradictory evidence, quarantine, identity immutability, environment uniqueness, current permission revalidation, browser independence, real/effective actor and correlation, scoped approval, current AGT projection, cross-environment receipts/corrections and outbound rejection of inconsistent evidence.

PHPStan retains the 21 pre-existing diagnostics in ConvertQuoteToInvoice (1), IssueTransportDocument (2), SaveTransportDocumentDraft (1), DocumentSnapshot history return shape (1), V1_2 FiscalDocumentPayloadBuilder (1), SaftExportSummary (1), SaftExporter (9), FiscalDocumentController (1), TransportDocumentController (1), StoreTransportDocumentRequest (3). No suppressions or analysis-setting changes were introduced.

Previously established repository-wide lint debt remains outside this change: ESLint 9,719 errors in bundled skill assets/scripts and `resources/promo/render.mjs`; Prettier four unchanged files (`resources/js/pages/Establishments/Index.vue` and three promo files). No unrelated mass formatting was performed. `composer ci:check` therefore is not claimed globally green. Changed-file checks and all test tiers were run separately. The compact Pest runner continues to report one warning with no details; it is disclosed, not counted as an assertion failure or AGT evidence.

## Changed-file manifest

All paths below are repository-relative and include preceding Phase 1 changes still present in this working tree.

- `.github/workflows/tests.yml`
- `app/Actions/ConvertRecurringProfile.php`
- `app/Actions/GenerateRecurringInvoices.php`
- `app/Actions/IssueFiscalDocument.php`
- `app/Actions/SaveFiscalDocumentDraft.php`
- `app/Actions/SyncAgtSeries.php`
- `app/Analytics/DashboardQuery.php`
- `app/Analytics/DocumentSnapshot.php`
- `app/Analytics/ReceivablesQuery.php`
- `app/Fiscal/Documents/FiscalDocumentRegister.php`
- `app/Http/Controllers/CustomerController.php`
- `app/Http/Controllers/CustomerProfileController.php`
- `app/Http/Controllers/FiscalDocumentController.php`
- `app/Http/Controllers/FiscalDocumentDeliveryController.php`
- `app/Http/Controllers/RecurringInvoiceController.php`
- `app/Http/Requests/UpdateFiscalDocumentRequest.php`
- `app/Jobs/PollAgtSubmissionStatus.php`
- `app/Jobs/SubmitAgtDocument.php`
- `app/Models/AgtConnection.php`
- `app/Models/AgtConnectionCheck.php`
- `app/Models/AgtSubmission.php`
- `app/Models/Customer.php`
- `app/Models/FiscalDocument.php`
- `app/Models/FiscalSeries.php`
- `app/Models/RecurringInvoice.php`
- `app/Policies/FiscalDocumentPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `config/impersonation.php`
- `database/factories/FiscalDocumentFactory.php`
- `resources/js/pages/Analytics/Index.vue`
- `resources/js/pages/Customers/Show.vue`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/Debts/Index.vue`
- `resources/js/pages/Invoices/Create.vue`
- `resources/js/pages/Recurring/Index.vue`
- `tests/Feature/AgtSchemaTwoContractTest.php`
- `tests/Feature/ImpersonationTest.php`
- `tests/Feature/PhaseFourFiscalIssuanceTest.php`
- `tests/Feature/PhaseThreeFiscalDocumentsTest.php`
- `tests/Feature/WithholdingAndCurrencyTest.php`
- `app/Actions/ApproveRecurringInvoice.php`
- `app/Console/Commands/AuditFiscalEnvironment.php`
- `app/Fiscal/DocumentCapabilities.php`
- `app/Fiscal/Documents/CurrentAgtState.php`
- `app/Fiscal/ExecutionContext.php`
- `app/Fiscal/FiscalEnvironmentBackfill.php`
- `app/Models/Concerns/HasFiscalEnvironment.php`
- `database/migrations/2026_10_07_104140_add_recurring_authority_and_occurrence_integrity.php`
- `database/migrations/2026_10_07_105649_scope_fiscal_document_numbers_to_legal_entity.php`
- `database/migrations/2026_10_07_111342_bind_fiscal_environment_identity.php`
- `docs/phase-1-capability-contract.md`
- `tests/Feature/FiscalEnvironmentBoundaryTest.php`
- `tests/Feature/PhaseOneSecurityGateTest.php`
- `tests/Feature/RecurringAuthorityTest.php`
- `tests/Unit/PostgresFiscalConcurrencyTest.php`

## Exact supported execution contract

Current supported principals are authenticated humans and scheduled recurrence under persisted human standing approval. Integration, agent and delegated machine principals are explicitly unsupported: there is no credential factory, arbitrary principal-kind input, or implicit conversion into the profile creator. Future adapters must validate stable principal identity and intersect credential/entity/environment grants with current membership and operation policy; until implemented they fail closed by having no entry path.

The private-constructor readonly context binds actor, workspace, legal entity, environment, server-defined permissions, request/correlation, approval and automation IDs, and real/effective actor with impersonation session. `DocumentCapabilities::read` and `approveRecurring` demonstrate independent scope resolution and current permission revalidation. Read-only contexts cannot approve. MFA and verified email are required for these new primitives, including read contexts; this does not change existing browser view policies. Read access does not require outbound AGT production enablement. Consequential permissions do. Unknown environments cannot construct an enum-backed context; `unresolved` records never match scoped reads. Fiscal issue-time series selection binds an unissued legacy draft, but does not relabel frozen legacy evidence.

`read` returns whitelisted fields and separately labels frozen issue status, authoritative current AGT status and its provenance. It audits real/effective actor, subject tenant/environment and original correlation. It returns no JWS, keys or raw submission payload. Recurring approval uses scoped locked profile resolution and the existing transactional grant action. The wrapper is internal and has no route. Current UI actions are not automatically wrapped/exported by adding this class.

Approval and automation references are attribution, never permission grants. Recurrence validates the persisted approval/configuration/expiry at execution and uses `(profile, scheduled date)` as idempotency identity. General HTTP idempotency remains the defined protocol above and must be implemented/tested before any consequential public command. Context is not a bearer token: every capability must call `authorize`, then scope target queries. Future delayed commands must reconstruct context and approvals; serialized permission arrays are not authority.

Support impersonation remains read-only at the new boundary; consequential calls reject both captured and currently active impersonation. Future delegation needs its own durable approved scope and cannot reuse support impersonation. Committed AGT outbox work has bounded system authority and checks document/submission/connection environment consistency before network access. Audit propagation alone is not authorization.

## Environment migration production procedure

1. Confirm PostgreSQL version and backup/PITR restore capability; rehearse restoration in an isolated database. Save schema, row counts and hashes of fiscal payload/signature/print evidence. Estimate exclusive-index/DDL lock duration on a representative snapshot. This is a maintenance migration, not a promised online migration.
2. Stop web mutations, schedulers and queue workers; drain or pause AGT workers before replacing code. Deploy the release in maintenance mode. Run `php artisan fiscal:environment-audit` against the explicitly identified target. This command is read-only and works before/after the environment migration. Archive its resolved count/unresolved document IDs and any conflicts in restricted operational evidence.
3. Deterministic rule: same-tenant connection links, document series connection and every document submission connection must agree. Known durable environment after migration is additional evidence. Never infer from current browser state, configuration defaults, timestamps, NIF, series spelling or payload guesses. A missing or foreign required connection is an error. Conflicting evidence aborts before schema writes. Unlinked documents become `unresolved` and remain unavailable to environment-scoped reads or dependent receipt/correction issuance.
4. Resolve contradictory links only through separately reviewed evidence-backed repair; never silently rewrite issued payloads or signatures. Unknown historical rows need an approved environment-evidence reconciliation before exposure; they do not prevent safely migrating other records. Do not fabricate production authority or renew old recurring grants implicitly.
5. With writers still stopped, take the pre-migration recovery checkpoint and run the normal migrations. PostgreSQL wraps the environment migration transactionally. New indexes are created before old uniqueness is removed. Composite keys enforce workspace/entity/environment agreement. SQLite uses deferred checks only for table rebuilds, explicitly verifies every FK, then restores immediate checks. MySQL is not the production acceptance target and was not certified by this pass.
6. Run the read-only audit again, compare counts and fiscal hashes, confirm unresolved rows are quarantined, verify index/FK definitions and inspect failed jobs before resuming workers. Connection tenant/environment are immutable; rotate credentials on the existing identity or create a separate environment connection, never repurpose one carrying evidence. Resume under the pre-existing AGT enablement settings.
7. Recovery: pre-commit PostgreSQL failure rolls back atomically. Empty-evidence migration rollback is tested. Once any fiscal environment/approval/occurrence evidence exists, destructive down migration intentionally refuses. Use a reviewed forward repair; restoring the checkpoint is permitted only with writers paused and assurance that no later fiscal issue/AGT delivery would be lost. Recovery must retain external obligations and append-only history.

No production data was inspected or migrated here. Actual legacy inventory, reconciliation evidence, backup rehearsal and maintenance rollout are Category C operational gates. Homologation proof, where needed to resolve an identity, is Category B. The deterministic isolation mechanism itself is complete.

## Remaining classifications and Phase 1 determination

**PHASE 1 — SIGNED OFF** for the internal security/capability contract. There are no remaining Category A blockers to the bounded read-first foundation. Sign-off follows the demonstrated scope/authority boundaries, safe environment migration and critical PostgreSQL invariants, not merely a passing SQLite suite.

Remaining categories: **A — internal blocker before sign-off; B — external AGT/homologation dependency blocking the affected fiscal capability; C — deployment/operational gate before production; D — deferred bounded implementation governed by this contract.**

Every original finding is accounted for below. “Closed” means the identified existing defect/contract obligation is resolved at this phase; it does not authorize every future adapter.

| Finding                         | Closure / remaining category                                  | Concrete remaining work and acceptance                                                                                                                                                                                                                                                      |
| ------------------------------- | ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| C01 context                     | Closed; D                                                     | Incrementally adopt the demonstrated context primitive; new adapters must pass browser-independence, revoked-authority and tenant/entity/environment tests. Unsupported machine principals stay disabled.                                                                                   |
| C02 recurring tenant leak       | Closed                                                        | Scoped manual run and global internal scheduler regressions retained.                                                                                                                                                                                                                       |
| C03 recurring authority         | Closed for existing human workflow; B/D for expanded autonomy | Current persisted approval/revalidation is enforced. No integration/agent/autonomous expansion without separate high-risk review and affected homologation evidence.                                                                                                                        |
| C04 AGT projection              | Closed; B                                                     | Current projection is authoritative; legal meaning of new AGT statuses/cancellation remains evidence-dependent.                                                                                                                                                                             |
| C05 external delivery           | Closed existing web defect; D                                 | Shared delivery capability, destination approval and command replay must precede any external adapter. Excluded from Phase 2.                                                                                                                                                               |
| C06 API idempotency             | D                                                             | Implement the canonical versioned command ledger and concurrent replay tests before consequential API commands. Read-only GET does not require it. Existing occurrence/draft identity is already tested.                                                                                    |
| C07 stale drafts                | Closed                                                        | SQLite and PostgreSQL regressions retained; future HTTP conflict mapping remains D.                                                                                                                                                                                                         |
| C08 database                    | Closed internal gate; C                                       | Verify actual production server version, hosted CI run and required-check configuration. PostgreSQL 18.6 is tested locally and configured in CI; hosted execution is not claimed.                                                                                                           |
| C09 uniqueness/environment      | Closed implementation; C/B                                    | Run actual data inventory/backfill/backup rehearsal; reconcile quarantined historical evidence before exposing it. AGT's remote uniqueness/replay guarantees remain B.                                                                                                                      |
| C10 audit                       | Closed existing customer gap; D/C                             | Machine/delegation envelopes and denied/replay outcomes must be implemented with those adapters; production retention/access/append-only infrastructure remains C. No invented machine actor is currently accepted.                                                                         |
| C11 analytics                   | Closed currency/date ambiguity; D                             | Current summaries remain native-currency/current-cohort browser queries. Environment-partitioned and historical/as-of/payment-date analytics capabilities require scoped verified queries before exposure. Do not expose current browser aggregates directly as a production analytics API. |
| C12 recurrence concurrency      | Closed                                                        | Atomic occurrence/cursor, after-commit effects, rollback and concurrent replay regressions retained.                                                                                                                                                                                        |
| C13 existing fiscal engine      | Acceptable, PostgreSQL demonstrated; B/D                      | Remote acknowledgement behavior is B; additional crash points/deadlock injection attach to future consequential API/outbox work, D.                                                                                                                                                         |
| C14 reusable actions/boundary   | Closed contract; D                                            | Add wrappers incrementally with scoped tests; do not route arbitrary actions.                                                                                                                                                                                                               |
| C15 BYOW/agents                 | D                                                             | Separate future bounded design/implementation. No tools, arbitrary SQL, BYOW or outbound webhook work now.                                                                                                                                                                                  |
| C16 SAF-T/subscription payments | Acceptable / False positive                                   | Existing SAF-T generation/XSD validation retained. Subscription payment fulfillment is not customer fiscal settlement.                                                                                                                                                                      |
| C17 impersonation               | Closed existing routes and primitive; D                       | Any future adapter must preserve the demonstrated refusal and attribution behavior.                                                                                                                                                                                                         |

External AGT assumptions still requiring homologation evidence (B): official schema/signing fixtures and acceptance; remote duplicate/replay and uncertain acknowledgement outcomes; legal correction/receipt/cancellation behavior; series/environment and AGT request-identity guarantees; key custody and production authorization. These block the affected fiscal capability, not safe scoped reads. No new AGT semantics were invented and no production flag was enabled.

Production parity (C): ADR 0001 and ADR 0007 require PostgreSQL but specify no version. The observed local MySQL setting and SQLite suite are not deployment evidence. The supported/tested reference for this gate is 18.6. CI configuration is completed and statically validated, with a pinned `postgres:18.6` service, explicit test database, pgsql/pcntl requirements and mandatory test command. Actual hosted execution, service-image pull and required branch checks were not verified. Before deployment, capture actual engine/version and rerun the gate on any differing supported version; do not assume parity.

## Exact bounded handoff — Sol Medium, Phase 2

Recommended next action: start a separately authorized **Phase 2 shared capabilities and read-first API foundation** using Sol Medium with this prompt:

> Read the master prompt and `docs/phase-1-capability-contract.md` in full, including the final Phase 1 sign-off and B/C/D gates. Preserve all Phase 1 changes and regression tests. Build only the next bounded shared-capability/read-first foundation. Reuse `ExecutionContext`, `DocumentCapabilities`, `CurrentAgtState`, existing policies and fiscal domain actions. Add typed, validated read commands and minimal resource serializers; require explicit workspace/legal entity/environment selection, current verified/MFA session authority, server-defined permissions, correlation and safe audit. Add narrowly scoped read adapters behind existing authenticated session protections, never anonymous access or mutable browser workspace fallback. Test foreign/sibling entities, environments, unresolved evidence, revoked membership, role limitations, safe fields and AGT projection provenance. Expand wrappers incrementally; do not export unguarded actions or existing aggregate queries wholesale. Do not create API credentials, integrations/agent identities, BYOW, agent tools, general outbound webhooks or new fiscal mutations. Fiscal issuance/corrections, external delivery, autonomous recurring expansion, production AGT and historical analytics are excluded pending separate Astra clearance. Any new credential/delegation mechanism needs its own reviewed scope/authority contract before external use. Preserve at least the final SQLite and PostgreSQL baselines in this report, zero new PHPStan diagnostics, types and changed-file quality checks. Report the diff, tests and remaining gates, then stop for review.

This is safe to hand to Sol Medium within that exact boundary. Phase 2 has not been started. The user must initiate the handoff; no thread, credential, API route or production deployment is created by this report.

## Explicit amendment SA1 — status authority, 2026-10-07

The user-authorized narrow Astra review approves [SA1](phase-4b-status-authority-amendment.md) as the controlling amendment to C04 and the AGT state projection section. Historical text above records the original decision; it is not silently replaced. Persisted submission status and frozen legacy fallback alone no longer establish AGT acceptance for receipt eligibility. The single evidence-qualified projection must distinguish fiscal issue facts, delivery workflow, AGT observations, uncertainty and display freshness. Receipt eligibility requires authoritative bound matching V, idle synchronization, no unresolved uncertainty/conflict/pending work, and all existing fiscal/context/integrity checks, revalidated under the locks specified in SA1. Unknown, partial and conflicting evidence fail closed; age alone does not invalidate a proven acceptance. Frozen documents and old attempts/events remain unchanged.

This is an intentional tightening for formerly eligible but unsupported legacy records. The old v1 ten-field response remains an explicitly deprecated legacy workflow summary, not the qualified projection or an authorization signal. SA1 defines exact reconstruction categories, concurrency, migration consequences, API compatibility and required regressions. All other signed invariants remain unchanged. The corrected bounded Sol handoff is in the amended Phase 4B design report; this documentation amendment implements no runtime behavior.
