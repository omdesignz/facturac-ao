> Current R3 execution record is appended below under “Current clean R3 implementation and rehearsal”. Earlier blocked verdicts and failures are preserved as historical records. The bounded implementation and fresh execution gates are complete; independent execution review is the next step.

# Phase 7B.3c-1 — migration implementation blocked by reconciliation SQL

Date: 2026-10-09. Status: **BLOCKED — DESIGN REVIEW REQUIRED**.

Latest outcome: the approved R2 identifier correction executed successfully on a new database. The resumed run then exposed R3, the public-identity case conflict documented in the final section below. The original R2 record is retained as history.

## Scope and stop decision

The user authorized exact translation of the frozen migration package, isolated PostgreSQL rehearsal, and implementation evidence. The user also explicitly required an immediate stop if PostgreSQL behavior contradicted the reviewed SQL. The first execution rehearsal exposed such a defect before Laravel migration files or runtime/test code were created. Implementation stopped; the frozen design was not patched.

Only this report and `phase-7b-3c-1-migration-implementation-evidence.json` were added to the repository. No application code, migration, test, configuration, dependency, or governing document was changed. Raw SQL rehearsal occurred only in the newly created disposable database `facturac_test_phase7b3c1_execution` on local port 55439. The original review database remained unchanged.

## Acceptance-blocking design defect R2

**MEDIUM — PL/pgSQL variable/table-alias collision prevents reconciliation.**

Location: `docs/phase-7b-3c-1-migration-review-package.md:1126`, declaration of `w`, and lines 1129–1142, preflight queries using table alias `w`. The exact approved reconciliation listing has SHA-256 `4ad379eec69b0a4b62caecd3e377af175917bfd773854b11b30cfb72efa7400f`.

The final DO block declares `w public.assistant_provider_windows%ROWTYPE`. Its destination preflight then aliases `assistant_provider_windows` as `w`. PostgreSQL cannot resolve `w.budget_id` unambiguously. Execution reached this block after source coverage/history validation and failed:

```text
ERROR:  column reference "w.budget_id" is ambiguous
DETAIL:  It could refer to either a PL/pgSQL variable or a table column.
CONTEXT:  PL/pgSQL function inline_code_block line 5 at IF
```

`psql -v ON_ERROR_STOP=1 --single-transaction` exited **3**. The exception happens even with no history or shared target rows, because PostgreSQL resolves the expression before evaluating row matches. It is not a framework translation error: the reviewed SQL itself was executed unchanged, with only its explicitly required mapping input supplied.

The fixture created one disabled root, one unused legacy budget, one unused shared-usage budget, and their synthetic immutable mapping. It created no customer account approval, profile, credential, active state, receipt, or provider authority. A mapped zero-liability source is valid under the accepted contract. The earlier checks accepted it; the destination preflight could not execute. This prevents the intended successful reconciliation path and blocks implementation acceptance. It does not demonstrate escaped liability or partial accounting writes.

The prior static review missed this executable name-resolution failure. Its R1 orphan-liability reasoning is not overturned, but its acceptance is insufficient for an executable package until R2 is corrected and reviewed.

### Required bounded design decision

Authorize a lexical correction in the frozen reconciliation listing: give the PL/pgSQL row variable a name distinct from SQL aliases, updating its `SELECT INTO`, per-row comparisons, and update predicate references consistently. Alternatively, rename every colliding table alias consistently. Preserve all predicates, source coverage, types, ordering, locks, accounting, and transaction semantics. Do not change PostgreSQL's variable-conflict setting to mask the collision.

Update the reviewed package/evidence hashes through a bounded design correction and independent review. Then resume implementation with a regression that reaches destination preflight on both a zero-use valid mapping and complete positive historical data. The required unmapped surviving-attempt fixture remains mandatory. No correction was applied or tested in this task.

## Execution performed

The machine-readable evidence includes exact commands, exit codes, logs, all eight frozen SQL listings, the exact synthetic rehearsal input, and catalog snapshots. No provider API was called.

| Operation                                                  | Result                                                             |
| ---------------------------------------------------------- | ------------------------------------------------------------------ |
| Create new disposable PostgreSQL database                  | Exit 0                                                             |
| Install all existing pre-3c Laravel migrations from zero   | Exit 0                                                             |
| Execute exact M1 in its own transaction                    | Exit 0                                                             |
| Execute exact M2 constraints and guards in one transaction | Exit 0                                                             |
| Execute exact M3 validation in its own transaction         | Exit 0                                                             |
| Execute safe-down preflight and M2 down on unused schema   | Exit 0                                                             |
| Execute safe-down preflight and M1 down on unused schema   | Exit 0                                                             |
| Reinstall exact M1–M3 for reconciliation rehearsal         | Exit 0; this second installation used one encompassing transaction |
| Reconcile synthetic mapped zero-use source                 | Exit 3; R2 above                                                   |
| Read-only post-failure row census and catalog capture      | Exit 0                                                             |
| Read-only original baseline catalog preservation check     | Exit 0; exact match                                                |

The first M1/M2/M3 run preserves the reviewed separate transaction chronology. The second combined transaction was preparation for the isolated reconciliation attempt, not a proposed change to Laravel migration chronology. M3 down is the reviewed no-op; only the substantive M2/M1 down listings were executed.

