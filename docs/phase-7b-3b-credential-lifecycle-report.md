# Phase 7B.3b — below-active credential lifecycle implementation

Date: 2026-10-09. Governing authority: approved PA1 Option B and [supplement §11.1](phase-7b-3-tenant-ai-control-plane-supplement.md), plus the user's corrected implementation request. **The bounded below-active implementation and required validation are complete and ready for independent review. No independent approval is claimed.** The original active-rotation request, B1 stop and PA1 decision are preserved below and in the machine-readable history.

## Implemented boundary

Two new internal services implement candidate allocation/replacement, exact-candidate revocation, workspace/connection disablement and transactional secret destruction. No migration, existing runtime edit, route, command, job, container binding, promotion permit, verifier or gateway consumer was added. All 51 frozen files remain byte-identical to the PA1 evidence hashes. `TenantAiStorage::configure()` retains its accepted first-candidate behavior.

`TenantAiLifecycle` accepts `TenantAiContext` for candidate creation/replacement and the separate `TenantAiEmergencyContext` only for revoke/disable. The latter preserves fresh primary owner membership, original membership identity, web actor, verified email, MFA, valid work session, CSRF, explicit workspace/deployment and impersonation/automation/console restrictions. It does not require recent password confirmation, provider approval or available KEKs. Its type cannot be passed to candidate creation or frozen envelope insertion. It is nonserializable and introduces no alternate secret reader.

## State and transaction contract

| Operation                                  | Committed result                                                                                                                    | Failure/no-change semantics                                                                                                                                                                        |
| ------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Candidate with expected absence            | New pending/unverified UUID, next immutable version, connection revision +1                                                         | Existing pending UUID, stale settings/connection, unavailable key or invalid input denies without changes.                                                                                         |
| Explicit replacement of exact pending UUID | Old pending becomes revoked with destroyed envelope; new pending/unverified UUID and generation; connection revision +1             | Any failure restores old material, revisions and audits. This is not active rotation.                                                                                                              |
| Revoke exact pending UUID                  | Revoked tombstone; ciphertext, wrapped DEK and KEK reference NULL; server destruction/revocation timestamps; connection revision +1 | No provider-side invalidation claim. Missing KEK does not prevent revocation.                                                                                                                      |
| Repeat revoke                              | Fresh revisions and already-destroyed revoked version return the same metadata                                                      | Stale revisions conflict first. No duplicate audit/revision. A revoked row with unexpected retained material fails closed.                                                                         |
| Workspace disable                          | Mode disabled, profile/connection/version selections cleared, settings revision +1                                                  | Already disabled with fresh revision is no change. No credentials are promoted, decrypted or destroyed by disable.                                                                                 |
| Connection disable                         | Disabled flag true, connection revision +1                                                                                          | Does not switch workspace mode or grant another provider. Already disabled with fresh revisions is no change.                                                                                      |
| Active/replaced/verification operations    | Unavailable                                                                                                                         | No positive active fixtures, permit issuer or verification metadata writer. Unexpected active/replaced connection history denies lifecycle mutation; workspace disable remains selection-clearing. |

Every transaction uses the primary connection, one transaction attempt and canonical root fencing. PostgreSQL must report READ COMMITTED; another isolation level is refused. Applicable order is deployment root → settings → connection → existing candidate credential; multi-row extension must retain the full signed ordering below. Exact ownership/revisions are reloaded after locks. No secret is fetched to destroy it. All writes carry tenant/context predicates, and parent revision updates additionally use CAS. Version allocation reads the largest retained generation under the connection lock and rejects integer overflow. Frozen partial uniqueness independently prevents two pending/active rows. There are zero active credentials in all successful new fixtures.

Full unchanged lock order: deployment global root; remaining provider/model controls sorted by UUID then budget controls; settings by workspace numeric key; connections by UUID; credentials by UUID; approvals then acknowledgements by primary key; windows by budget UUID, scope rank, scope key and UTC start; attempt/allocations; required audit last; commit. This stage uses only the applicable subset and touches no ledger/approval/window rows. No network occurs inside or outside lifecycle transactions. Each mutation revalidates owner context before entry, after acquiring root, at required audit and before commit.

## Audit and custody

