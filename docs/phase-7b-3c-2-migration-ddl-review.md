# Phase 7B.3c-2 — pre-execution DDL review of the migration package

Date: 2026-10-09. **Review only.** No migration file was created, nothing was applied to any project, development or production database, no runtime code or test was written, no frozen file was edited, no switch was enabled and no provider request was made.

Reviewed: [the migration review package](phase-7b-3c-2-migration-review-package.md) and [its evidence](phase-7b-3c-2-migration-review-evidence.json), against [the 3c-2 design](phase-7b-3c-2-gateway-consumption-design.md), supplement §6, §7 and §13, and the frozen `TenantAiVerificationSchema` listing. Machine-readable results are in [this review's evidence](phase-7b-3c-2-migration-ddl-review-evidence.json).

## 1. Independence and limits — read first

**This is not an independent review.** The handoff asks for one. This review was performed in the same session, by the same author, that wrote the design and the package. It is a rigorous second pass with execution in a disposable database, and it did find two defects the author had missed, but it cannot supply the independence the gate exists for. A reviewer who did not write the package should still confirm the verdict and the required changes below before anything is executed.

Other limits:

- **Rehearsal, not execution.** Every database action below ran in a private, throwaway PostgreSQL 18.6 cluster created for this review (port 55477, databases named `facturac_test_phase7b3c2_ddl_review_utf8*`, UTF-8), which was stopped afterwards. It is review rehearsal under §13 of the package, not the authorized execution.
- The 3c-1 schema boundary was built with the existing migrations. Rows were created with the frozen schema fixtures (`tests/AiVerificationMigrationFixtures.php`), which construct a promoted, active credential at SQL level. They are schema fixtures, not the verifier runtime.
- Lifecycle operations in the race rehearsals were SQL statements reproducing what `TenantAiActiveLifecycle` writes. The PHP services were not run.
- **The frozen PostgreSQL test suites were not run with the new migrations present.** That would require creating migration files, which this step forbids. Coexistence was assessed by reading the tests and by reproducing their migration selection directly (§4).
- One refusal branch was not exercised: `AI invocation receipt binding` (it needs a second approval with a different account mapping). Query plans were not assessed on realistic volumes.
- The underlying 3c-1 evidence keeps its recorded limits: partitioned SQLite and concurrency reruns and two unavailable intermediate setup-migration logs. It is not one uninterrupted clean execution.

## 2. Baseline

| Check | Result |
| --- | --- |
| 802-file inventory | 0 drift, before and after this review |
| Frozen hashes | 14/14 exact |
| Dependency and configuration hashes | 7/7 exact |
| Package hash equals the hash in its evidence | yes |
| Each listing's SHA-256, recomputed from the report text | equals the package evidence (3/3) |
| Five frozen fragments, recomputed from the frozen file by line range | present verbatim in the listings; hashes equal the package evidence (5/5) |

## 3. What the rehearsal established

### 3.1 Structure

| Step | Result |
| --- | --- |
| Function-body MD5 preconditions versus the live 3c-1 catalog | both equal (`ai3c_metadata_guard`, `ai3c_credential_guard`) |
| I1 applied in one transaction on the 3c-1 catalog | parses and installs cleanly |
| State after I1 | both checks present and `NOT VALID`; both triggers and both indexes present |
| I2 | both checks validated; no `ai3c_%` constraint left unvalidated |
| Catalog difference after I1+I2 | exactly the 2 replaced checks, 2 replaced functions, 2 new functions, 2 new triggers, 2 new indexes; nothing else among 1,819 catalog objects |
| I1 down with no inference data | catalog **byte-identical** to the pre-I1 snapshot |
| Up, down, up | identical catalog both times |
| I1 and I2 over rows created at the 3c-1 boundary (legacy attempt, promoted probe attempt, nine allocations, active credential, approval, acknowledgement) | every row byte-identical afterwards; both checks validate; down restores |
| Down with inference data retained | refused, catalog unchanged |
| I1 re-run with inference data retained | refused at the precondition, catalog unchanged |
| Down while another session holds a conflicting lock | `lock timeout` after 0.31 s, catalog unchanged |

### 3.2 Old/new equivalence of the attempt check

The old and new CHECK expressions were installed on two trigger-free copies of the attempts table and fed the same 3,688 rows: five valid base rows (legacy admitted, legacy received, probe admitted, probe promoted, inference admitted), every single-column mutation of each, and every purpose flip combined with a second mutation.

| Rows | Result |
| --- | --- |
| 2,623 rows whose purpose is not `assistant_intent` | accepted by both 699, refused by both 1,924, **disagreements 0** |
| 1,065 rows with purpose `assistant_intent` | old check accepts 0; new check accepts 200 and refuses 865 |
| Legacy or probe row with only its purpose flipped (plus any one other change) | never accepted as an inference row |

The 200 accepted inference variants differ only in columns the CHECK is not meant to bind on its own (identifiers and values that the binding and authority triggers compare), which is as designed.

### 3.3 Behaviour of the installed listings

Fifty-three cases against the real tables with every 3c-1 and new trigger active, each rolled back: **53 as specified, 0 unexpected.** They cover the five permitted terminal shapes; the refused shapes (not-sent after an authorized send, unknown-usage without one, received without usage or without authorization); write-once send mark; terminal immutability; an inference attempt writing receipt or admission fields; a probe attempt writing usage; every numeric bound at and beyond its limit; an inference attempt bound to the probe approval; admission and send authorization after settings change, connection disable, revoke and candidate store; finalization succeeding after revoke and disable; every `last_used_at` rule; and the approval shapes.

The frozen fixtures were also run **after** I1+I2 were installed and still built a promoted active credential, so the 3c-1 probe and promotion writes are accepted by the successor guards.

### 3.4 Races, two sessions, both orders

| Race | Result |
| --- | --- |
| Revoke commits first, send authorization second | authorization refused; no send mark; credential revoked |
| Send authorization commits first, revoke second | revoke waited 1.05 s for the authorization; send mark kept; use timestamp retained on the revoked row; finalization then succeeded |
| Connection disable commits first; sender takes no lock of its own on settings or connection | sender waited 1.03 s on the trigger's `FOR SHARE`, then was refused with `AI invocation authority changed` |
| Send authorization checked first and still uncommitted; disable second | disable waited 1.05 s; send mark kept |

The third row answers package question 1 with evidence: the `FOR SHARE` reads are what make the database backstop hold when a writer fails to lock those rows. No deadlock occurred in any rehearsal.

## 4. Defects found

### D1 — the proposed migration file names break the frozen upgrade test (required change)

`tests/Unit/PostgresAiVerificationUpgradeTest.php` (frozen) builds its "historical" boundary from every migration whose file name does **not** contain `_ai_verification_`, then migrates forward. The proposed names, `…_install_ai_invocation_integrity.php` and `…_validate_ai_invocation_integrity.php`, do not contain that string. The frozen test would therefore run I1 as a historical migration, before the three 3c-1 migrations exist.

Reproduced: the frozen test's exact selection was applied to an empty database, then I1 was applied. It failed with `column "contract_version" does not exist`. Every test in that frozen file would fail in its `beforeEach`.

The package's §6 statement that no frozen file needs to change is therefore **false with the proposed names**. It becomes true if both file names contain `_ai_verification_`.

Two accepted, non-frozen tests select migrations with the glob `*_ai_verification_*.php` (`tests/Unit/PostgresTenantAiStorageTest.php`, `tests/Feature/TenantAiStorageTest.php`) and reverse and re-apply them in name order. With the corrected names they reverse the successor first, which is the correct order. This was established by reading them, not by running them.

### D2 — reversing the 3c-1 migrations without first reversing the successor silently breaks the legacy path (required change)

`tests/Unit/PostgresAssistantProviderTest.php` (accepted, not frozen) reverses an explicit list of exactly the three 3c-1 migrations. With I1 installed that is an out-of-order reversal.

Reproduced on an empty database with I1+I2 installed: all three 3c-1 downs **succeed**, and leave both `ainv1_` triggers and both functions in place on tables whose gateway columns are gone. A legacy attempt insert then fails with `record "new" has no field "contract_version"`. The accepted VAP path would be broken, with no error at the moment of the mistake.

The migrator's own rollback order never does this, but a direct invocation can, and one existing test does.

A remedy was rehearsed: give the new attempt trigger a `WHEN (NEW.contract_version='gateway_v1' AND NEW.purpose='assistant_intent')` clause. With it, the trigger depends on those columns, so the 3c-1 metadata down refuses with `cannot drop column purpose … because other objects depend on it`, the columns survive, and the legacy insert still commits. It is also the better trigger design: legacy and probe rows no longer queue a deferred event only to return immediately. The 53-case matrix and the races were **not** re-run against that variant; the revised package must do so.

Even with that remedy the 3c-1 integrity down has already run by the time the metadata down refuses, so the result is fail-safe (legacy works, gateway branches closed, operator alerted), not atomic. The ordering rule must be stated, and the explicit-list test must be updated to reverse the successor first. That is an edit to a non-frozen test that adds steps and removes no assertion.

## 5. Other findings

| # | Finding | Disposition |
| --- | --- | --- |
| N1 | Several tests call a migration's `up()` and `down()` directly, outside the migrator's transaction. I1 is only atomic there if it runs as one statement batch. | State in the package that I1 executes as a single `DB::unprepared` call. |
| N2 | Package test MP-21 says "all frozen tests pass unmodified" without naming the four tests that select migrations by file name. | Name them and include the two glob-based and the explicit-list tests. |
| N3 | `AI invocation receipt binding` was not exercised here. | Remains for MP-15; not a defect. |
| N4 | The refusal text `Customer inference unavailable` is produced by four different rules. | Acceptable; noted for whoever reads logs. |

## 6. Answers to the package's six questions

1. **Late `FOR SHARE` in the deferred trigger — keep.** The third race shows it is what makes the backstop sound; no cycle was found or observed; the 250 ms lock timeout bounds it.
2. **Exact equality of use timestamp and send instant — keep.** It fails closed on a backwards clock step.
3. **Permanent `verification_outcome='not_observed'` on inference rows — keep.** The frozen insert guard stayed untouched and the rehearsal confirmed it refuses any other value at insert.
4. **Settings revision and entitlement equality in the trigger — keep.** Rehearsed: it refuses admission and send authorization after a settings change.
5. **Reused refusal text — keep.**
6. **Account circuit needs no DDL — confirmed.** The application write is already governed by the existing control guard, which requires a revision increment.

## 7. Required changes — finite list

1. **Rename both migration files so each base name contains `_ai_verification_`** (for example `2026_10_10_010000_install_ai_verification_invocation_successor.php` and `2026_10_10_010100_validate_ai_verification_invocation_successor.php`). Update every mention in the package and the design's handoff.
2. **Add the `WHEN` clause of D2 to `ainv1_intent_authority`.** Keep the function's own early return.
3. **Correct package §6**: withdraw the claim that holds only by inspection, name the four file-name-selecting tests, and record the necessary edit to the non-frozen `PostgresAssistantProviderTest` explicit list as a required, non-weakening test change. Add it to the implementation-file map and extend MP-21 to all four.
4. **State in package §8** that the 3c-1 migrations must never be reversed while the successor is installed, what happens if they are (after change 2: the metadata down refuses, legacy continues to work), and that I1 executes as one statement batch.
5. **Regenerate the listings, hashes and evidence, and re-rehearse the revised listings in a disposable database**: parse and install, the 53-case matrix, the four races, the out-of-order down, and up/down/up catalog identity.

No other change is required. Nothing in the constraint branches, the two successor guards, the use-timestamp trigger, the indexes, the down, the lock levels or the ordering needs to change.

## 8. Disposition

The DDL itself behaved as specified in every rehearsed case. The package is not approvable as written because of D1 and D2: one would fail a frozen test, the other can silently break the accepted legacy path. Both have small, rehearsed remedies. Because this review was not independent (§1), an approval after the changes should come from a reviewer who did not write the package.

PHASE 7B.3c-2 MIGRATION PACKAGE — CHANGES REQUIRED