The rehearsal proves that the raw up DDL installs and validates on an empty PostgreSQL schema and that the unused-schema down listings execute. It does **not** prove clean installation through new Laravel wrappers, a populated upgrade, every constraint's behavioral correctness, concurrency, source-counter conservation, successful reconciliation, or the forward-only refusal boundary. No such result is claimed.

## Rollback and database state

After the failed reconciliation transaction, the fresh database had zero controls, roots, windows, attempts, allocations, credentials, and audit records. The synthetic root and both budgets were rolled back with the mapping transaction. No reconciliation accounting write was reached. This verifies rollback of this fixture only, not the still-required failure-after-accounting-write/audit tests.

The disposable database remains at the installed M1–M3 schema for inspection, with no synthetic fixture rows retained. It is not wired into application configuration. Its catalog contains 251 columns, 278 constraints, 72 indexes, and 121 non-internal triggers across the inspected target tables. The existing VAP-only catalogue seed came from historical migrations; no customer profile was seeded.

The baseline catalog in the evidence is the accepted pre-3c database, freshly rechecked after rehearsal: 164 columns, 218 constraints, 42 indexes, and 109 triggers. A separate catalog of the new target immediately before M1 was not captured; do not describe the baseline reference as that missing snapshot. The full pre-3c install log establishes the target's migration chronology.

No lock-monitoring worker was started before the stop. ALTER/ordinary index operations still require the reviewed maintenance window; no zero-downtime claim is made. SQL retained its 250 ms lock timeout and 10-second statement timeout.

## Preservation and limits

- All three governing files match their hashes captured at task entry.
- All 56 frozen source/test/config files match their accepted hashes.
- All 2,600 pre-existing inventoried files remain unchanged.
- All eight extracted SQL listings match their frozen hashes.
- The original review database catalog matches the accepted snapshot exactly.
- Production inference and provider egress remain hard-disabled in `config/assistant.php`.
- Gateway credential consumption, provider verification, runtime receipt authority, promotion, and active rotation services remain absent from this change.
- No credentials, signing keys, customer grants, consent, or provider calls were introduced.

No migration files were generated; therefore there are no migration-file hashes yet. The exact SQL hashes are recorded instead. Laravel framework version was confirmed as 13.24.0. Boost `search-docs` was unavailable; installed migration conventions and official Laravel documentation were consulted. No dependency changed.

## Unperformed gates and inherited debt

The immediate-stop instruction took precedence over further implementation and rehearsal. All R1-01 through R1-10 fixtures, the explicit unmapped surviving-attempt regression, positive populated reconciliation, numeric-boundary tests, active uniqueness/rotation schema fixtures, receipt persistence/vector tests, concurrency, lock observation, and post-boundary downgrade-refusal tests remain **NOT RUN**.

SQLite, custody/lifecycle/gateway/security suites, assistant UI, type checking, PHP syntax, Pint, PHPStan, and repository-wide lint were **NOT RUN** after the blocker. No PHP or frontend files changed. Document formatting is recorded separately in the evidence.

The inherited 21 PHPStan diagnostics, ESLint/resource-formatting debt, SQLite/PostgreSQL warnings, and PDF issue were not repaired or reclassified. The PDF issue remains **UNRESOLVED NON-SECURITY TEST ANOMALY**. No claim of fresh regression success is made.

## Required assertions

`NOT RUN` is used where the requested binary labels would falsely imply an executed result.

| Assertion                                  | Result                                                           |
| ------------------------------------------ | ---------------------------------------------------------------- |
| REVIEWED DDL IMPLEMENTATION                | EXACT for SQL executed; Laravel implementation not completed     |
| CLEAN POSTGRESQL INSTALL                   | PASSED for raw M1–M3; new Laravel migration path NOT RUN         |
| POPULATED POSTGRESQL UPGRADE               | NOT RUN                                                          |
| ATTEMPT-DERIVED RECONCILIATION COVERAGE    | NOT VERIFIED by execution                                        |
| RETAINED-LIABILITY RECONCILIATION COVERAGE | NOT VERIFIED by execution                                        |
| UNMAPPED SURVIVING-ATTEMPT SOURCE          | NOT RUN                                                          |
| ORPHAN RETAINED LIABILITY                  | NOT RUN                                                          |
| MISSING ATTEMPTS REDUCE LIABILITY          | NOT RUN; no successful reconciliation claimed                    |
| LIABILITY CONSERVATION                     | NOT VERIFIED by execution                                        |
| RECONCILIATION FAILURE ATOMICITY           | NOT VERIFIED comprehensively; this pre-write failure rolled back |
| EXACTLY ONE ACTIVE CREDENTIAL              | NOT VERIFIED by execution; accepted index preserved              |
| DATABASE SELF-AUTHORIZED VERIFICATION      | ABSENT from this implementation; no runtime authority added      |
| RECEIPT PERSISTENCE CONSTRAINTS            | NOT VERIFIED behaviorally                                        |
| CANONICAL LOCK ORDER                       | PRESERVED in exact SQL; concurrency NOT RUN                      |
| ROLLBACK BEFORE FORWARD-ONLY BOUNDARY      | VERIFIED for unused raw schema only                              |
| ROLLBACK AFTER FORWARD-ONLY BOUNDARY       | NOT RUN                                                          |
| 7B.3a CUSTODY                              | PRESERVED by hashes; regression suite NOT RUN                    |
| 7B.3b LIFECYCLE                            | PRESERVED by hashes; regression suite NOT RUN                    |
| AI GATEWAY CREDENTIAL CONSUMPTION          | ABSENT                                                           |
| PROVIDER VERIFICATION                      | ABSENT                                                           |
| PROVIDER ACTIVATION                        | UNCHANGED                                                        |
| PRODUCTION INFERENCE                       | DISABLED                                                         |