Pending replacement emits `assistant.ai.credential_revoked`, `assistant.ai.credential_destroyed`, then `assistant.ai.credential_configured`, sharing a server-generated operation UUID. Single revoke emits the first two. Workspace disable emits `mode_changed` and `selection_changed` only for changed fields. Connection disable emits `selection_changed` with fixed outcome `disabled`. Audit is mandatory and transactional; any failure rolls back lifecycle state, secret destruction, new envelope insertion and revisions. No success audit survives a rollback.

Metadata contains only fixed outcomes, public workspace/connection/version/profile identifiers, A1 actor attribution, operation UUID and old/new revisions. IP/User-Agent are explicitly NULL. No raw input, secret, envelope, KEK identifier/path or upstream error is included. The transaction callback argument is a native `SensitiveParameter` because its closure captures candidate input; explicit exception-trace dumps with argument capture enabled are tested, not only the default exception dumper. New metadata responses retain the accepted six-field credential allowlist; disable responses contain only mode or connection ID/disabled flag. Fixed exceptions drop underlying exception chains.

Frozen `TenantAiEnvelope::seal()` and `insert()` remain the only new-secret primitives used. Ciphertext insertion still bypasses Laravel query-event binding disclosure through the existing prepared statement on the same transaction connection. Destruction updates secret columns to NULL without selecting their contents. The existing test-only custody reader cannot recover a destroyed version; a callback-delivery assertion verifies that boundary. Backups and previously held process memory are outside per-row destruction: this implementation makes no erasure claim about them. No rewrap or new production decrypt consumer was implemented.

## Contract mapping and new tests

| Requirement                                      | Implementation / adversarial evidence                                                                                                                                                                                                                                           |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Immutable candidate identity, generation and CAS | `TenantAiLifecycle::candidate`, parent locks/CAS, fresh version UUID; replacement, stale identity/absence/settings/connection and overflow cases.                                                                                                                               |
| Terminal revocation/destruction                  | `revoke`/`destroy`; NULL material, retained A1, custody callback denial, duplicate behavior and direct-SQL resurrection/deletion rejection.                                                                                                                                     |
| Emergency authority                              | `TenantAiEmergencyContext`; missing recent confirmation/KEK succeeds for revoke, but owner removal/role/inactive/email/MFA/session/CSRF/method/auth/deployment/impersonation/automation failures deny. Candidate context substitution is rejected.                              |
| Tenant isolation                                 | Explicit predicates on settings, connection, credential and metadata; missing versus foreign fixed errors; concurrent foreign-target attempt cannot mutate foreign custody.                                                                                                     |
| Atomicity/freshness                              | Fail each replacement audit event, revoke/disable audit, final authority withdrawal; PostgreSQL AFTER-trigger failures after destruction, insertion, revision update and audit.                                                                                                 |
| Canonical locking and concurrent stale writes    | Independent processes with root-lock barrier and observed `pg_stat_activity` lock wait; simultaneous pending replacements, both replace/revoke orders, double revoke and both disable/replacement orders.                                                                       |
| Retention under concurrency                      | Account deletion is denied by existing retention preview while replacement holds its root lock; no fabricated expectation that a refused operation must acquire a lock.                                                                                                         |
| no_probe and no authority expansion              | Complete forged verification fields, failed verification and replaced transitions rejected by actual schema in testing/production environment names; exact public lifecycle method allowlist, no permit class, unchanged gateway/composition files and disabled provider flags. |
| Redaction                                        | New/old sentinel plaintext and envelope bytes absent from outputs, metadata audits, query listeners and captured logs; full accepted custody/remediation suite retained.                                                                                                        |

New test cases: 49 feature cases, exercised on SQLite and PostgreSQL, plus 14 PostgreSQL-specific cases. The latter comprise nine actual lock-contention cases, one overlapping early retention denial and four database-trigger rollback cases. Counts across databases overlap and must not be added as unique cases. No test was deleted or weakened; no active row was created, guard disabled or test-specific schema exemption added.

Files introduced:

- `app/Fiscal/TenantAiEmergencyContext.php`
- `app/Fiscal/TenantAiLifecycle.php`
- `tests/TenantAiLifecycleFixtures.php`
- `tests/Feature/TenantAiLifecycleTest.php`
- `tests/Unit/PostgresTenantAiLifecycleTest.php`

