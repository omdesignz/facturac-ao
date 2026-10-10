# Phase 7B.3c-2 — independent pre-execution DDL review of migration package revision 2

Date: 2026-10-09. **Review only.** I did not write the design, the package or the first review. No migration file, runtime code or test was created in the repository, no existing repository file was edited, no switch was enabled, no provider or network request was made, and nothing was applied to a project, development or shared database.

Reviewed: [the migration review package, revision 2](phase-7b-3c-2-migration-review-package.md) and [its evidence](phase-7b-3c-2-migration-review-evidence.json), [the first review](phase-7b-3c-2-migration-ddl-review.md), [the 3c-2 design](phase-7b-3c-2-gateway-consumption-design.md) §8, §9, §12 and §17, supplement §6, §7 and §13, and the frozen `app/Fiscal/TenantAiVerificationSchema.php`. Machine-readable results are in [this review's evidence](phase-7b-3c-2-migration-ddl-review-r2-evidence.json).

## 1. Result in brief

The three SQL listings behave as the package says in every case I ran, including the ones the package did not cover. The frozen tests pass unmodified with both migrations present. I found no reason to change a byte of the listings.

The package is nevertheless not approvable as written, because one of its safety statements about the listings is false and I reproduced the counter-example: the deferred trigger's late `FOR SHARE` reads **can** complete a lock cycle, and when they do, the transaction that PostgreSQL aborts can be an emergency revoke (§5, D1). This only happens when the inference writer has not taken the canonical locks, which the design forbids, so it is a defect of the package's analysis and test plan, not of the DDL. A second, smaller defect is an incomplete lock matrix (D2). Both are corrected by text. Three required changes are listed in §9.

## 2. Scope and limits

- **Rehearsal, not execution.** Every database action ran in a private PostgreSQL 18.6 cluster that I created for this review (`initdb`, port 55488, loopback only, UTF-8, locale C) and stopped afterwards. All databases were named `facturac_test_…` and created from `template0`. Before every `artisan` call I proved the target (driver, database name, port) and refused otherwise. This is review rehearsal. It is not the authorized execution.
- **Independence.** I wrote my own scripts and did not read or reuse the package author's scratch material. I treated the package's recorded results as claims and repeated them.
- **Draft migration files existed only in a scratch copy** of the repository under my scratch directory. They embed the three listings byte-for-byte (hashes checked at load).
- **Two deviations inside that scratch copy, disclosed.** The scratch directory lives under `/private/tmp`, which is world-writable. `TenantAiKeyFile` and `TenantAiVerificationReceiptKeyFile` refuse any key file with a group- or world-writable ancestor directory, so in the copy 1 frozen upgrade test and 54 tests of the verifier, lifecycle and quota files failed *before* any draft migration was added. I added one identical exemption for exactly `/private/tmp` to one line of each of those two files, in the copy only. Every frozen file in the copy stayed byte-identical to the checkpoint. With that exemption the control runs (no drafts) pass, which is what makes the with-drafts runs meaningful.
- **PHP 8.5.10** (the `php` on PATH) was used, not the PHP 8.4 binary the checkpoint used. One SQLite test fails for that reason with and without the drafts (§4.9).
- **Rows were built at SQL level.** The tenant comes from the frozen fixtures (`tests/AiVerificationMigrationFixtures.php`); inference attempts, approvals and lifecycle writes are my SQL, reproducing what the design's writer and `TenantAiActiveLifecycle` write. The inference runtime does not exist yet, so no PHP inference path was run.
- The underlying 3c-1 evidence keeps its recorded limits. I did not re-audit it.

## 3. Baseline

| Check | Result |
| --- | --- |
| 802 files in `source_inventory` | all present, 0 drift, before and after this review |
| 14 `frozen_hashes` | 14/14 exact, before and after |
| 7 dependency and configuration hashes | 7/7 exact |
| Package SHA-256 equals `report_sha256` in its evidence | yes, `4c143beb…f7ba97` |
| Design SHA-256 as the package states | yes, `bb5dfe36…4d77fb` |
| Git HEAD | `4e77285…`, working tree status identical at start and end apart from my two files |

Recomputed from the frozen PHP file by line range, without using the package's generator:

| Fragment | Lines | SHA-256 | Bytes | In I1 up | In I1 down |
| --- | --- | --- | --- | --- | --- |
| attempt check: header, legacy and probe branches | 379–434 | `96d35ca9…a036dd` | 7,256 | verbatim | verbatim |
| approval check: legacy and probe branches | 336–345 | `285345b2…8b7361` | 887 | verbatim | verbatim |
| attempt check, whole statement | 378–435 | `763e7c4e…de4acd` | 7,387 | – | verbatim |
| approval check, whole statement | 335–346 | `24f7ef37…66c9f2` | 1,002 | – | verbatim |
| gateway-attempt mutable list | 462–464 | `8fc5c1c2…6e4c71` | 338 | verbatim | verbatim |

All five equal the package's values. Reversing the one stated edit in each successor function reproduces the frozen function text exactly, for both functions. The two functions restored by the down equal the frozen text exactly. The MD5 values used as I1 preconditions equal the MD5 of the frozen bodies and, by execution, the MD5 of `prosrc` in a catalog built by the existing migrations.

The listing hashes in the package are hashes of each listing **without** its final newline (I1 up `d56daa0c…`, I2 up `48268c0a…`, I1 down `be38814f…`); I reproduced all three. The stated line count of I1 up (314) is one more than its newline count. This matters only to whoever writes the later schema contract test, because the frozen class appends a newline to each listing.

## 4. What I rehearsed

### 4.1 Structure and identity

| Rehearsed | Result |
| --- | --- |
| I1 as one statement batch with no surrounding transaction | installs; both checks `NOT VALID`; `lock_timeout` back to 0 afterwards, so `SET LOCAL` is confined to the batch |
| I2 | both checks validated; no unvalidated constraint of any name remains |
| Catalog after I1+I2 against the 3c-1 catalog (my own catalog query and `pg_dump -s`) | 2 checks and 2 functions changed; 2 functions, 2 triggers (with their 2 `pg_constraint` rows) and 2 indexes added; nothing else |
| `WHEN` clause stored on `ainv1_intent_authority` | yes |
| I1 down, from the validated state and from the I1-only state | catalog identical to the 3c-1 catalog by both methods |
| Up, down, up; down twice; I2 twice; I2 without I1 | as expected; the second down fails on the missing trigger and changes nothing |
| I1 a second time, before I2 and after I2 | refused at the precondition both times; catalog unchanged |
| The same through the Laravel migrator: `migrate`, `migrate:rollback --step=2`, `--step=1`, `--step=5`, `migrate` again | every step succeeds; catalogs equal the corresponding psql results |

### 4.2 Populated data

Rows created at the 3c-1 boundary by the frozen fixtures (two legacy attempts, one promoted probe attempt, nine allocations, an active verified credential, approval, acknowledgement, 14 windows). A per-table digest of every row in the database was identical before I1, after I1, after I2 and after the down. Between I1 and I2 an invalid legacy row was refused and a valid one accepted, so the unvalidated checks do enforce new rows.

### 4.3 Behaviour of the installed listings

223 cases of my own against the real tables with every trigger active, each in a transaction that forced deferred constraints and rolled back. **223 as I predicted from reading the listings, 0 otherwise.** (Two cases first failed because my harness wrote a decreasing `updated_at`, and five because of an ambiguous column in my own SQL; I corrected the harness and re-ran the whole matrix.)

- **Shape (138):** each of 34 required fields null; each of 15 forbidden fields set; every numeric bound at and beyond its limit; the five permitted terminal shapes and the refused combinations of state, outcome, send mark and usage; write-once send mark; terminal immutability; an inference attempt writing receipt, observation, promotion or admission fields; purpose and contract flips; deletion; probe attempts still refusing usage writes and still accepting the frozen failure finalization; legacy rows unchanged.
- **Authority, use and approvals (85):** for seven authority changes (settings flag, entitlement, mode, cleared selection, connection disable, connection revision, full revoke) admission and send authorization are refused, while not-sent finalization, received finalization and unknown-usage recovery all still succeed; stale attempt-side snapshots; every `last_used_at` rule; approval shapes; receipt binding.

### 4.4 Old and new checks compared

I turned the frozen and the successor CHECK expressions into two functions and evaluated both on the same synthetic rows: six valid base rows (legacy admitted and received, probe promoted, inference admitted, not-sent and received), 905 single-column mutations over all 73 columns, each combined with nine purpose and contract flips.

| Rows | Result |
| --- | --- |
| 29,187 attempt rows whose purpose is not `assistant_intent` | accepted by both 3,592; refused by both 25,595; **disagreements 0** |
| 18,315 attempt rows with purpose `assistant_intent` | frozen check accepts 0; successor accepts 4,288 |
| Inference rows accepted by the successor that started as a legacy or probe row | 0 |
| 304,708 approval rows whose purpose is not `assistant_intent` (single and pairwise mutations) | accepted by both 42,421; refused by both 262,287; **disagreements 0** |

Together with the byte identity of §3 this confirms that no legacy or `connection_probe` row changes between accepted and refused.

### 4.5 The branch the package did not exercise: `AI invocation receipt binding`

Reached three ways, each with a matching, valid approval so that the earlier binding check passes and only the new trigger can refuse:

| Mismatch against the credential's promoted verification attempt | Result |
| --- | --- |
| another account-mapping digest | `AI invocation receipt binding` |
| another legal entity of the same workspace | `AI invocation receipt binding` |
| settings select another customer profile | `AI invocation receipt binding` |
| control: same statements without the mismatch | accepted |

### 4.6 Lifecycle interplay

- **Rotation while an attempt is admitted** (schema-level rotation as the frozen rotate fixture writes it, committed): send authorization for the old attempt refused (`Customer inference unavailable`); the same with the use written on the *new* credential refused (`AI invocation authority changed`); not-sent finalization accepted; a new admission still naming the replaced credential refused; a new admission bound to the promoted candidate and a fresh approval accepted, and so was its send authorization.
- **Rotation after a recorded use:** the replaced credential keeps its use instant; the candidate starts with none; received finalization of the earlier attempt accepted; a second attempt admitted before the rotation cannot be authorized; a use write on the replaced row refused.
- **After a use:** clearing `creator_user_id` (what `ON DELETE SET NULL` writes) accepted; a rewrap accepted; an attempt admitted before the rewrap can no longer be authorized (wrap revision differs) and finalizes as not sent.

### 4.7 Two-session races

| Race | Result |
| --- | --- |
| Both writers take the root first; revoke first, send second | send refused; no send mark; credential revoked |
| Both take the root first; send first, revoke second | both commit; send mark and use instant kept on the revoked row |
| Same, sender holds the root 0.6 s, revoke uses the 250 ms lock timeout | revoke fails with lock timeout and changes nothing |
| Sender takes no locks; connection disable in progress commits first | sender waited 0.5 s, then `AI invocation authority changed` |
| Sender takes no locks, already checked, uncommitted; disable second | disable waited, both commit |
| **Sender takes no locks, has written, commits late; revoke arrives in between** | **`deadlock detected`, the revoke is aborted after 1.03 s; the send commits** |
| **Same with the 250 ms lock timeout both writers use** | **revoke aborted by lock timeout at 0.31 s; the send commits** |
| **Admission takes no locks; revoke arrives before it commits** | **`deadlock detected`, the revoke is aborted after 1.04 s; the admission commits** |
| Two senders for one credential, both locking, clock read after the locks | both commit, increasing instants |
| Two senders, one read its instant before waiting for the credential lock | the one with the earlier instant is refused (`Customer inference unavailable`) |

The three rows in bold are defect D1.

### 4.8 Down, refusal, ordering, locks

| Rehearsed | Result |
| --- | --- |
| Down with inference attempts and an approval retained; with only an approval; with only a *revoked* inference approval | refused (`AI invocation downgrade refused: retained data`); catalog unchanged |
| I1 again with inference data | refused; catalog unchanged |
| I1 preconditions one at a time: approval check not validated; credential guard body different by one space; a 3c-1 trigger missing; attempt check missing | each refused; catalog unchanged |
| Down racing a transaction that commits an inference approval *after* the down's own check has passed | down fails at its `VALIDATE` (`violated by some row`); catalog unchanged. The check-then-act window is closed by the validation, not by the preflight |
| Out-of-order reversal by direct `down()` calls (3c-1 validation, integrity, metadata) with the successor installed | integrity down runs; metadata down refuses on the column dependency; `contract_version` and `purpose` survive; a legacy insert and a legacy finalization commit; a gateway insert is refused by the restored stage check |
| I1 down directly in that state | fails on the missing approval check; changes nothing |
| Recovery: 3c-1 integrity and validation up, then I1 down | catalog identical to the clean 3c-1 catalog |
| Correct-order round trip by direct calls | every step succeeds; `pg_dump -s` identical to the installed schema |
| I1 up as a single batch while another session holds a lock on the attempts table, or a writer lock on the credentials table | lock timeout after 0.31 s; catalog unchanged, including the functions replaced earlier in the same batch |
| I2 against a conflicting lock on the second table | lock timeout; **both** checks stay unvalidated, so I2 is atomic too |
| I2 while writers hold ordinary row-exclusive locks | not blocked |
| Down against a conflicting lock | lock timeout; catalog unchanged |
| Both partial indexes on 60,000 synthetic inference attempts, generic plans as inside PL/pgSQL | the use check uses `ainv1_intent_use_idx`; the recovery scan uses `ainv1_intent_recovery_idx` |

Locks actually held at the end of each listing (`pg_locks`):

| Listing | `tenant_ai_credentials` | `assistant_provider_tenants` | `assistant_provider_attempts` |
| --- | --- | --- | --- |
| I1 up | access share, share row exclusive | access share, access exclusive | access share, share row exclusive, share, access exclusive |
| I2 | – | share update exclusive | share update exclusive |
| I1 down | access share, **access exclusive** | access share, share update exclusive, access exclusive | access share, share update exclusive, access exclusive |

### 4.9 Existing tests with both draft migrations present (scratch copy)

| Test file | Frozen | Without drafts | With drafts |
| --- | --- | --- | --- |
| `tests/Unit/PostgresAiVerificationUpgradeTest.php` | yes | 6/6 | **6/6** |
| `tests/Unit/PostgresAiVerificationMigrationTest.php` | yes | 166/166 | **166/166** |
| `tests/Unit/PostgresAiVerificationConcurrencyTest.php` | yes | 1/1 | **1/1** |
| `tests/Unit/AiVerificationSchemaContractTest.php` | yes | 1 passed, 1 skipped (SQLite-only case) | same |
| `tests/Unit/PostgresTenantAiStorageTest.php` | no | 6/6 | 6/6 |
| `tests/Feature/TenantAiStorageTest.php` on PostgreSQL | no | 49/49 | 49/49 |
| `tests/Unit/PostgresAssistantProviderTest.php` | no | 19/19 | **18/19**; with the one edit, 19/19 |
| `tests/Unit/PostgresTenantAiLifecycleTest.php`, `…VerifierTest.php`, `…VerificationQuotaTest.php` | no | 102/102 | 102/102 |
| `tests/Unit/PostgresAssistantTest.php` | no | not run | 33/33 |
| The other seven `tests/Unit/Postgres*.php` files | no | not run | 264/264 |
| Whole default SQLite suite | – | the three failing files only | 2,967 tests: 2,367 passed, 597 skipped, 3 not passed |

The three SQLite non-passes are identical without the drafts and are properties of my environment: `node` rejecting `--input-type=module`, a PHP 8.5 deprecation notice in captured output, and a `storage/app/public` directory I did not copy.

## 5. Defects

### D1 — the late `FOR SHARE` reads can complete a lock cycle; the package says they cannot

The package states (§3.6) that if a writer failed to hold its row locks, "a concurrent lifecycle commit either waits for this transaction or is seen by it", (§5.2) that the reads are "on rows the design's writers already hold", and (§12, answer 1) "No cycle was found or observed."

There is a third outcome. `ainv1_intent_authority` runs at commit and asks for the settings row first. By then a writer that took no locks of its own already holds later locks: the attempt row, and on the credential either its own row lock from the use write or, at admission, just the foreign-key key-share. Every lifecycle operation in `TenantAiActiveLifecycle::locked()` takes the root, then settings, then connection, then the credentials `FOR UPDATE`. So the lifecycle writer holds settings and waits for the credential, while the inference writer holds the credential and waits for settings.

Reproduction (database with I1+I2 installed, the fixture tenant made selectable, one inference attempt admitted and committed; two `psql` sessions):

```sql
-- Session A: a writer that takes no locks of its own
BEGIN;
UPDATE assistant_provider_attempts SET send_authorized_at = :t WHERE id = :attempt;      -- :t from clock_timestamp()
UPDATE tenant_ai_credentials SET last_used_at = :t, updated_at = greatest(updated_at, :t) WHERE id = :credential;
SELECT pg_sleep(0.5);
COMMIT;   -- ainv1_intent_authority runs here and asks for tenant_ai_settings FOR SHARE

-- Session B, started 0.15 s after A: what a revoke does
BEGIN;
SELECT id FROM ai_gateway_controls WHERE kind='global' AND subject_key='root' AND deployment_id = :deployment FOR UPDATE;
SELECT workspace_id FROM tenant_ai_settings FOR UPDATE;
SELECT id FROM tenant_ai_connections FOR UPDATE;
SELECT id FROM tenant_ai_credentials WHERE state IN ('pending','active') ORDER BY id FOR UPDATE;   -- waits for A
-- revoke writes, SET CONSTRAINTS ALL IMMEDIATE, COMMIT
```

Observed: B fails with `ERROR: deadlock detected` after 1.03 s and A commits. With `SET LOCAL lock_timeout='250ms'` in both sessions, B fails with a lock timeout after 0.31 s and A commits. Replacing A's two updates by a bare admission (insert of the attempt and its nine allocations) gives the same result: B `deadlock detected` after 1.04 s, A commits.

What this does and does not mean:

- It is fail-safe. In no run was a send authorized after a lifecycle change had committed. The send that commits was authorized before the revoke could commit, which the design already accepts.
- The transaction that loses can be the **emergency revoke**, which then has changed nothing and must be retried.
- It needs a writer that skipped the canonical locks. I read every existing writer of these rows (`TenantAiStorage::configure`, `TenantAiLifecycle`, `TenantAiActiveLifecycle`, `TenantAiCredentialVerifier::lockCaptured`, `TenantAiVerificationRecovery`, `TenantAiLegacyAccounting::lock`, `DeleteUserAccount`): each takes the deployment root row first. The design's inference writer does too (§7 step 4, §9.1). With both sides taking the root first I observed no cycle, and by the lock order there cannot be one.
- So the trigger remains a correct backstop for the case the package rehearsed (a settings-only change against a non-locking writer). The listing does not have to change. But the package's statement of what the backstop does is wrong, for a non-locking writer the trigger does exactly what the governing rule forbids, acquiring an earlier lock class late (design §9.1, supplement §7), and proposed test MP-14 ("with and without the writer holding its row locks", "in both commit orders") has no stated expected result for the interleaving above.

### D2 — the lock matrix is incomplete and one sentence about lock order is inaccurate

Package §5.1 lists the locks of I1 up and I2 only. Observed (§4.8): the **down** takes `ACCESS EXCLUSIVE` on `tenant_ai_credentials` (from `DROP TRIGGER`), which is stronger than anything the up takes on that table and blocks every read of credentials for the duration. §3 and the I1 header also say table locks are taken in canonical class order, credentials then approvals then attempts. The first locks I1 takes are the preflight's `ACCESS SHARE` locks on attempts, then approvals, then credentials, held to the end of the transaction and upgraded later. With admission closed and a 250 ms lock timeout this is harmless, and I reproduced the timeout leaving the catalog unchanged. It is still the document an operator plans the maintenance window from, and it should say what happens.

## 6. Findings that are not defects

| # | Finding | How established |
| --- | --- | --- |
| N1 | **A fail-fast variant removes the cycle of D1.** With `FOR SHARE NOWAIT` on the three reads (a variant I installed in a separate disposable database; it is **not** the package's listing), the non-locking writer is refused at once (`could not obtain lock on row`) and the revoke commits, in both interleavings of D1; a locking sender, a pre-checked sender against a disable, and two overlapping non-locking senders behave as before. The cost is that a non-locking writer is also refused under harmless contention. It is an option for the author, not a requirement; choosing it changes I1's hash and needs the matrix and races re-run. | executed, 5 races |
| N2 | **The use pair cannot be written once constraints are immediate.** After `SET CONSTRAINTS ALL IMMEDIATE`, the attempt write fails with `AI invocation use unrecorded` and the reverse order with `AI credential use without authorized attempt`. Transaction 3 must issue both writes before it forces constraints, as design §8 orders them. The frozen fixture `verificationMigrationActiveFixture()` leaves its transaction in immediate mode, so any later test that builds a tenant with it and performs the send writes in the same transaction must first issue `SET CONSTRAINTS ALL DEFERRED`. | executed |
| N3 | **One send authorization per credential per transaction.** Two attempts authorized at different instants in one transaction are refused; in sequence, with increasing instants, they are accepted. | executed |
| N4 | **The send instant must be read after the credential row lock is held.** A sender that read the clock first and then waited is refused when a later instant commits ahead of it. Design §8 already takes the locks first. | executed |
| N5 | **Nothing ties the instant to real time.** A send instant in the year 2100 is accepted, and from then on every present-time send for that credential is refused until it is rotated. A CHECK cannot compare with the clock; this is a rule for the writer (primary `clock_timestamp()` only). The package already accepts failing closed on a backwards clock step. | executed |
| N6 | **The pair of checks does not prove the credential row was written.** A second attempt, admitted earlier, can be authorized with a send instant equal to the credential's existing use instant without any credential write, and two attempts can share one instant in one transaction. Current authority is still checked at that commit, so nothing is authorized against changed authority; the effect is a backdated send mark and a use instant that understates the latest use. The package's sentences in §3.6 and §3.7 are true as written. | executed |
| N7 | **Expiry is not a database rule**: admission and send are accepted with a credential `expires_at` in the past and with an expired owner approval. The package says so for approvals; it holds for the credential and for profile validity at send time as well. | executed |
| N8 | **There is a fifth test that selects AI migrations by file name**: the frozen `AiVerificationSchemaContractTest` globs `2026_10_09_020*verification*.php` in its SQLite case. The new names fall outside it, so it is unaffected, and no existing test asserts that the two new files are skipped on SQLite. MP-24 is the only place that will. | read; SQLite suite executed |
| N9 | **Receipt binding to one legal entity** makes customer inference available only in the entity under which the credential was verified. Workspaces can have several entities. This is design §5.2, stated there in terms. | read and executed |
| N10 | A **revoked** inference approval still blocks the down. Consistent with "retained evidence". | executed |
| N11 | After the correct-order round trip my catalog query differs from the installed one in **physical column numbers** only, because 3c-1's own down drops columns that its up re-adds. `pg_dump -s` is identical. The package's "byte-identical" for that row depends on the snapshot method. | executed |
| N12 | `ainv1_credential_use` has no `WHEN` clause, so every credential update queues a deferred call that returns at once. Harmless. `WHEN (NEW.last_used_at IS DISTINCT FROM OLD.last_used_at)` would avoid it. Not required. | read |
| N13 | The inference branch hard-codes the legacy envelope (output ≤ 1,024, input ≤ 1,000,000, estimate 1,024–8,192, bytes 1–7,168). The estimate and byte bounds are consistent with `AssistantProviderProfile::estimate` (body + 1,024 ≤ 8,192). A later customer profile with another envelope needs DDL. | read |
| N14 | All existing runtime queries over gateway attempts filter on `purpose='connection_probe'` (`TenantAiVerificationRecovery`, `…Quota`, `…Admission`, `…PolicyResolver`), so inference rows will not be picked up by the probe recovery or quota paths. | read |

## 7. The package's §6 claims about existing tests

Confirmed by running them, not only by reading:

- **Frozen `PostgresAiVerificationUpgradeTest`:** its historical selection excludes both new files by name; they run after the three 3c-1 migrations; 6/6 pass, including both `ai3c_% … NOT convalidated = 0` assertions. The two tests that call the schema listings directly never apply I1 and are unaffected.
- **`PostgresTenantAiStorageTest` and `Feature/TenantAiStorageTest` on PostgreSQL:** the glob sorts the successor last, so it is reversed first and re-applied last; both pass unmodified (direct `up()`/`down()` calls outside the migrator, and inside a `RefreshDatabase` transaction respectively).
- **`PostgresAssistantProviderTest`:** unmodified, its round-trip test fails at the 3c-1 metadata down with `cannot drop column purpose … trigger ainv1_intent_authority … depends on`. Appending the two new file names to its explicit list makes all 19 pass. **The one required non-frozen test edit is correctly identified**, and it adds two list entries and removes nothing.
- **No other existing test breaks.** All 16 `tests/Unit/Postgres*.php` files, the PostgreSQL run of `Feature/TenantAiStorageTest`, and the whole SQLite suite were run with the drafts present; the only non-passes are the explicit-list test above and three environmental SQLite failures that occur without the drafts too. Feature tests other than `TenantAiStorageTest` were run on SQLite only.

## 8. Answers to the handoff questions

| | Question | Answer |
| --- | --- | --- |
| a | Legacy and probe branches and both function bodies reproduced exactly | **Confirmed**, by my own hashing and by execution (§3, §4.4). |
| b | Inference attempt and approval branches: complete, disjoint, null-safe | **Confirmed.** The three branches are disjoint on contract and purpose; every comparison sits under `IS TRUE`; 138 shape cases and the differential found no gap. |
| c | The two successor guards | **Confirmed** (§4.3, §4.6). |
| d | The two constraint triggers, the `WHEN` clause, the `FOR SHARE` reads | Triggers and `WHEN` clause **confirmed**. The claim about the `FOR SHARE` reads is **refuted in part** (D1). |
| e | The down, its refusal, the ordering rule of §8 | **Confirmed**, including a race the package did not consider (§4.8). |
| f | Lock levels, ordering, single-batch execution, closed-admission requirement | Single-batch atomicity **confirmed** for I1 up, I2 and the down. Lock levels of the up **confirmed**; the matrix is **incomplete** (D2). |
| g | The four file-name-selecting tests and the one non-frozen edit | **Confirmed** by execution; there is a fifth, unaffected (N8). |
| h | The rehearsal results of §11.2 | **Repeated and confirmed**, with the reading of N11 for the round-trip row. I did not reproduce the author's exact counts (53 cases, 3,688 rows, 1,819 objects) because I used my own, larger sets. |

On the package's six questions: I agree with 2 to 6. On question 1 I agree with keeping the reads but not with the stated reason.

## 9. Required changes

1. **Correct the statement about the late `FOR SHARE` reads** in §3.6, §5.2 and §12 answer 1. Say that a writer which has not taken the root and the canonical row locks can form a lock cycle with any lifecycle operation; that PostgreSQL resolves it by lock timeout or deadlock detection; that either transaction can be the one aborted, including a revoke or disable, which then changes nothing and must be retried; and that no send is authorized after a committed lifecycle change in any case. State as an invariant for the implementation that every transaction that inserts an inference attempt or sets a send mark holds the deployment root and the settings, connection and credential row locks before its first write. *Alternative:* adopt the fail-fast reads of N1 instead, in which case I1 changes, its hash changes, and the matrix and races must be re-run on the new listing.
2. **Give MP-14 expected results** for the variants "without the writer holding its row locks", naming the interleavings of D1 for both admission and send authorization. With a non-locking writer the test must accept abort of either party and must assert only what is guaranteed: no send mark after a committed lifecycle change, and no partial write by the aborted party. With a locking writer it must assert that the later transaction either completes or fails on the 250 ms lock timeout having changed nothing.
3. **Complete §5.1**: add the locks of the I1 down, including `ACCESS EXCLUSIVE` on `tenant_ai_credentials`, and the preflight's `ACCESS SHARE` locks; reword the "canonical class order" sentence in §3 and in the I1 header comment so that it describes the exclusive locks only. (Changing the header comment changes I1's hash; if the author prefers to keep the hash, correct §3 and §5.1 only and leave the comment.)

No other change is required. In particular, if items 1 to 3 are done as text only, the three listings keep their SHA-256 values, every result in §4 continues to apply to them, and the follow-up review can be limited to the changed text and a re-check of those three hashes.

Carried into the later implementation run without any change to the package: N2, N3, N4 and N5 as conditions on transaction 3 and on its tests.

## 10. What was verified, and how

**By execution in my disposable cluster:** everything in §4; the MD5 preconditions against a live 3c-1 catalog; the three listing hashes embedded in the draft files; D1 and the lock observations of D2; N1 to N7, N10 and N11.

**By reading only:** that the design's inference writer and every existing writer take the root first (D1); `ai3c_check_attempt` for probe-only assumptions (I found none, and the executed admissions, finalizations after authority changes and binding refusals are consistent with that); N8, N9, N12, N13, N14; that the stated `WHEN` clause, not something else, is what makes the 3c-1 metadata down refuse (the error names the trigger).

**Not verified:**

- The authorized execution, and any behaviour on a populated production-like database. My volume check was 60,000 synthetic rows for index choice only.
- Any PHP inference writer, because none exists. Every statement about "the design's writer" rests on the design text.
- The proposed tests MP-01 to MP-27 as tests. I exercised the same ground with my own scripts; that does not replace them.
- Receipt MAC verification after I1+I2 (I compared row bytes, which include the MAC, but did not run the verifier over them).
- PHP 8.4, and the unpatched key-file ancestor check, for the test runs in the scratch copy (§2).
- Feature tests other than `TenantAiStorageTest` on PostgreSQL.
- Behaviour under `REPEATABLE READ` or `SERIALIZABLE`; the existing writers refuse anything but `READ COMMITTED`.

## 11. Decision

The DDL is sound in every case I could construct, and no frozen test fails. One statement the package makes about the locking behaviour of that DDL is false, with a reproduced counter-example in which an emergency revoke is the transaction that gets aborted, and the proposed race test has no expected result for it. Three text corrections are required before the package can serve as the approved specification. No listing byte needs to change.

PHASE 7B.3c-2 MIGRATION PACKAGE — CHANGES REQUIRED

## 12. Follow-up review of revision 3

Date: 2026-10-10. Sections 1 to 11 above are the record of revision 2 and are unchanged, including the verdict line that closes section 11. This section reviews revision 3 of the package (SHA-256 `4da1f707…c9a11b`) against the three required changes of §9. Review only: I read text and recomputed hashes. No database was started, nothing in the repository was edited except this report and its evidence file, and nothing was committed.

### 12.1 Result in brief

All three required changes are closed in substance, and the three listings are byte-identical to the ones I rehearsed, so every result in §4 carries over. Revision 3 is still not approvable as written, because it introduced two statements that are wrong and a summary of this review that says more than the review established. One is a factual claim about the existing code inside the paragraph that closes item 1. The other describes this review as more independent than it was. All are fixed by editing sentences. No listing byte needs to change.

### 12.2 Baseline and listing identity

| Check | Result |
| --- | --- |
| 802-file inventory, 14 frozen hashes, 7 dependency and configuration hashes | 0 drift, 14/14, 7/7 |
| Package SHA-256 equals `report_sha256` in its evidence | yes, `4da1f707a5f0c3967bc70624144a9e05e165fdd232d5d69bc23631e7eac9a11b` |
| I1 up, extracted from revision 3 | `d56daa0c…2b02d79`, byte-identical to the text I rehearsed |
| I2 up | `48268c0a…771b64`, byte-identical |
| I1 down | `be38814f…c0d84b`, byte-identical |
| Listing hashes recorded in the revision 3 evidence | equal to the three above |
| Hash of this report recorded in the revision 3 evidence | equal to the hash this report had before this section was added (`709c006e…7f4844`) |
| Git HEAD and working tree | `4e77285…`; status unchanged since the end of the first review |

I compared revision 3 with my retained copy of revision 2 line by line. Every difference is in prose, in tables, or in the revision history. I did not re-run any rehearsal, because no listing changed.

### 12.3 The three required changes

| # | Required change | Result | Why |
| --- | --- | --- | --- |
| 1 | Correct the statement about the late `FOR SHARE` reads in §3.6, §5.2 and §12; state the writer-lock invariant | **Closed in substance; one new wrong statement inside it (F1)** | §3.6 now says the reads are not safe for a writer that skipped the canonical locks, describes the cycle as I reproduced it for send authorization and for bare admission, says either transaction can be aborted including a revoke or disable, says it is fail-safe, and states the invariant. §5.2 no longer says "no change to the canonical order" without qualification. §12 answer 1 gives the corrected reason. The reason given for not adopting the fail-fast variant is accurate: in my variant run the revoke still waited on the credential row before it committed. |
| 2 | Give MP-14 expected results for writers with and without their locks | **Closed** | MP-14 now covers admission as well as send authorization, states the result with the locks (completes, or fails on the lock timeout having changed nothing, and no deadlock) and without them (abort of either party accepted; assert only no send mark after a committed lifecycle change and no partial write by the aborted party). This is what §9 item 2 asked for. |
| 3 | Complete §5.1; reword the lock-order sentence | **Closed** | §5.1 lists the preflight's `ACCESS SHARE` locks and the down's locks, including `ACCESS EXCLUSIVE` on `tenant_ai_credentials`, and extends the closed-admission requirement to the down. Each new row agrees with what I read from `pg_locks` (§4.8). §3 now says only the exclusive locks follow the canonical order. Leaving the I1 header comment unchanged to keep the hash is what §9 item 3 allowed, and §3 tells the reader how to read it. |

Two remarks on item 1 so the record does not overstate what I saw. "Either transaction can be aborted" is the wording I required; in my three runs the aborted transaction was the revoke each time, and the sender being the one aborted is a conclusion from how lock timeouts and the deadlock detector choose, not an observation. The cycle with a disable in place of a revoke was not run; it follows from both using the same locking routine.

### 12.4 What revision 3 introduced that is wrong

**F1 — §3.6: "PostgreSQL resolves it by lock timeout (250 ms, as every writer here sets) or by deadlock detection."** Not every writer sets it. By reading: the verifier, the recovery and `TenantAiActiveLifecycle::transaction` set 250 ms through `TenantAiVerificationAdmission::limits`. `TenantAiLifecycle::transaction` does not, and `TenantAiActiveLifecycle::disableWorkspace` delegates to it, so the emergency workspace disable runs without a lock timeout of its own. `TenantAiStorage::configure` does not set one either. I found no default in configuration. And the writer D1 is about is by definition one that did not follow the protocol, so it cannot be assumed to set anything. My report said "the 250 ms lock timeout both writers use" about the two sessions of one race, not about every writer. The conclusion of the sentence survives, because deadlock detection resolves a cycle regardless, but the parenthetical tells a reader that every wait here is bounded at 250 ms, and for those paths the bound is `deadlock_timeout` for a cycle and nothing for a plain wait.

**F2 — §11.3: "A separate reviewer with no access to the author's reasoning or scripts".** I had the author's reasoning in front of me: I read the package, the design and the first review in full, as the handoff required. What I did not read or reuse is the author's scripts and scratch material (§2). The sentence overstates the independence of this review.

**F3 — §11.3 says more than the review established, in three places.**

- "constraint differentials … found no disagreement with the listings". The differentials compared the frozen check with the successor check and found no disagreement *between the two* on non-inference rows (§4.4). They say nothing else about the listings.
- "No other existing test broke", unqualified. What I established is narrower (§7): all 16 `tests/Unit/Postgres*.php` files, `Feature/TenantAiStorageTest` on PostgreSQL and the whole SQLite suite were run with the drafts, three SQLite tests fail with and without them for environmental reasons, and the other Feature tests were not run on PostgreSQL.
- "Not verified by anyone" lists four things. My §10 lists more that are just as unverified: receipt MAC verification after I1+I2, PHP 8.4 and the unpatched key-file check for the test runs, Feature tests other than `TenantAiStorageTest` on PostgreSQL, and `REPEATABLE READ` and `SERIALIZABLE`.

### 12.5 Checked and found accurate

- **§9.1**, the seven conditions on the implementation, represents findings N2 to N7 and the invariant correctly and claims no more than I reported. One correction to my own record: §6 marks N7 "executed", and §9.1 item 7 follows it. I executed the credential expiry and the owner approval expiry. That profile validity is not re-checked at send time I established by reading `ai3c_check_attempt`, which compares only the admission instant. The statement is right; its basis for that part is reading.
- **§11.3** otherwise: the verdict, the confirmed items, the 223 cases, the two row counts, the three receipt-binding cases, the test results (four frozen files passing, the two glob tests, 18 of 19 and 19 of 19), the disclosed deviation and PHP version, and the fifth file-name-selecting test are as I reported them.
- **§1 limits**, the note under §11.2 on what "byte-identical" means, the new §13 handoff and the §14 history agree with my report. "One disclosed deviation" in §1 is loose (the copy also ran on PHP 8.5.10), but §11.3 gives both.
- Not verifiable by me and taken as the author's statement only: that the author's snapshot used the four definition functions named under §11.2, and that the generator refuses to build unless the listing hashes match. I checked the hashes directly instead.

One remark that is not a required change: for the admission variants of MP-14 the guaranteed property is equally that no attempt is admitted after a committed lifecycle change. MP-14 names only the send mark, which is the wording I asked for.

### 12.6 Required changes for the next revision

1. **§3.6:** remove "as every writer here sets". Say that resolution is by lock timeout where the waiting writer has set one, naming which writers do, and otherwise by deadlock detection after `deadlock_timeout`; or simply delete the parenthetical.
2. **§11.3, first sentence:** describe the reviewer as one who did not write the design, the package or the first review and did not read or reuse the author's scripts or scratch material. Drop "no access to the author's reasoning".
3. **§11.3:** state the differential result as agreement between the frozen and successor checks on non-inference rows; qualify "No other existing test broke" with what was run and the three environmental SQLite failures; either complete the "not verified" list from §10 of this report or say that it is not exhaustive and point to §10.

Nothing else is required. The three listings must keep their hashes. If they do, the next follow-up needs only these sentences and the hash re-check.

### 12.7 Not verified in this follow-up

- Anything by execution. No listing changed, so I relied on §4 and ran nothing.
- Whether `TenantAiLifecycle` or `TenantAiStorage` are ever invoked under an outer wrapper that sets a session lock timeout. I read their own transactions and the configuration only; no route calls them yet.
- The author's generator and the author's snapshot method (§12.5).
- Everything listed as not verified in §10 remains so.

### 12.8 Decision

Required changes 1 to 3 of §9 are closed and the listings are the ones I rehearsed. Approval was conditional on nothing new being wrong, and F1 and F2 are wrong and F3 overstates this review. They are three sentence-level corrections.

PHASE 7B.3c-2 MIGRATION PACKAGE — CHANGES REQUIRED

## 13. Follow-up review of revision 4

Date: 2026-10-10. Sections 1 to 12 above are unchanged, including both earlier verdict lines; they are the record of revisions 2 and 3. This section reviews revision 4 of the package (SHA-256 `fd415498…168e320`). Review only: I read text, read code and recomputed hashes. No database was started, nothing in the repository was edited except this report and its evidence file, and nothing was committed.

### 13.1 Result in brief

F1, F2 and F3 are closed. Required changes 1 to 3 on revision 2 remain closed. The three listings are byte-identical to the ones I rehearsed. I found nothing in the changed text that is wrong or that claims more than I established. Three remarks in §13.5 are additions to my own record, not defects of the package.

### 13.2 Baseline and listing identity

| Check | Result |
| --- | --- |
| 802-file inventory, 14 frozen hashes, 7 dependency and configuration hashes | 0 drift, 14/14, 7/7 |
| Package SHA-256 equals `report_sha256` in its evidence | yes, `fd4154983a5a4cd5fd67a1fb4eefef1f5c3122e156fb8a932d49536ea168e320` |
| I1 up, I2 up, I1 down, extracted from revision 4 | `d56daa0c…2b02d79`, `48268c0a…771b64`, `be38814f…c0d84b`; each byte-identical to the text I rehearsed |
| Listing hashes recorded in the revision 4 evidence | equal to those three |
| Hash of this report recorded in the revision 4 evidence | equal to the hash this report had after section 12 (`eeaac213…3f71ee`) |
| Git HEAD and working tree | `4e77285…`; status unchanged since the end of the first review |

I did not keep a copy of revision 3. I compared revision 4 with my retained copy of revision 2 and set that against the revision 2 to 3 differences I recorded in §12. The changes since revision 3 are: the header line; the first two bullets under "What then happens" in §3.6; §9.1 item 7; the first paragraph and three bullets of §11.3; the §13 handoff; two rows and the closing sentence of §14. §3, §5.1, §5.2, MP-14, §12 and the rest of §3.6 read as they did in revision 3.

### 13.3 F1, F2, F3

| | Correction required | Result | Why |
| --- | --- | --- | --- |
| F1 | §3.6: remove or correct "as every writer here sets" | **Closed** | The sentence now says resolution is by lock timeout where the waiting transaction has set one and otherwise by deadlock detection, and that not every existing writer sets one. I checked each name against the code. Setting it through `TenantAiVerificationAdmission::limits`: the verification transactions (`TenantAiCredentialVerifier` at four call sites, `TenantAiVerificationAdmission::admit`, `TenantAiVerificationSecret`, `TenantAiVerificationContext`), `TenantAiActiveLifecycle::transaction`, and `TenantAiVerificationRecovery` at two. The design specifies the same for its inference transactions (§9.1). Setting none in their own code: `TenantAiLifecycle::transaction`, which `TenantAiLifecycle::disableWorkspace` uses and `TenantAiActiveLifecycle::disableWorkspace` delegates to, and `TenantAiStorage::configure`. "About one second in the reviewer's runs" matches my 1.03 s and 1.04 s. |
| F2 | §11.3: describe the reviewer accurately | **Closed** | It now says I read the package, the design and the first review in full and so saw the author's documented reasoning, and that what I did not read or reuse were the author's scripts and scratch material. That is what §2 of this report says. |
| F3 | §11.3: differential wording, test claim, not-verified list | **Closed** | The differentials are described as comparing the frozen checks with the successor checks. The test claim is limited to what I ran, names the three SQLite non-passes and that I judged them environmental, and says the other Feature tests ran on SQLite only. The not-verified list now carries every item of §10 and §12.7 and says it is not a substitute for my own list. |

### 13.4 The other changes

| Change | Judgement |
| --- | --- |
| §3.6: "In the reviewer's three reproductions the aborted transaction was the revoke each time. That the sender can be the victim instead, and that a disable behaves like a revoke here, follow from the lock graph and were not observed." | Accurate. It is the correction I made to my own record in §12.3. |
| §9.1 item 7: credential and approval expiry executed; profile validity at send time established by reading | Accurate. It is my correction in §12.5. |
| Header; §13 handoff; §14 rows for revision 4 and closing sentence | Accurate descriptions of what changed. "No listing has changed since revision 2" I confirmed by hash. |
| Revision 4 evidence | Revision number, reason, this report's hash and the three listing hashes are as they should be. |
| Required changes 1 to 3 on revision 2 | Still closed. The text I judged in §12.3 is unchanged apart from the two §3.6 bullets, which are now more accurate. |

### 13.5 Remarks that are not defects

1. **The two writers named as setting no lock timeout are the two I named in §12.4; they are not the only ones.** Checking the names again, I read `DeleteUserAccount`. Its transaction locks every deployment root first and sets no lock timeout in its own code, and deleting a user writes `creator_user_id` on that user's settings, connection and credential rows through the foreign key. The package's sentence is true as written and does not say "only", and its general rule (lock timeout where one is set, otherwise deadlock detection) covers this writer. The incompleteness was in my finding. The revision-history cell "named the two that do not" should be read as "the two the follow-up identified". Whether account deletion can actually take part in the cycle of D1 I did not run; it follows from the same lock order.
2. **"Set 250 ms"** is exact only away from a deadline. `limits` sets `min(250, remaining)` milliseconds, so the value is 250 ms or less. The inaccuracy is in the safe direction.
3. **"A different agent of the same model family, not a different organisation or person."** I can confirm my side of this: I am an AI agent, not a person or a separate organisation, and the package is right to say so. I cannot verify what the author is. The sentence narrows the independence claimed rather than widening it.

### 13.6 Not verified in this follow-up

- Anything by execution. No listing changed, so I ran nothing and rely on §4.
- A literal revision 3 to revision 4 difference, since I had not kept revision 3 (§13.2). I have kept revision 4.
- Whether `TenantAiLifecycle`, `TenantAiStorage` or `DeleteUserAccount` are ever called under an outer wrapper that sets a session lock timeout.
- The author's generator, the author's snapshot method, and the author's statement about its own model family.
- Everything listed as not verified in §10 remains so. In particular no PHP inference writer exists, so the invariant of §3.6 is a condition on work not yet written.

### 13.7 Decision

The listings are the ones I rehearsed in §4, where they behaved as specified in every case and no frozen test failed. Every required change from §9 and §12.6 is closed, and I found nothing new that is wrong. This approval covers only the bounded implementation specified in design §17, under the conditions of package §9.1 and the invariant of package §3.6. It does not authorize activation, enabling any switch, any provider request, or applying the migrations outside that bounded run.

PHASE 7B.3c-2 MIGRATION PACKAGE — APPROVED FOR BOUNDED EXECUTION