## Exact bounded handoff

> Perform the narrow Astra review of R2 in `docs/phase-7b-3c-1-migration-implementation-report.md` and its evidence JSON. PostgreSQL rejects the frozen reconciliation's final DO block because its row variable `w` collides with table alias `w` in destination preflight. Authorize only an unambiguous naming correction, consistently updating references, in the reviewed package and evidence. Preserve R1 source-universe independence, all eight counter checks, missing-history refusal, accounting conservation, lock order, transaction semantics, and every other accepted DDL decision. Do not mask ambiguity through server settings. Provide corrected hashes and a bounded resume instruction requiring zero-use and positive-history destination-preflight regressions plus all previously mandated fixtures, especially independent unmapped surviving-attempt coverage. Do not implement runtime verification, promotion, rotation, gateway consumption, or provider access. Stop after the corrected design is reviewed.

**PHASE 7B.3c-1 MIGRATION IMPLEMENTATION BLOCKED — DESIGN REVIEW REQUIRED**

## Resume after approved R2 — R3 public-identity case blocker

Date: 2026-10-09. **Implementation stopped again under the frozen-design stop rule.** R2 is no longer the current blocker. R3 requires a predicate/identity-contract decision and cannot be repaired as another procedural identifier rename.

### Fresh execution and verified progress

Created **new** disposable PostgreSQL database `facturac_test_phase7b3c1_r2`; the earlier failed database was not reused. Installed all pre-3c Laravel migrations from zero, then executed exact M1, M2 (constraints plus guards), and M3 in their reviewed separate transactions. Every command exited 0. Executed SQL matches all current frozen hashes, including corrected reconciliation `19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc`.

The zero-use raw rehearsal reached all three DO blocks and completed successfully. Its fixture was explicitly rolled back; `psql --single-transaction` then emitted a harmless “there is no transaction in progress” warning on its final COMMIT. This was fixture cleanup, not an application failure. No production audit or commit-success claim is inferred from that raw rehearsal.

Added only `tests/Unit/PostgresAiVerificationMigrationTest.php` as an isolated persistence/reconciliation test harness. It requires `FISCAL_PG_GATE=1`, testing environment, PostgreSQL, and the exact disposable database name. Each fixture runs inside an outer transaction, rolls back afterward, and purges the connection. The harness exercises the exact frozen reconciliation string after checking its hash; it supplies only the reviewed prepared mapping input. It is not a production reconciliation entry point and does not implement required operational audit persistence.

The first run passed **3 tests, 35 assertions** (exit 0). The expanded run reached **13 tests: 12 passed, 1 error, 109 assertions** (exit 2 with stop-on-failure).

| Executed case                                                                | Outcome                                                                                                                                                                                         |
| ---------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Corrected zero-use destination preflight                                     | Passed; persistent snapshot unchanged                                                                                                                                                           |
| Complete positive surviving history, five source windows                     | Passed; original counters unchanged, five destination windows each receive exactly one attempt unit and 1,024 output units; original money remains on source windows; no allocations fabricated |
| Unmapped surviving attempt with **zero retained windows**                    | Passed; `AI incomplete liability mapping`; full captured persistent snapshot unchanged                                                                                                          |
| Unmapped orphan with only `reserved_micro_usd` nonzero                       | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `attempt_count` nonzero                            | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `actual_input_tokens` nonzero                      | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `actual_output_tokens` nonzero                     | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `actual_micro_usd` nonzero                         | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `unknown_usage_count` nonzero                      | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `reserved_attempt_units` nonzero                   | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Unmapped orphan with only `reserved_output_units` nonzero                    | Passed; coverage refusal and snapshot unchanged                                                                                                                                                 |
| Deleted legacy attempt with positive windows and an approved fixture mapping | Passed; `AI legacy liability reconciliation required`; retained counters unchanged                                                                                                              |
| Structurally bound active-credential fixture                                 | Failed before activation, at gateway attempt insertion: R3                                                                                                                                      |

These are partial implementation gates. The positive fixture proves the renamed procedural row's SELECT INTO, counter comparisons and row-ID update execute; both destination aliases remain unchanged. It does not prove all legacy outcomes, all edge dates, idempotent re-execution, committed audit, concurrency, or the entire R1 fixture matrix. Eight individual-counter orphan cases execute R1-02; the exact multi-counter R1-01 fixture has not separately run. Zero-use mapped input is not the separate unmapped zero-orphan R1-06 fixture. Missing mandatory cases remain outstanding, not implicitly passed.

### R3 — frozen CHECK rejects actual workspace/entity public identities

**MEDIUM, acceptance-blocking design incompatibility.**

Frozen package locations:

- Lines 414–415: gateway attempt shape requires `workspace_public_id` and `legal_entity_public_id` to match `^[0-9A-HJKMNP-TV-Z]{26}$`, an uppercase-only alphabet.
- Lines 636–637: deferred binding requires those same values to be exactly equal to stored workspace/entity `public_id` values using `IS DISTINCT FROM`.

Both actual `Workspace` and `LegalEntity` models use Laravel `HasUlids` for `public_id`. Installed Laravel 13.24.0, `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasUlids.php:18`, returns `strtolower((string) Str::ulid())`. The fixture used these real factories and copied their stored public IDs without normalization. This is current application behavior, not an invented malformed identifier.