Existing files changed by this implementation: this report and `phase-7b-3b-credential-lifecycle-evidence.json` only. Governing contracts remain unchanged. No migrations or dependencies changed.

## Validation

Validation uses PHP 8.4.25, Laravel 13.24.0 and Pest 5.0.4. PostgreSQL is 18.6, UTF8, primary READ COMMITTED. Disposable database names begin `facturac_test_`; the final integration run uses a separately created UTF8 database to avoid overlapping the earlier run.

| Gate                                    | Fresh result                                                                                                              |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| SQLite lifecycle + accepted custody     | 133 passed / 669 assertions, exit 0.                                                                                      |
| PostgreSQL lifecycle + accepted custody | 153 passed / 971 assertions, exit 0, 24.866 seconds. Includes every final lifecycle case and accepted custody tests.      |
| Complete SQLite                         | 2,306 passed / 335 PostgreSQL-only skipped / 15,337 assertions, exit 0, 746.629 seconds. One warning, no details emitted. |
| Full PostgreSQL concurrency             | 335 passed / 9,700 assertions, exit 0, 844.480 seconds. One warning, no details emitted.                                  |
| PostgreSQL integration                  | 1,542 passed / 11,293 assertions, exit 0, 1,612.658 seconds. Initial 1,541-case run retained as historical evidence.      |
| Focused gateway/quarantine/audit        | 86 passed / 910 assertions, exit 0. Frozen runtime unchanged.                                                             |
| Assistant UI                            | 12 passed, exit 0.                                                                                                        |
| Type checking                           | Passed, exit 0.                                                                                                           |
| PHP syntax / Pint                       | Five new PHP files parse; dirty-file Pint passes after formatting only new files.                                         |
| PHPStan                                 | Exit 1, exactly 21 inherited diagnostic objects; zero new. No suppressions/baseline edits.                                |
| Repository ESLint / resource format     | Exit 1 each: unchanged 9,719 errors / zero warnings and four existing resource-format failures. No frontend changes.      |

The final broad suites were launched against the final runtime hardening. One subsequent **test-only** addition explicitly covers disabling a customer-managed pending selection (not an active credential). That case passes on SQLite and PostgreSQL, including the consolidated focused selections. It is not included in the broad-run counts above. No application code changed after final broad-suite launch, and overlapping test counts are not summed.

The full SQLite rerun did not reproduce the original PDF anomaly or the initial countdown failure. The PDF issue remains **UNRESOLVED NON-SECURITY TEST ANOMALY** rather than being falsely declared fixed. The countdown assertion uses real `now()`/`time()` and expects a constant remaining-second value; its unchanged source and the immediate 17-case / 90-assertion rerun establish an unrelated one-second timing boundary. Neither issue required weakening an assertion or changing production session behavior. Broad-suite warnings match the historical count, but their identity is not proven because the runner emits no details.

The four formatting failures remain `resources/js/pages/Establishments/Index.vue`, `resources/promo/coming-soon.html`, `resources/promo/render.mjs` and `resources/promo/switch.html`. Their bytes and every PHPStan diagnostic source file are unchanged by 3b. None supplies lifecycle authorization or secret handling. The inherited debt does not invalidate the focused trust-boundary evidence, and no clean repository-wide lint/static-analysis claim is made.

An existing PostgreSQL assistant test rewrote the historical Phase 6 evidence artifact during validation. Its exact pre-task bytes were restored after both concurrency runs completed; this is not a Phase 3b artifact change.

See machine-readable evidence for exact commands, exits, counts, log hashes and before/after preservation hashes. No unfinished gate is treated as passing.

## Failed attempts and corrections

