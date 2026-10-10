# Phase 7B.3c-1 — independent migration execution review

Date: 2026-10-09. **PASSED for the bounded migration/reconciliation implementation only.** No unresolved BLOCKER, HIGH or MEDIUM finding. This is not approval for production deployment, provider verification, active-promotion services, gateway consumption or inference.

The candidate was frozen before testing. All nine PHP files, the supplement, frozen package/evidence and implementation report/evidence remain byte-identical. This review adds only this report and [execution evidence](phase-7b-3c-1-execution-review-evidence.json). Reviewer-only harnesses live outside the repository; their source is embedded in the evidence. No implementation was modified or remediated.

## Independent evidence and execution

Read the governing supplement, frozen SQL package and R1/R2/R3 history, both evidence artifacts, implementation report, all nine candidate PHP files, relevant historical custody/lifecycle/gateway code and tests. Inspected installed Laravel 13.24.0 migration transaction support and `HasUlids`. Boost documentation tools were unavailable. No new framework API was implemented.

The initial frozen package SHA-256 is `3e885e96c24ff0bc8d348df134657afcf560009c7a1518baeca4f684e59e2e52`; design evidence is `eeb8e660e549df490a7c3397266993d04edd5212755c2c951bebc542bf720cb3`. Implementation report is `4bfeb02f1407309e8f15fea8850f08d183a32a935acca8d5611019f051015a66`; implementation evidence is `6bd1f71ba70fbef81704cbb74d23ce1895a891487ec1babb319c55a57696d40b`. All candidate hashes are recorded before and after review.

Created five **new**, local PostgreSQL 18.6 databases on loopback port 55439, all UTF8 with deterministic C collation:

- `facturac_test_phase7b3c1_review_clean_utf8`: complete 86-migration installation, schema matrix, catalog, unused-schema downgrade/reinstall and initial reviewer experiments.
- `facturac_test_phase7b3c1_review_upgrade_utf8`: six populated/interrupted/lock/audit upgrade rehearsals, each starting at the historical 83-migration boundary.
- `facturac_test_phase7b3c1_review_race_utf8`: fresh original schema contender test.
- `facturac_test_phase7b3c1_review_security_utf8`: unchanged historical custody/lifecycle/gateway/quarantine suites at the approved pre-3c boundary.
- `facturac_test_phase7b3c1_review_adversarial_utf8`: clean final reviewer-authored R2, independent contender, receipt uniqueness, actual downgrade-wrapper and generated-identity checks.

No Sol rehearsal database was used. Candidate tests hardcode Sol database names; scratch copies changed only those allowlists to exact review-owned names. The evidence includes the complete diffs. No assertion or database constraint was weakened. Historical security tests run from their original paths with only the scratch application's migration directory restricted to the historical boundary. Explicit Herd PHP 8.4.25 and private project-storage directories were used.

| Fresh gate                                                                      | Exit | Actual result                                     |
| ------------------------------------------------------------------------------- | ---- | ------------------------------------------------- |
| Full clean migration chain                                                      | 0    | 86 migrations, including actual M1–M3 wrappers    |
| Schema/reconciliation/receipt matrix                                            | 0    | 166 passed; 1,698 assertions                      |
| Populated upgrade, staged interruption, lock timeout and audited reconciliation | 0    | 6 passed; 88 assertions                           |
| Original independent-process schema race                                        | 0    | 1 passed; 15 assertions                           |
| Custody/lifecycle plus gateway, Anthropic migration and execution quarantine    | 0    | 232 passed; 1,805 assertions                      |
| Reviewer R2, independent direct-SQL contender and receipt/downgrade cases       | 0    | 3 passed; 31 assertions                           |
| Reviewer generator-to-storage-to-snapshot byte capture                          | 0    | 1 passed; 9 assertions                            |
| SQLite frozen-SQL/closed-boundary tests                                         | 0    | 2 passed; 22 assertions                           |
| Independent Python receipt encoding                                             | 0    | 2 exact vectors; 94 mutations reject original MAC |
| Actual unused-schema rollback and reinstall                                     | 0    | M3→M2→M1 down, catalog capture, M1–M3 up          |
| PHP syntax                                                                      | 0    | All nine candidate PHP files                      |
| PHPStan                                                                         | 1    | Exactly 21 inherited diagnostic objects; zero new |