PostgreSQL rejected the admitted gateway row with **SQLSTATE 23514**, `assistant_provider_attempts_values`. The failing row carried lowercase IDs such as `01m4g5dd4yqgh2hvh6nfyqxqp7`. A subsequent read-only PostgreSQL expression check confirmed:

- stored lowercase identifier matches reviewed uppercase regex: **false**;
- uppercase form matches that regex: **true**;
- uppercase form is distinct from the original stored identity: **true**.

Consequently, uppercasing the snapshot is not a valid implementation workaround: the later exact binding would reject it, and silently normalizing attribution/receipt inputs would change the approved identity contract. Changing existing public IDs, CHECK predicates, binding comparisons, or receipt canonicalization is outside this implementation authorization. The fixture was not changed to artificial uppercase identities to bypass the real branch.

The error occurred before an attempt, allocation or active credential could be persisted. Its test transaction rolled back. No production authority was created: the attempted schema fixture used explicit inert envelope strings, an all-zero non-authentic MAC, and the `offline_fixture` realm. It uses no signer, decryptor, provider, runtime permit or production promotion service. Reaching a valid active state remains unproven.

### Required narrow Astra decision

Reconcile the frozen public-identity shape constraints with actual stored identities. Decide the accepted alphabet/canonical representation for workspace/entity snapshots, preserving exact identity binding and existing public identifiers without data rewriting. Explicitly determine implications for historical/synthetic receipt vectors and future signed canonical inputs. Authorize only the necessary frozen predicate/contract amendment and refreshed evidence. Do not replace exact tenant binding with a permissive comparison for convenience. Once reviewed, require regression coverage for real lowercase factory identities and invalid/foreign/substituted identities. No design amendment was made here.

### State, preservation and limits

Post-test read-only census: zero roots, controls, windows, attempts, allocations, credentials and audit records. Synthetic rows from both successful and failed test fixtures were rolled back. The new database retains the reviewed M1–M3 schema only, alongside historical catalogue seed data. Captured catalog: **251 columns, 278 constraints, 72 indexes, 121 triggers**. No second populated-upgrade database was created before the stop.

All three current governing artifact hashes match task-entry values. All **56/56** frozen source/test/config files and **2,600/2,600** pre-existing inventoried files remain unchanged. No Laravel migration wrapper, production reconciliation helper, application behavior, configuration or dependency was added. The earlier report/evidence content remains historical; this section and the evidence's `resume_after_R2` object append the new run rather than pretending R2 never happened.

Fresh gates: new PostgreSQL test run as above; PHP syntax passed; scoped `vendor/bin/pint --dirty --format agent tests/Unit/PostgresAiVerificationMigrationTest.php` exited 0 and formatted only the new test. Formatting changed imports/PHPDoc only after the stop; the failing test was not weakened or removed. Tests were not rerun after formatting because implementation stopped. Document formatting is recorded in evidence.

Remaining gates **NOT RUN**: full mandatory R1 matrix; corrupt restore/negative/NULL/overflow and maximum-value cases; complete mapping/identity adversarial cases; audit failure after writes; active uniqueness, active rotation, simultaneous contenders, receipt persistence/vector reproduction; populated upgrade; deployment lock observation; fresh unused-schema downgrade and post-boundary downgrade refusal; custody/lifecycle/gateway suites; full SQLite/PostgreSQL regressions; assistant UI; type checking; PHPStan; unrelated lint. Historical downgrade success is not promoted to a fresh result.

Inherited PHPStan/ESLint/formatting/database warning debt is unchanged and not rerun. PDF remains **UNRESOLVED NON-SECURITY TEST ANOMALY**. Zero provider calls. No real credentials or API probes. Production inference and egress remain disabled; provider activation unchanged.

### Resumed-run required assertions

| Assertion                                  | Result                                                                   |
| ------------------------------------------ | ------------------------------------------------------------------------ |
| APPROVED IDENTIFIER CORRECTION             | IMPLEMENTED EXACTLY in executed SQL                                      |
| REVIEWED DDL IMPLEMENTATION                | EXACT in executed raw SQL; Laravel wrappers not implemented              |
| CLEAN POSTGRESQL INSTALL                   | PASSED for pre-3c Laravel history plus exact raw M1–M3                   |
| POPULATED POSTGRESQL UPGRADE               | NOT RUN                                                                  |
| CORRECTED RECONCILIATION                   | EXECUTED successfully on zero-use and positive-history fixtures          |
| ATTEMPT-DERIVED RECONCILIATION COVERAGE    | VERIFIED for independent no-window fixture                               |
| RETAINED-LIABILITY RECONCILIATION COVERAGE | VERIFIED for all eight individual counters                               |
| UNMAPPED SURVIVING-ATTEMPT SOURCE          | REJECTED                                                                 |
| ORPHAN RETAINED LIABILITY                  | REJECTED in eight individual-counter cases                               |
| ZERO-LIABILITY ORPHANS                     | NOT RUN; zero-use mapped case passed                                     |
| MISSING ATTEMPTS REDUCE LIABILITY          | NO in executed deleted-history case; refusal preserved all counters      |
| LIABILITY CONSERVATION                     | VERIFIED for executed positive fixture only                              |
| RECONCILIATION FAILURE ATOMICITY           | NOT VERIFIED after accounting writes; pre-write refusal snapshots passed |
| EXACTLY ONE ACTIVE CREDENTIAL              | NOT VERIFIED; R3 prevented valid active setup                            |
| DATABASE SELF-AUTHORIZED VERIFICATION      | ABSENT from this change; schema fixture is not runtime authority         |
| RECEIPT PERSISTENCE CONSTRAINTS            | NOT VERIFIED                                                             |
| CANONICAL LOCK ORDER                       | PRESERVED in exact SQL; concurrency not run                              |
| UNUSED-SCHEMA DOWNGRADE                    | NOT RUN in resumed run                                                   |
| POST-BOUNDARY DOWNGRADE                    | NOT RUN                                                                  |
| 7B.3a CUSTODY                              | PRESERVED by hashes; fresh regressions not run                           |
| 7B.3b LIFECYCLE                            | PRESERVED by hashes; fresh regressions not run                           |
| AI GATEWAY CREDENTIAL CONSUMPTION          | ABSENT                                                                   |
| PROVIDER VERIFICATION                      | ABSENT                                                                   |
| PROVIDER ACTIVATION                        | UNCHANGED                                                                |
| PRODUCTION INFERENCE                       | DISABLED                                                                 |