1. First SQLite lifecycle run: 37/41 passed, four failed. Two exposed a new query bug: Laravel `value()` retained the helper's existing select list, so it read the UUID instead of version number. Explicitly select `version_number` before reading it. Two audit expectations included unrelated factory `created` events; scope the ordered assertions to the exact assistant lifecycle event namespace while still checking every lifecycle event. The corrected 41-case run passed; subsequent added cases also pass.
2. Initial PostgreSQL attempt was blocked by sandbox localhost permissions. Reran against the same disposable test database with approved local access; no provider access was requested or used.
3. First permitted PostgreSQL run: 54/55 passed, one harness timeout. Existing account deletion refuses retained custody before locking. Corrected the retention race to require that expected early refusal while replacement is held at root; all other races require an observed PostgreSQL lock wait. No runtime guard was changed.
4. A mixed manual-migration/RefreshDatabase command passed 120/151, with 29 failures and two errors caused by a persisted fixture from the intervening old manual PostgreSQL suite. Group manual suites before transactional feature suites; new manual lifecycle teardown also resets the framework migration-state marker. The corrected combined command passed 151/151 without changing frozen tests or production code; the final expanded selection passes 153/153.
5. Initial PHPStan found two new nullsafe/coalescing diagnostics in audit metadata. Use explicit nullable-connection branches. Final diagnostic objects match the inherited 21 exactly; no suppression or baseline edit.
6. An additional security check reproduced a new disclosure path: explicitly dumping `getTrace()` with `zend.exception_ignore_args=0` exposed the candidate secret through the transaction callback's captured variables. Default exception dumping alone did not reproduce it. Marked the private callback argument `SensitiveParameter`; the strengthened test now passes on SQLite and PostgreSQL. Only inert test material was involved. The failing test log is kept locally as hashed historical evidence; its raw dump is not copied into this report/artifact. All broad gates were rerun successfully against the final hardening.
7. Initial full SQLite: 2,304 passed, one failure, 335 PostgreSQL-only skipped and one warning. The unchanged WorkSession countdown test crossed a second boundary (2,099 versus 2,100); its unchanged 17-case suite immediately passed. This is separately recorded timing evidence, not a Phase 3b authorization relaxation. No countdown/PDF tests or application behavior were changed. The final full rerun is recorded in the validation table above.
8. The server's default SQL_ASCII template refused UTF8 creation of a new disposable integration database. Created that database from `template0` with UTF8; no application migration or production database change.
9. Pint formatted only new PHP files. Broad-suite results and the historical PDF anomaly are recorded separately in the validation section.

## Deferred proofs and hard exclusions

Active A→B/A→C rotation, active promotion/revoke races, positive provider-verified authority uniqueness, concrete permit issuer/binding/expiry/replay proofs, active-state rollback/retention and inference-admission races remain deferred to 7B.3c's reviewed evidence stage. Zero active rows and pending races do not certify these properties. No legitimate active fixture exists at this stage, and none was manufactured. KEK rewrap/production decrypt maintenance also remains deferred under PA1. Section 6 migration/evidence review remains a prerequisite; this task does not begin it.

No verifier, promotion permit, probe, live provider request, real credential/KEK provisioning, payer assignment, general ledger change, enabling/mode selection, management UI/API, provider adapter, endpoint, gateway consumption, external access or inference activation. Application and fiscal authority stay unchanged. Historical metadata-only rows and A1 attribution are retained; operational backup retention/restore and production custody remain external gates.

## Completion assertions

These assertions cover only the approved PA1 below-active scope and disposable test evidence:

- BELOW-ACTIVE CREDENTIAL LIFECYCLE: IMPLEMENTED
- ACTIVE CREDENTIAL PROMOTION: NOT IMPLEMENTED — DEFERRED TO 7B.3c
- ACTIVE ROTATION: DEFERRED; POSITIVE ACTIVE ROTATION CONCURRENCY: NOT PERFORMED
- DATABASE SELF-AUTHORIZED PROMOTION: ABSENT
- SYNTHETIC VERIFIED CREDENTIALS: ABSENT
- no_probe GUARD: PRESERVED
- REVOCATION: FAIL CLOSED
- TRANSACTIONAL SECRET DESTRUCTION: VERIFIED
- STALE LIFECYCLE WRITES: REJECTED
- BELOW-ACTIVE CONCURRENCY: SERIALIZED
- CROSS-TENANT LIFECYCLE ACCESS: DENIED
- LIFECYCLE AUDIT SECRET LEAKAGE: NOT IDENTIFIED
- 7B.3a CUSTODY BOUNDARY: PRESERVED
- AI GATEWAY CREDENTIAL CONSUMPTION: NOT IMPLEMENTED
- PROVIDER VERIFICATION: NOT IMPLEMENTED; PROVIDER VERIFICATION CLAIMS: ABSENT
- PROVIDER ACTIVATION: UNCHANGED
- PRODUCTION INFERENCE: DISABLED