Exact commands, environments, statuses, durations, log hashes and reviewer harnesses are in the evidence. Fresh tests total 411 executions and 3,668 assertions across the listed processes; this is not a claim of 411 distinct new tests.

## Design fidelity and executable boundaries

All eight PHP SQL strings match both the authoritative Markdown listings and JSON bytes, including trailing newlines. Tables, columns, defaults, nullability, FKs, CHECKs, indexes, partial predicates, deferred triggers, retention and inverse SQL therefore have no translation drift. The actual installed catalog independently reproduces the implementation's recorded catalog exactly: **251 columns, 278 constraints, 72 indexes and 121 non-internal triggers**. New constraints are validated and indexes valid.

The nonliteral wrapper differences are intentional and bounded:

1. Laravel owns one transaction per migration. M2 executes constraints and guards before committing removal of staged closure. M3 down is the approved no-op.
2. `TenantAiVerificationSchema::reconcile()` owns one PostgreSQL transaction, rejects nesting/SQLite, binds the manifest through the equivalent `jsonb_to_recordset` loader, executes the remaining frozen SQL unchanged and requires persisted audit before commit. The operator, not this method, authenticates the historical manifest and supplies approved audit attribution. There is no route or command exposing it.
3. Prepared-statement cleanup handles PostgreSQL's connection-local PREPARE lifetime after rollback. Invalid input and missing audit were followed by successful same-session reconciliation; failure does not poison the next legitimate invocation.
4. SQLite skips the successor migrations and retains the historical closed guard. This is an explicit compatible-regression boundary under the narrower migration-only authorization, not delivery of the broader future file map's SQLite successor parity. PostgreSQL remains mandatory.

The frozen package's known legacy compatibility work is still deferred: predicate-aware acknowledgement upsert, discriminator-scoped legacy queries, root-fenced shared accounting and control revision updates. The implementation does not pretend these are deployed. Closed admission and drained writers are prerequisites, not a safe-online-deployment claim.

## R2, R3 and receipts

**R2 DOES NOT RECUR.** The reviewer reconstructed the original SQL by reversing only `shared_window_row` to `w`; its hash exactly matched historical `4ad379eec69b0a4b62caecd3e377af175917bfd773854b11b30cfb72efa7400f`. PostgreSQL reproduced `column reference "w.budget_id" is ambiguous`. The failed subtransaction left the captured persistent state unchanged. Current SQL then passed mapped zero-use preflight and positive history, producing five shared window ceilings with one attempt and 1,024 output units each. These overlapping ceilings are not additive monetary charges. Current reconciliation remains hash `19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc`.

**SNAPSHOT = EXACT STORED PUBLIC-ID BYTES.** Installed Laravel generates lowercase ULIDs. A separate reviewer test captured real Workspace/LegalEntity `creating` events after generation, persisted real models, copied the fixture snapshots and compared PHP strings and PostgreSQL `convert_to(..., 'UTF8')` bytes. Both matched exactly with deferred integrity forced. The full matrix independently exercised both uppercase transformations, malformed shapes, different canonical IDs and a real foreign entity. Canonical values pass; uppercase is rejected by the CHECK; 27-character input is rejected earlier by column assignment. No case conversion or case-insensitive identity comparison occurs in the new production code. Existing generator/input normalization is not a snapshot transformation.

Independent Python serialization, separate from implementation fixtures, reproduced both canonical JSON byte strings, domain prefix, length, SHA-256 and HMAC using the public zero-byte test key. All 47 signed positions per vector were mutated, including uppercase substitutions at positions 6 and 7; all 94 failed comparison to the original MAC. The canonical field order and explicit decimal-string/null representations were preserved. No vector was regenerated.