### Exact next handoff

> Perform only the narrow Astra review of R3: frozen gateway attempt CHECKs require uppercase workspace/entity ULIDs while actual Laravel HasUlids stores lowercase IDs and deferred guards require exact equality to those stored values. Inspect frozen package lines 414–415 and 636–637, installed HasUlids, the real Workspace/LegalEntity models, the failing schema fixture, and resumed execution evidence. Decide the minimum shape/canonical-identity amendment that accepts legitimate existing identities, rejects invalid/foreign/substituted identities, preserves exact tenant binding, and does not rewrite public IDs or silently change receipt meaning. Explicitly address signed-input/vector implications. Preserve R1, R2 and all unrelated DDL. Do not implement migrations or runtime authority. Return an exact amended contract and bounded implementation resume instruction, then stop.

**PHASE 7B.3c-1 MIGRATION IMPLEMENTATION BLOCKED — DESIGN REVIEW REQUIRED**

## Current clean R3 implementation and rehearsal

This section resumes the exact §14 handoff after approved R2 and R3. It does not amend the frozen design. The original DDL acceptance, R1 reconciliation coverage defect/remediation, R2 ambiguous procedural identifier failure and mechanical correction, successful corrected R2 execution, R3 public-ID failure and canonical-ID correction remain preserved above and in the governing review evidence. The historical **12 passed / 1 error / 109 assertions** is not current passing evidence.

### Authorized implementation

- `app/Fiscal/TenantAiVerificationSchema.php`: the eight frozen SQL listings as literal strings, including their exact trailing newline; controlled reconciliation transaction with bound JSON manifest input, required persisted audit and prepared-statement cleanup. No operator route or command is added. The operator must independently approve the manifest, close admission and drain old writers before invoking the maintenance procedure.
- Three migrations: `2026_10_09_020000_extend_ai_verification_metadata.php` (M1), `2026_10_09_020100_install_ai_verification_integrity.php` (M2), and `2026_10_09_020200_validate_ai_verification_integrity.php` (M3). Laravel runs each PostgreSQL migration transaction separately. M1 installs staged closure; M2 atomically installs constraints and deferred guards before removing staged closure; M3 validates. M3 down is the signed no-op. M2 and M1 down execute the shared refusal preflight before their frozen inverse SQL.
- `tests/AiVerificationMigrationFixtures.php`: synthetic schema fixtures only, extracted from the prior focused migration test. Active/rotation fixtures contain deliberately non-operational evidence and inert envelopes; they prove persistence constraints, not authentication or runtime promotion authority.
- `tests/Unit/PostgresAiVerificationMigrationTest.php`: expanded existing focused regression matrix.
- `tests/Unit/PostgresAiVerificationUpgradeTest.php`, `tests/Unit/PostgresAiVerificationConcurrencyTest.php`, and `tests/Unit/AiVerificationSchemaContractTest.php`: populated upgrade, failure/recovery, maintenance-lock observation, independent PostgreSQL contenders, exact frozen SQL hashes and SQLite boundary checks.
- This report and its existing evidence artifact. No existing runtime service, route, configuration, historical migration, frozen custody/lifecycle test or gateway implementation changed.

The helper's manifest loader uses bound `jsonb_to_recordset` parameters equivalent to the frozen prepared loader. It does not concatenate manifest values into SQL. The remainder of the reviewed procedure executes unchanged. A missing/failed audit rolls back fresh shared accounting; repeated successful reconciliation preserves counters. Each invocation owns its PostgreSQL transaction. SQL failures clean up the connection-local prepared statement so the same session can retry after correction. Audit attribution and the approved metadata vocabulary remain the trusted maintenance caller's responsibility; no general user-callable maintenance surface is supplied.

### Fresh databases and execution chronology