These are implementation assertions, not deployment approval or proof of provider validity. The exact remaining limitations are deferred active authority, production key/backup operations, PHP memory zeroization, and the inherited diagnostics/anomaly recorded above. No new architecture decision or contract deviation was needed.

## Exact independent Astra review handoff

> Independently review only Phase 7B.3b's corrected PA1 below-active lifecycle. Read supplement §5.1 and §11.1, PA1 decision evidence, this report/evidence and preserved B1 history, accepted 3a custody/re-review and frozen Phase 7A/7B.1/7B.2 baseline. Inspect the actual two new services, shared fixture, feature tests and PostgreSQL harness. Try to falsify fresh owner/emergency authority separation, explicit workspace/deployment scoping, expected candidate/settings/connection CAS, generation allocation, terminal NULL destruction and no resurrection, canonical root ordering, rollback and mandatory metadata-only audit. Verify missing KEKs/expired recent confirmation do not impede authorized emergency revoke, while loss of owner/MFA/session/CSRF authority still denies. Independently exercise both serialized race orders, observe real PostgreSQL lock contention, distinguish the early retention denial from a blocked lock, and verify stale operations cannot retire a newer candidate. Check exact frozen hashes and unchanged no_probe/active/initial/selection/retention guards. No test may create active/verified state, fake permits or change the production schema. Confirm all gateway/transport/ledger/provider/capability boundaries remain unchanged, and that customer material is still disconnected from inference. Review exact fresh versus historical validation, initial failures, the PHPStan object comparison and inherited lint/format/PDF debt; do not accept this report as proof. Report findings and independent verdict only. Do not implement 3c, provider verification, active rotation, new adapters, UI, provisioning or activation.

---

## Historical B1 handback — preserved verbatim

The following report records the original stop before PA1. Its blocked verdict is historical; the current bounded implementation and validation above supersede it only within approved below-active scope.

# Phase 7B.3b — credential lifecycle implementation blocker

Date: 2026-10-09. **Stopped before runtime, migration or test changes.** Phase 7B.3a remains accepted and frozen. This is a bounded implementation handback, not an amendment or an independent approval. Governing authority is the user's Phase 7B.3b request, the [control-plane supplement](phase-7b-3-tenant-ai-control-plane-supplement.md), the accepted [custody report](phase-7b-3a-encrypted-credential-storage-report.md) and evidence, and the signed Phase 7/7B architecture. The preceding independent re-review in the conversation accepted custody with an unresolved non-security PDF test anomaly; no separate re-review document was written.

## B1 — verification evidence and the frozen no-probe guard

The requested active-credential rotation/concurrency proofs cannot run against the accepted database guard without a narrowly approved change to the verification boundary.

1. Supplement §3.5 requires `active` to have `verification_state=verified`, a verified profile, verification operation ID and verification timestamp. `app/Fiscal/TenantAiSchema.php:71` enforces that requirement.
2. The accepted `no_probe` guard at `app/Fiscal/TenantAiSchema.php:145` rejects every non-`unverified` verification state and every populated verification/test/use field on insert or update. The separate initial-insert guard also forbids starting with an active or verified row.
3. Supplement §3.5 requires verification updates to match a finalized successful probe of the exact credential version/profile/revision in **3c**, and prohibits an application `verified=true` test bypass. The existing legacy provider attempt/permit has no credential-version-bound probe contract. Those extensions are explicitly deferred to supplement §6 / 3c; the legacy ledger and gateway remain frozen.
4. Supplement §11 nevertheless assigns atomic promotion machinery with denied production verification and **synthetic test permits** to 3b. Synthetic fixtures are authorized in principle. What is not specified is how those permits may satisfy or replace the persisted `no_probe` restriction while retaining the accepted production denial and avoiding fabricated verification evidence.
5. The accepted custody report explicitly states that later migrations may replace staging guards only when corresponding authoritative evidence checks are implemented. The current user request freezes 3a, prohibits fabricated verification and requires a stop if a safe lifecycle requires changing a frozen custody invariant.

This is not a request to permit live provider calls. A local encryption/format check does not establish provider verification. An arbitrary operation UUID is not successful probe evidence. Removing the guard and trusting an ordinary boolean, changing the active-state CHECK, temporarily dropping it in concurrency tests, adding unapproved probe tables, or extending the frozen ledger would each choose a security boundary not resolved by this handoff.

### Reproduction