Schema fixtures deliberately contain inert envelopes and nonauthentic receipt MACs. They demonstrate persistence structure only. They do not prove a provider exchange, authentic receipt, live issuer or promotion permission. Their only consumers are tests; production has no verifier/permit/receipt consumer or test-fixture binding.

## Reconciliation, integrity and atomicity

The executed matrix covers each of the eight orphan counters separately; the original combined orphan; missing and supplied mappings; deleted attempts with retained counters; an unmapped surviving attempt with **zero windows**; neutral zero-use orphans; all legacy outcomes; multiple budgets/deployments; exact UTC dates; partial history; inconsistent source and destination totals; missing/wrong roots and mappings; duplicate mappings; window identity substitution; NULL/negative values; maximum bigint and checked overflow.

The SQL discovers A from surviving attempts and B directly from authoritative windows/controls before consulting candidate mapping. A UNION B must be fully covered. Supplying a mapping for missing historical attempts still fails history comparison. Neither disabled budgets nor absent attempts erase liability. Zero-use sources remain untouched. Valid history preserves original money, actual counters, IDs, consent and credential state; only exact shared attempt/output ceilings are added. Repeat execution is idempotent for accounting. No legacy allocations, attempts, receipts or consent are fabricated.

The corrupt-restore cases intentionally remove counter constraints only inside disposable test savepoints to reach defensive preflight; they then verify restoration. Ordinary corruption cases use the real constraints. Active/rotation tests never disable guards or indexes.

Failure after shared writes and failure of required audit roll back all affected captured persistent state. Populated upgrade proves old encrypted rows and preexisting legacy attempts/windows survive actual M1–M3. Interrupted M1, M2 and M3 restore their previous state and can roll forward. A second connection's read lock causes the configured 250 ms migration lock timeout; after release the test observes granted AccessExclusiveLock and the 10-second statement limit. This confirms maintenance locking, not production-scale capacity.

Canonical reconciliation SQL acquires ordered deployment roots, ordered budget UUIDs, then ordered windows by budget/scope rank/C-byte scope key/UTC start. Preflight refuses unmapped liability before later window writes; it does not reacquire an earlier class after windows. The original process race also exercises the prescribed root/budget/settings/connection/sorted-credential/approval/window ordering. No network is performed under locks.

## Active uniqueness, receipt retention and independent contention

Direct PostgreSQL tests cover zero/one active, second-active rejection, disabled connections, separate connections, retained revoked/replaced history, selection consistency, successful A→B and rollback restoring A. The preserved non-null connection key and `tenant_ai_credentials_one_active` index enforce **at most one active per connection**, not always one active globally.

The reviewer added a different two-process harness without the application's root fence. One transaction completes a structurally valid A→B transition and pauses before commit. The other proposes a distinct version 3 and attempts activation. An observer sees PostgreSQL `wait_event_type=Lock`, `wait_event=transactionid`; after releasing the first transaction, the second fails **SQLSTATE 23505 on `tenant_ai_credentials_one_active`**. Exactly the first winner remains active. Neither index nor constraint was disabled. This proves database arbitration independently of the original root/CAS race; it is still schema-only, not production promotion authority.

Receipt tests cover tenant/entity/credential/provider/profile bindings, required fields, immutable finalized fields, deletion/retention, complete nine-allocation sets, duplicate/extra/missing allocations and terminal relationships. A reviewer case separately proves duplicate evidence UUID rejection on `ai3c_evidence_id_uq` and calls both actual migration down wrappers after retained evidence. Both refuse without changing the captured state.

**PERSISTENCE STRUCTURE VERIFIED** does not mean **RUNTIME VERIFICATION AUTHORITY IMPLEMENTED**. Fully coherent synthetic SQL can satisfy structural constraints; it cannot create an absent private issuer or gateway consumer. Cryptographic authenticity and live request authority remain mandatory future work.