Initial new disposable databases were created with the local cluster's SQL_ASCII default. Focused schema execution succeeded, but the complete historical concurrency suite correctly refused the wrong encoding. Those results are preserved as unsuccessful environment setup, not accepted PostgreSQL gate evidence. New, independently created databases use `TEMPLATE template0 ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C'`:

- `facturac_test_phase7b3c1_r3_wrapped_utf8`: clean 86-migration install; schema matrix; unused-schema down; restored catalog; M1–M3 reinstall.
- `facturac_test_phase7b3c1_r3_upgrade_utf8`: six isolated rehearsals, each resetting only this explicitly guarded disposable schema to the historical 83-migration boundary before upgrade.
- `facturac_test_phase7b3c1_r3_races_utf8`: clean install and committed synthetic schema-only rotation race.
- `facturac_test_phase7b3c1_r3_regress_utf8` and `facturac_test_phase7b3c1_r3_concurrency_utf8`: complete unchanged historical integration/concurrency suites at their signed historical migration boundary.

No previous R2/R3 failed execution database was reused. All PostgreSQL access was local to disposable databases; no production database or provider was contacted. The historical-boundary scratch application uses the same source/tests/vendor and an isolated migration directory containing only the 83 historical migrations, as the frozen package requires. Its exact commands explicitly use Herd PHP 8.4.25. The main successor tests use the actual new migrations and real PostgreSQL guards, without disabling constraints.

### Requirement-to-evidence mapping

| Requirement                | Current implementation and evidence                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R2 preserved               | Exact reconciliation SHA-256 `19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc`; zero-use and positive-history reach destination preflight and writes.                                                                                                                                                                                                                                                                                                                                              |
| R3 exact identity          | Real Workspace/LegalEntity factories invoke production lowercase ULID generation; stored bytes are copied into attempt snapshots unchanged; exact deferred validation passes. Uppercase transformations of those actual stored IDs fail the shape CHECK. Different valid lowercase IDs fail binding; real foreign entity fails the composite context FK.                                                                                                                                                            |
| Malformed identities       | Both identity fields: uppercase, overflow first character, short/long values, excluded alphabet, separator, empty and NULL refused. A 27-character value fails `character(26)` assignment before the CHECK, explicitly distinguished in tests. No production normalization added.                                                                                                                                                                                                                                   |
| Receipt vectors            | Both amended frozen vectors reproduce byte length, SHA-256 and HMAC exactly. All 47 signed positions per vector are mutated: 94 mismatches. Separate real-model fixture proves canonical stored public-ID bytes enter signed material unchanged. No runtime signer/verifier/permit was added.                                                                                                                                                                                                                       |
| R1 reconciliation coverage | Eight independent orphan-counter cases and independent unmapped surviving attempt with no windows; missing/deleted/partial history; original combined-counter orphan with and without mapping; malformed/ambiguous/duplicate mappings; wrong destinations and window identities; existing shared destination mismatch/extra counters; actual-counter mismatch; NULL/negative schema rejection and isolated corrupt-restore preflight refusal for all eight counters; bigint maximum and checked aggregate overflow. |
| Legacy accounting          | Every admitted/received/failed/not-sent/profile-mismatch/usage-unknown outcome; two deployments/budgets; same actor across separate workspaces; UTC leap/year/month boundaries; zero liability; old monetary counters unchanged; shared reservations exact and repeated execution idempotent.                                                                                                                                                                                                                       |
| Allocation integrity       | Nine allocations required individually; role-shape money/attempt-unit checks; wrong output units/timestamp refused; duplicate and extra allocation refused; exact tenant/entity/profile/account binding remains frozen.                                                                                                                                                                                                                                                                                             |
| Authority boundary         | Exactly one active per connection via existing PostgreSQL unique index; disabled connection does not bypass uniqueness; separate connections allowed; schema-only A→B rotation and selected-version update; generation/destruction rules. SQL coherence is explicitly not cryptographic authority.                                                                                                                                                                                                                  |
| Concurrency                | Two forked PostgreSQL connections, barriers, observed lock wait and canonical root/budget/settings/connection/credential/approval/window order. One rotation commits; stale contender cannot replace the winner. Existing complete concurrency suite runs separately.                                                                                                                                                                                                                                               |
| Atomicity/recovery         | Failure after shared writes and missing audit restore prior persistent state; rotation failure restores A; interrupted M1/M2/M3 restore schema before roll forward; lock timeout leaves no partial M1; released lock permits retry; M1 successor branches remain closed.                                                                                                                                                                                                                                            |
| Downgrade                  | Actual unused-schema M3/M2/M1 rollback and reinstall; retained gateway/receipt evidence refuses down; independent legacy subsecond precision and nonzero new unit counters refuse down without data destruction.                                                                                                                                                                                                                                                                                                    |
| Custody/lifecycle          | Frozen source and tests preserved byte-for-byte; unchanged historical suites run at their accepted boundary; populated encrypted custody rows preserve original column hashes and secrets, with no fabricated active/verified state.                                                                                                                                                                                                                                                                                |

### Catalog, locks and deployment limitations

Installed target catalog: **251 columns, 278 constraints, 72 indexes, 121 non-internal triggers**. No unvalidated constraints or invalid indexes. All proposed persistent objects are present; the five M1 staging constraints are intentionally absent after M2. Safe down restores **164 columns, 218 constraints, 42 indexes and 109 triggers**. Columns, indexes and trigger/function definitions match the frozen baseline exactly. One CHECK deparse differs only in placement of varchar-to-text casts in a constant four-value array; the accepted inverse SQL was executed byte-for-byte. PostgreSQL also evaluates those two constant arrays as exactly equal. This is not claimed as textual catalog equality. After the authorized down/reinstall cycle, 80 new columns have later physical attribute ordinals because PostgreSQL retains dropped slots; relative visible column order and every nonordinal catalog field match the initial install exactly. The first whole-object comparison correctly reported that physical difference; it is not hidden or treated as a constraint change.

A second connection's held read lock causes the reviewed 250 ms migration lock timeout. Failure rolls back M1 completely. After release, the test observes the migration's granted `AccessExclusiveLock`, `lock_timeout=250ms` and `statement_timeout=10s`, then installs the remaining stages. This is a maintenance-window migration, not zero-downtime deployment. Worker draining is an operational prerequisite, not something these migration wrappers can infer or enforce remotely.

SQLite intentionally retains the closed historical schema: the three PostgreSQL-only migrations return `shouldRun=false`; controlled reconciliation rejects SQLite. This follows the bounded implementation request §32 (“SQLite may be used for compatible regression coverage”); the broader future-file map is not a claim of delivered SQLite successor parity. Fast SQLite tests are regression coverage, not proof of deferred integrity, successor schema parity or permission to enable customer authority on SQLite. PostgreSQL is mandatory for this bounded schema and all later authority work.

The signed package already identifies future legacy-writer compatibility work (partial-index-aware acknowledgement writes, legacy-only predicates, shared accounting/root fencing and revision increments). This migration-only implementation does not implement that runtime phase. Keep admission and production inference closed; do not deploy/open mixed old writers against the successor schema. No claim of ready-to-enable production operation is made.

### Failed attempts preserved

All raw logs remain referenced and hashed in the evidence artifact. No failed attempt is relabelled as passing:

- The original R2 and R3 frozen-design failures remain unchanged historical evidence.
- A synthetic observed organization field initially used a non-UUID string; corrected only the test fixture to a generated UUID.
- Initial extracted nowdocs omitted the final newline; literal strings were corrected to exactly reproduce all eight approved byte sequences.
- `migrate:fresh` does not remove PostgreSQL functions; repeated upgrade setup therefore collided with an existing function. Dedicated disposable upgrade tests now reset their explicitly guarded schema before installing the historical boundary.
- Some adversarial tests expected a later deferred message, while an earlier correct composite FK, credential relationship guard or role-shape CHECK rejected the input. Assertions now name the actual protective boundary; wrong-output-unit testing targets one appropriate shared allocation to reach deferred validation.
- New UTC-edge fixtures initially passed Carbon objects through Laravel's date formatting, losing explicit offsets. Only fixtures were corrected to bind UTC strings with microseconds and offsets; frozen SQL was unchanged.
- Initial complete SQLite execution exceeded the CLI's 128 MiB default. The signed 1 GiB baseline command was used for the complete rerun.
- Early PHPStan non-debug invocations returned empty output. Debug output initially exposed eight new literal-string return annotations needed by the database API; truthful literal-string annotations resolved them. Final diagnostics exactly match inherited debt; no suppression added.
- Initial scratch commands selected Homebrew PHP 8.5. Those specific process groups were stopped and commands restarted with explicit Herd PHP 8.4.25. The SQL_ASCII rehearsal run failed the existing UTF8 guard; fresh UTF8 databases replaced that environment for accepted gates. The scratch application initially placed synthetic test keys beneath world-writable `/private/tmp`, which correctly failed existing custody-path protection. Those broad commands were stopped; private `LARAVEL_STORAGE_PATH` directories under project storage were supplied for their complete reruns. An immediate focused run then passed all 153 custody/lifecycle tests without modifying their assertions or production guards.

### Explicit exclusions

Zero provider calls. No provider verification/probe, runtime receipt authority, active-promotion service, production active-rotation authority, gateway credential consumption, tenant inference, 3c-2, provisioning UI/command, customer catalogue seed, real credential, production key, endpoint expansion or provider activation. `config/assistant.php` retains hardcoded provider `enabled=false` and `egress_enabled=false`. External integration defaults remain unchanged/off. Synthetic active rows exist only in explicitly named disposable test databases.

### Validation results and independent execution handoff

The evidence artifact's `resume_after_R3.validation` records exact commands, working directories, exit statuses, counts, log paths and hashes. Historical unsuccessful commands remain separate.

| Gate                                                                   | Result                                                                      |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Clean UTF8 PostgreSQL installation                                     | Exit 0; 86 migrations including M1–M3                                       |
| Final schema/reconciliation/receipt matrix                             | Exit 0; 166 passed, 1,698 assertions                                        |
| Populated upgrade, stage interruption, lock timeout, audit and closure | Exit 0; 6 passed, 88 assertions                                             |
| Independent-process schema rotation                                    | Exit 0; 1 passed, 15 assertions                                             |
| Frozen SQL and SQLite boundary                                         | Exit 0; 2 passed, 22 assertions                                             |
| Full SQLite                                                            | Exit 0; 2,309 passed, 465 skipped, 15,367 assertions, one inherited warning |
| Historical PostgreSQL integration                                      | Exit 0; 1,543 passed, 11,301 assertions                                     |
| Historical PostgreSQL concurrency                                      | Exit 0; 335 passed, 9,700 assertions, one inherited warning                 |
| Dedicated PostgreSQL custody/lifecycle                                 | Exit 0; 153 passed, 971 assertions                                          |
| Assistant UI                                                           | Exit 0; 12 passed                                                           |
| Type checking                                                          | Exit 0                                                                      |
| PHP syntax / scoped Pint                                               | Exit 0; all nine changed PHP files; Pint passed                             |
| PHPStan                                                                | Exit 1; exact same inherited 21 diagnostic objects; zero new diagnostics    |
| Repository ESLint                                                      | Exit 1; inherited 9,719 errors, zero warnings; no JS changed                |
| Repository resource formatting                                         | Exit 1; same four inherited files; no resource changed                      |
| Changed implementation-document formatting                             | Exit 0; both implementation artifacts                                       |

The full SQLite discovery occurred before the final PostgreSQL-only adversarial additions; those are exercised in the complete final schema/upgrade matrices, and do not run on SQLite. A final run loading all changed test files passed two contract cases and skipped all 173 PostgreSQL-only cases (22 assertions). No existing test was weakened, disabled or deleted. The inherited PDF issue remains **UNRESOLVED NON-SECURITY TEST ANOMALY**; this work neither fixes it nor declares it flaky. Local CLI/FPM-resolution regression tests are included; no production FPM deployment claim is made.

### Required bounded assertions

| Assertion                                  | Result                                                               |
| ------------------------------------------ | -------------------------------------------------------------------- |
| REVIEWED DDL IMPLEMENTATION                | EXACT                                                                |
| CLEAN POSTGRESQL INSTALL                   | PASSED                                                               |
| POPULATED POSTGRESQL UPGRADE               | PASSED                                                               |
| ATTEMPT-DERIVED RECONCILIATION COVERAGE    | VERIFIED                                                             |
| RETAINED-LIABILITY RECONCILIATION COVERAGE | VERIFIED                                                             |
| UNMAPPED SURVIVING-ATTEMPT SOURCE          | REJECTED                                                             |
| ORPHAN RETAINED LIABILITY                  | REJECTED                                                             |
| MISSING ATTEMPTS REDUCE LIABILITY          | NO                                                                   |
| LIABILITY CONSERVATION                     | VERIFIED                                                             |
| RECONCILIATION FAILURE ATOMICITY           | VERIFIED                                                             |
| EXACTLY ONE ACTIVE CREDENTIAL              | POSTGRESQL-ENFORCED (at most one per connection; zero remains valid) |
| DATABASE SELF-AUTHORIZED VERIFICATION      | ABSENT (no runtime consumer or permit authority)                     |
| RECEIPT PERSISTENCE CONSTRAINTS            | VERIFIED at the schema boundary, not runtime authentication          |
| CANONICAL LOCK ORDER                       | PRESERVED                                                            |
| ROLLBACK BEFORE FORWARD-ONLY BOUNDARY      | VERIFIED                                                             |
| ROLLBACK AFTER FORWARD-ONLY BOUNDARY       | REFUSED                                                              |
| 7B.3a CUSTODY / 7B.3b LIFECYCLE            | PRESERVED                                                            |
| AI GATEWAY CREDENTIAL CONSUMPTION          | ABSENT                                                               |
| PROVIDER VERIFICATION                      | ABSENT                                                               |
| PROVIDER ACTIVATION                        | UNCHANGED                                                            |
| PRODUCTION INFERENCE                       | DISABLED                                                             |

### Exact independent execution-review handoff

> Perform the independent Phase 7B.3c-1 migration implementation and disposable PostgreSQL execution review. Read the governing supplement, frozen migration review package/evidence including R1/R2/R3, and current implementation report/evidence in full. Inspect the actual nine PHP files and all referenced logs/catalogs; the report is a handoff, not proof. Verify all eight executable SQL strings against frozen hashes and confirm no design edit, normalization, provider call or runtime authority. Use a NEW clean UTF8 disposable PostgreSQL database, never production or either historical failed database. Independently exercise M1–M3, populated pre-3c upgrade, exact real-model lowercase snapshots and uppercase rejection, both corrected receipt vectors and all 94 mutations, every orphan-counter and unmapped-attempt case, reconciliation conservation/atomicity/idempotence, schema-only rotation and competing transactions, lock/timeouts, unused-schema downgrade, retained-evidence refusal and roll-forward recovery. Check historical custody/lifecycle/gateway/security regressions at their frozen migration boundary and successor tests with real PostgreSQL guards; do not weaken either. Review the explicit SQLite closed-boundary limitation and deployment prerequisites rather than inferring runtime enablement from schema success. Verify exact inherited PHPStan objects and separate lint/format/PDF debt. Preserve the historical R2/R3 failure chain. Do not implement a verifier, runtime receipt authority, promotion service, production rotation, gateway consumption, 3c-2 or activation; make zero provider calls. Return findings with evidence and the smallest bounded remediation, if needed. Approve only this migration/reconciliation implementation when every required execution gate is satisfied. Stop for review; do not proceed to the next implementation increment.

All bounded execution gates are complete. No additional frozen-design defect was found. All 56 accepted frozen hashes and all 2,600 original inventory hashes match. The historical PostgreSQL plan tests rewrote their Phase 6 evidence artifact as designed; fresh plans were retained separately and the original artifact restored byte-for-byte. No other inherited file was changed. The evidence retains current catalogs, exact code/migration hashes, the historical chain and all failed-attempt classifications.

The populated upgrade includes real legacy attempts and monetary windows created before M1, followed by M1–M3 and audited reconciliation. Their original columns and counters remain unchanged. This supplements the encrypted custody upgrade, rather than substituting postmigration legacy-shaped inserts for preexisting data.

**PHASE 7B.3c-1 MIGRATION IMPLEMENTATION COMPLETE — READY FOR INDEPENDENT EXECUTION REVIEW**