An isolated in-memory SQLite process migrated the current schema, created an inert credential through the real owner-scoped custody service, and located the actual generated `no_probe` update trigger. Three updates were attempted inside transactions:

| Attempt                                                 | Result                                |
| ------------------------------------------------------- | ------------------------------------- |
| Set verification state to `verified`                    | Rejected; credential remained pending |
| Set active plus all fields required by the active CHECK | Rejected; credential remained pending |
| Set verification state to `failed`                      | Rejected; credential remained pending |

No guard was disabled. No production database, real key or provider was used. Temporary synthetic key files were removed. The result confirms intended 3a denial, not a new 3a defect. PostgreSQL generates the same predicate in `TenantAiSchema::reject`; no fresh PostgreSQL lifecycle proof is claimed.

### Exact Astra decision required

Resolve the **3b verification/promotion evidence boundary** and issue a corrected bounded handoff:

- Specify whether and how 3b may replace the frozen `no_probe` guard, including the exact migration scope and production invariant that replaces it.
- Define the issuer, immutable credential/workspace/deployment/profile/revision binding, successful outcome and replay/staleness checks of an approved synthetic promotion permit. State what persists, what the database must enforce and why no application boolean or arbitrary operation UUID becomes verification authority.
- Define how real PostgreSQL rotation/concurrency tests obtain legitimate active fixtures without silently weakening the production guard or substituting a materially different schema. If an explicit test-only schema/fixture exception is intended, approve and bound it rather than leaving implementation to infer it.
- Keep production verification and credential consumption unavailable, and keep 3c accounting/probe extensions and frozen gateway files excluded.
- Alternatively, expressly narrow 3b to pre-verification operations and defer active promotion/rotation and their completion assertions to the later approved evidence stage. That would change the requested deliverable and must be explicit.

These are alternatives for Astra to resolve, not decisions made by this report. No implementation of either alternative has begun. The user's instruction to implement only pre-verification states when verification cannot occur does not justify claiming the separately required successful active rotation and concurrency tests. Because the stop condition is reached, this handback does not leave a partial lifecycle implementation awaiting an implicit architecture choice.

## Canonical lock order — restated before implementation

The supplement's unchanged order is:

1. Deployment global/root `ai_gateway_controls` row, `FOR UPDATE`; multiple roots in UUID byte order or separate deployments.
2. Remaining gateway provider/model controls in stable UUID order, then `assistant_provider_controls` in budget UUID order.
3. `tenant_ai_settings` in workspace numeric order.
4. `tenant_ai_connections` in UUID order.
5. `tenant_ai_credentials` in UUID order, never active-first.
6. Bound tenant approvals, then acknowledgements, each in primary-key order.
7. Provider windows by budget UUID, scope rank (deployment month/day, workspace month/day, user day), scope key bytes, UTC start.
8. Attempt row or new attempt/allocations, mandatory audit last, then commit.

Lifecycle operations must take the applicable ordered subset, beginning at root, discover IDs without locking if needed, then reload ownership/revisions under locks. Existing 3a/3b custody work does not acquire or alter legacy accounting merely to simulate a probe. Use primary PostgreSQL READ COMMITTED, consistent lock order, explicit expected revisions and no hidden deadlock retry. No new lock protocol was introduced or tested here.

## Approved state machine — not implemented by this handback

| Current state | Permitted different state | Required distinction                                                                             |
| ------------- | ------------------------- | ------------------------------------------------------------------------------------------------ |
| pending       | active                    | Exact successful verification and other promotion prerequisites; blocked evidence boundary above |
| pending       | revoked                   | Terminal local revocation; retain metadata and apply approved destruction policy                 |
| active        | replaced                  | Atomic successful replacement; previous authority retired with replacement/revocation metadata   |
| active        | revoked                   | Clear selection/disable affected policy transactionally; no new use                              |
| replaced      | none                      | Never reactivated                                                                                |
| revoked       | none                      | Never reactivated                                                                                |

`failed` is a **verification state**, not a new credential lifecycle state; a failed candidate remains pending. Same-state metadata/maintenance changes are not permission to reactivate or bypass verification. Existing per-connection pending/active partial uniqueness and the single settings selection remain authoritative; no new broader uniqueness rule was invented.