## Downgrade, recovery and preservation

Fresh actual unused-schema rollback restores **164 columns, 218 constraints, 42 indexes and 109 triggers**. Columns, indexes and guards match the historical frozen catalog exactly. One CHECK's PostgreSQL deparse places varchar-to-text casts on individual constant array members rather than the whole array; the four constants, order, comparison and semantics are identical. The approved inverse SQL itself is byte-exact. Reinstall succeeds. This is classified as deparser representation, not semantic drift.

After retained gateway/receipt evidence, both actual M2 and M1 wrappers refuse downgrade. Independent precision-loss and new-unit-counter cases also refuse. Recovery therefore retains forward schema and closes admission/egress; there is no destructive automatic escape hatch. Production backup restoration, capacity and operational manifest provenance remain separate gates.

All **56** frozen custody/lifecycle/gateway files and **2,600** original inventoried files match their accepted hashes. Original failed DDL review, R1 remediation/acceptance, R2 failure/correction/success and R3 failure/lowercase/vector correction remain intact. The historical 12-pass/1-error run was not treated as passing evidence.

## Validation evidence and inherited debt

The recorded logs and hashes independently confirm the claimed 166 schema, 6 upgrade, 1 race, **2,309 SQLite**, **1,543 PostgreSQL integration** and **335 PostgreSQL concurrency** results. This review reran the acceptance-critical new PostgreSQL cases and relevant old security/concurrency tests. It did not duplicate unrelated full financial/read-plane suites. Broad results are explicitly historical verified evidence, not fresh review executions. The report correctly discloses final PostgreSQL-only test additions after full SQLite discovery.

Fresh PHPStan exit 1 contains the **same 21 diagnostic objects as the pre-3c 3b baseline**, including paths, lines, identifiers, messages and tips. No suppression or new diagnostic. All nine candidate files pass PHP syntax. Previously recorded Pint, types and 12 assistant UI passes have matching logs and unchanged relevant source. No PHP formatter was run in write mode during this immutable review.

The **9,719 ESLint errors**, four resource-format failures (`resources/js/pages/Establishments/Index.vue`, `resources/promo/coming-soon.html`, `resources/promo/render.mjs`, `resources/promo/switch.html`), inherited SQLite/concurrency warnings and unresolved non-security PDF anomaly remain inherited debt. Their logs and relevant source are preserved. None affects these SQL integrity/reconciliation/credential boundaries. This review does not repair, suppress or relabel that debt.

Reviewer harness failures are preserved separately: numeric-leading scratch path rejected by Pest; copied Feature tests missing original Pest path bindings; PostgreSQL SUM decimal strings incorrectly expected as PHP integers; reconciliation correctly refused reviewer-retained gateway rows on an attempted reused setup; second fixture needed its normal deferred-constraint sequencing; exploratory receipt-prefix and PHPStan-output parsing mistakes. These were isolated harness issues. Final gates use the corrected reviewer harness and a new clean adversarial database. No candidate assertion or implementation changed.

## Findings and operational qualifications

No BLOCKER, HIGH, MEDIUM or LOW implementation defect identified.

| Severity      | Location                                                                                                                          | Evidence, impact and required treatment                                                                                                                                                                                                                                                                                                                                   |
| ------------- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| INFORMATIONAL | `docs/phase-7b-3c-1-migration-review-package.md`, §3 “Compatibility defects avoided”; `app/Fiscal/AssistantProviderLedger.php:83` | The old acknowledgement upsert cannot target the successor partial index without its predicate; old writers also lack generalized fencing/accounting. This is an explicitly deferred dependency of the accepted migration-only scope, not unexplained drift. Keep admission closed and writers drained; implement/review compatibility before any live successor traffic. |
| INFORMATIONAL | `database/migrations/2026_10_09_020000_extend_ai_verification_metadata.php:10` and sibling migrations                             | SQLite intentionally skips successor schema. Its passing suites prove historical regressions only. Use PostgreSQL for successor integrity and any later authority work; do not claim SQLite parity.                                                                                                                                                                       |
| INFORMATIONAL | `app/Fiscal/TenantAiVerificationSchema.php:13` and frozen package §6                                                              | Historical deployment mapping and administrative audit attribution are trusted maintenance inputs, not inferable SQL facts. Require independently approved manifest, closed/drained writers and metadata-only attributed audit for operational reconciliation. No public invoker exists.                                                                                  |