The supplement already requires failed rotation to preserve the previous active credential, terminal metadata retention, secret destruction as soon as no allowed in-flight use remains, required transactional metadata-only audit and canonical CAS behavior. The approved lifecycle audit vocabulary is retained; this report does not add a `credential_promoted` or `rotation_failed` event. No claim is made that these new lifecycle transactions or races have been implemented or verified.

## Changes and validation

Only this report and `phase-7b-3b-credential-lifecycle-evidence.json` are added. No code, configuration, test, migration, dependency, capability mapping or governing contract was modified. The frozen `no_probe` guard is intact. No credential was provisioned outside disposable synthetic validation.

Fresh checks:

- Existing SQLite custody/remediation suite: **84 passed / 441 assertions**, exit 0. Exact command: `php artisan test --compact tests/Feature/TenantAiCustodyRemediationTest.php tests/Feature/TenantAiStorageTest.php`.
- Isolated schema/service probe: all three prohibited verification updates denied; no partial state, zero provider calls, fixtures removed. Exit 0.
- Frozen/source preservation and report/evidence validation: see the machine-readable artifact for exact hashes and results.

No lifecycle tests or migrations were added. PostgreSQL lifecycle concurrency, broad PostgreSQL integration, full SQLite, gateway/UI/types/Pint/PHPStan implementation gates were **not rerun for this blocked attempt**. The preceding re-review's results are historical context, not Phase 3b implementation evidence: 84 SQLite custody, 90 PostgreSQL custody/security, 86 gateway/security, 37 PDF and 12 UI cases passed; types, syntax and read-only Pint passed; PHPStan matched exactly 21 inherited diagnostics. The full-suite PDF failure remains **UNRESOLVED NON-SECURITY TEST ANOMALY** and was not modified or relabeled. Existing ESLint/formatting debt is untouched.

## Completion assertions and exclusions

- CREDENTIAL LIFECYCLE: NOT IMPLEMENTED — BLOCKED
- SINGLE AUTHORITATIVE CREDENTIAL: EXISTING 3a CONSTRAINTS PRESERVED; NEW LIFECYCLE NOT VERIFIED
- ATOMIC ROTATION / FAILED ROTATION / SIMULTANEOUS ROTATION / ROTATION-REVOCATION RACES: NOT IMPLEMENTED OR VERIFIED
- REVOCATION / REACTIVATION / CROSS-TENANT LIFECYCLE ACCESS / LIFECYCLE AUDIT: NO NEW SERVICE OR VALIDATION CLAIM
- 7B.3a CUSTODY BOUNDARY: PRESERVED
- AI GATEWAY CREDENTIAL CONSUMPTION: NOT IMPLEMENTED
- PROVIDER ACTIVATION: UNCHANGED
- PRODUCTION INFERENCE: DISABLED

No 3c work, ledger extension, provider/model resolution, OpenAI, self-hosted endpoint, UI, live verification, real credential import or production KEK provisioning. No automatic next phase.

## Exact narrow Astra handoff

> Resolve only Phase 7B.3b blocker B1: the verification/promotion evidence boundary. Read this report/evidence, supplement §§3.5, 5, 6 and 11, the accepted 3a report paragraph on staging guards, and the actual active CHECK, initial-insert and no_probe guards in TenantAiSchema. The user requires active rotation and PostgreSQL concurrency proofs while freezing 3a custody, forbidding fabricated verification, and excluding 3c probe/accounting/gateway changes. Synthetic test permits are approved in principle; specify exactly how they interact with persisted verification authority without an ordinary verified=true or arbitrary-operation-ID bypass. Approve the minimal guard/migration/test-fixture contract and production denial guarantees, or explicitly narrow 3b's scope and defer active rotation. Do not implement lifecycle, change cryptography, add live probes, alter frozen gateway/accounting code, enable production or reopen unrelated accepted decisions. Return a precise amendment and corrected bounded implementation handoff.

PHASE 7B.3b BLOCKED — LIFECYCLE/CONCURRENCY DECISION REQUIRED

---

## Current bounded implementation decision

The historical B1 blocker above was resolved by approved PA1 Option B. The corrected below-active scope is implemented and validated; the exact independent Astra handoff above is the next action. Active promotion, active rotation and every deferred 7B.3c proof remain unimplemented. Stop here for independent review.

PHASE 7B.3b COMPLETE — READY FOR INDEPENDENT REVIEW