Repository configuration still hardcodes provider `enabled=false` and `egress_enabled=false`; external access defaults and tenant authority are unchanged. Review operations used only local databases and offline fixtures. **Provider calls: zero.** Recorded implementation commands and preserved code show no provider operation introduced. This is local repository/execution evidence, not an audit of uninspected remote production processes.

## Required verdicts

| Verdict                               | Result              |
| ------------------------------------- | ------------------- |
| IMPLEMENTATION VS FROZEN DDL          | EXACT               |
| R2 IDENTIFIER COLLISION               | REMEDIATED          |
| R3 PUBLIC-ID CONTRACT                 | VERIFIED            |
| SNAPSHOT EXACT-BYTE BINDING           | VERIFIED            |
| RECEIPT VECTORS                       | VERIFIED            |
| CLEAN POSTGRESQL INSTALL              | PASSED              |
| POPULATED POSTGRESQL UPGRADE          | PASSED              |
| ATTEMPT-DERIVED COVERAGE              | VERIFIED            |
| RETAINED-LIABILITY COVERAGE           | VERIFIED            |
| UNMAPPED SURVIVING ATTEMPT            | REJECTED            |
| ORPHAN RETAINED LIABILITY             | REJECTED            |
| DELETED-ATTEMPT LIABILITY             | PRESERVED           |
| ZERO-LIABILITY ORPHANS                | UNTOUCHED           |
| LIABILITY CONSERVATION                | VERIFIED            |
| RECONCILIATION ATOMICITY              | VERIFIED            |
| EXACTLY ONE ACTIVE CREDENTIAL         | POSTGRESQL-ENFORCED |
| DATABASE SELF-AUTHORIZED VERIFICATION | ABSENT              |
| RECEIPT PERSISTENCE CONSTRAINTS       | VERIFIED            |
| SCHEMA ROTATION SUPPORT               | VERIFIED            |
| SIMULTANEOUS CONTENDERS               | SERIALIZED/REJECTED |
| CANONICAL LOCK ORDER                  | PRESERVED           |
| UNUSED-SCHEMA DOWNGRADE               | PASSED              |
| POST-BOUNDARY DOWNGRADE               | REFUSED             |
| ROLL-FORWARD RECOVERY                 | PRESERVED           |
| 7B.3a CREDENTIAL CUSTODY              | PRESERVED           |
| 7B.3b BELOW-ACTIVE LIFECYCLE          | PRESERVED           |
| AI GATEWAY CREDENTIAL CONSUMPTION     | ABSENT              |
| PROVIDER VERIFICATION RUNTIME         | ABSENT              |
| PROVIDER CALLS                        | ZERO                |
| PROVIDER ACTIVATION                   | UNCHANGED           |
| PRODUCTION INFERENCE                  | DISABLED            |

PHASE 7B.3c-1 MIGRATION IMPLEMENTATION: ACCEPTED

PHASE 7B.3c-1 POSTGRESQL EXECUTION: VERIFIED

PHASE 7B.3c-1 RECONCILIATION: VERIFIED

PHASE 7B.3c-1 FORWARD-ONLY BOUNDARY: VERIFIED

The next eligible step is a separately authorized provider-verification implementation design under VC1, preserving this migration candidate. No verifier, promotion service, provider request, 3c-2 work or activation was begun.

PHASE 7B.3c-1 EXECUTION REVIEW PASSED — READY FOR 7B.3c-1 PROVIDER-VERIFICATION IMPLEMENTATION DESIGN
