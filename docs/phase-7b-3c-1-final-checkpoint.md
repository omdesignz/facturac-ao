# Phase 7B.3c-1 — final frozen-increment checkpoint

Date: 2026-10-09. **ACCEPTED — checkpoint frozen; no remaining reproduced acceptance blocker**. This records the completed independent review, its reproduced security blocker and subsequent validation-fixture blocker, the authorized bounded correction, and verification against the resulting source state. It does not reopen Phase 1 or authorize another capability increment, commit, push, deployment or activation.

## Scope and exact source

The initial independent review is preserved in [the security review](phase-7b-3c-1-provider-verification-security-review.md) and its evidence. Its failed verdict was correct for the original candidate: M1 was unresolved then. The checkpoint evidence identifies both complete 802-file candidate inventories, SHA-256 digests, the four-file source delta, final report hash, commands, logs, retained historical results and final manifest reconciliation. Git HEAD alone cannot identify this heavily uncommitted candidate; the content inventory is authoritative for this checkpoint. No commit was created.

Only these implementation/test files changed during remediation:

- `app/Fiscal/TenantAiVerificationResponseBuffer.php`: reject simultaneous Transfer-Encoding and Content-Length in the existing malformed-response guard.
- `tests/Unit/TenantAiVerificationProtocolTest.php`: two order/case counterexamples, two legitimate framing controls, and one inert native-cURL loopback regression.
- `tests/Unit/PostgresTenantAiVerifierTest.php`: two real-verifier rejection cases and a malformed-response branch added to the existing real-cooldown active-rotation test.
- `tests/Unit/PostgresAssistantProviderTest.php`: align the month-rollover test’s synthetic shared approval with its frozen clock, increment its revision as required, and assert exactly one control row changed. All existing assertions remain.

No migration, provider adapter, credential lifecycle, actor quota, signing algorithm, receipt vector, control-plane schema or gateway dependency changed. All 14 frozen hashes remain exact. Other files created/updated by this checkpoint are this report/evidence, the historical independent review/evidence and the existing phase plan in README.

## Finite blocker register and final disposition

| ID | Severity | Reproducible failure and violated requirement | Resolution proof | Final status |
| --- | --- | --- | --- | --- |
| M1 | MEDIUM acceptance blocker | At `app/Fiscal/TenantAiVerificationResponseBuffer.php:107`, a real cURL-decoded response with both Transfer-Encoding and Content-Length reached successful observation; the offline production verifier signed a promoted receipt and activated it. Frozen design §§5 and 11 require rejection of ambiguous headers/malformed protocol. | Existing parser now returns malformed_response before success. Both orders/mixed case reject; chunked-only and length-only still succeed; native local cURL delivers both headers and the parser rejects; full verifier creates no receipt/promotion, retains nine liabilities, and failed rotation preserves exact prior active row/envelope/selection/generation. Independent original counterexample is rerun expecting refusal. | RESOLVED |
| M2 | VALIDATION BLOCKER (test fixture) | At `tests/Unit/PostgresAssistantProviderTest.php:154`, the submitted month-rollover test failed reproducibly with HTTP 503 at `TenantAiLegacyAccounting.php:76`: its legacy clock was frozen to October 31 but its shared approval expired on October 10 using real PostgreSQL time. Frozen design §14 Compatibility and §15 require the legacy-coexistence regression; it could not reach its assertions. | Only this test now supplies a valid shared approval in its frozen timeline, with the required control revision increment. The isolated case passes 1/9; its complete file passes 19/275. No production accounting or time authority changed. | RESOLVED |

M2 is a fixture defect in this increment’s legacy-coexistence coverage, not evidence that production should accept expired approvals. The production refusal was correct. The original report’s retained broad pass is not used to override this fresh counterexample. There is no remaining reproduced acceptance blocker. Historical reconciliation, R2, R3 and CR1 failures remain in their original reports and are treated as corrected based on current implementation and evidence. They are not reopened merely because those reports still exist. Additional capabilities and speculative improvements are deferred.

## Security re-review of the bounded correction

The header parser already lowercases field names, rejects duplicates, enforces byte/count/deadline bounds, and stores only string values. The new combined `isset` therefore covers either header order and arbitrary field-name case without changing normalization of any security identity. It executes before model/account parsing, successful evidence or Phase C. Malformed responses take the existing failure-accounting path; no alternative issuer, refund or promotion authority is introduced. Legitimate single framing remains accepted.

The native-cURL regression is an inert server bound only to 127.0.0.1 with an ephemeral port, fixed non-sensitive JSON, no credentials and bounded client/server timeouts. It exercises library decoding and the production buffer; it is not a provider probe. Offline runtime tests continue to substitute raw wire data only, preserving actual authorization, quota, decryption, receipt and lifecycle code.

CR1 A1-only locking, count/admission atomicity, canonical root order, tenant/actor/provider/credential binding, fresh authorization, request-local permits, exact signed bytes, same-transaction receipt/promotion, old-envelope destruction and gateway isolation are unchanged. Independent cross-root/tenant/credential races, cross-provider inert metadata and different-actor control remain part of the evidence. Operational single-primary authority is a later prerequisite, not proved by local tests.

## Verification and manifest reconciliation

| Fresh gate | Exit | Result |
| --- | --- | --- |
| runtime | 0 | 79 passed / 1,124 assertions |
| quota | 0 | 9 passed / 143 assertions |
| units | 0 | 61 passed / 307 assertions |
| frozen-schema | 0 | 166 passed / 1,698 assertions |
| frozen-upgrade | 0 | 6 passed / 88 assertions |
| frozen-schema_race | 0 | 1 passed / 15 assertions |
| integration | 0 | 1543 passed / 11,301 assertions |
| concurrency | 2 | 335 passed / 9,709 assertions; 1 error(s), followed by explicit reruns |
| sqlite-isolated-quota | 0 | 1 passed / 64 assertions |
| sqlite-remainder | 0 | 2369 passed / 15,610 assertions; 597 skipped; 1 warning(s) |
| review-independent | 0 | 3 passed / 24 assertions |
| review-forgery | 0 | 1 passed / 4 assertions |
| review-isolation | 0 | 1 passed / 19 assertions |
| review-framing | 0 | 1 passed / 17 assertions |
| legacy-rollover-recheck | 0 | 1 passed / 9 assertions |
| legacy-file-recheck | 0 | 19 passed / 275 assertions |

The checkpoint evidence retains exact argv/environment, exit status, duration, log SHA-256 and raw summaries. Test counts are not summed across overlapping suites and called unique coverage. The full SQLite manifest is partitioned into one unchanged isolated quota case plus the disjoint remainder; this is not an uninterrupted clean run. PostgreSQL runtime, quota, schema, upgrade, schema race and integration are separately executed gates. Broad concurrency completed with 335 passes and one error; the corrected case then passed in isolation and in its full 19-case file. All 336 manifest cases have passing coverage after this explicit reconciliation, but this is **not** one uninterrupted passing concurrency run. Retained prior implementation evidence is labeled separately from fresh checkpoint executions.

The checked-in manifest increased from 2,960 to 2,967 cases: seven new cases and no removed method cases. Two new PostgreSQL cases are skipped in SQLite and executed in the dedicated runtime gate; five new protocol cases execute in SQLite. The existing rotation case gains a rejection branch without being counted as a new case. SQLite partition coverage is exactly one isolated case plus 2,966 remainder cases. Generated-ID dataset labels are compared by method multiplicity; raw inventory bytes and hashes are retained.

Integration and broad concurrency began before the final correction to the new runtime test’s audit assertion. That runtime test file is excluded from both manifests; all participating files and production code remained unchanged throughout those gates. The modified runtime file was rerun completely afterwards. SQLite was restarted after that test correction. The later M2 correction affects only a PostgreSQL test file skipped by SQLite and excluded from the integration manifest; that entire file was rerun on PostgreSQL. The other 317 broad-concurrency cases and every production file remained unchanged. Their first-run passing evidence is retained, not relabeled as a new uninterrupted run. No partially executed gate is counted as complete.

## Failures and limitations retained

- Original M1 success/promotion counterexample remains sealed against the pre-correction candidate. A passing counterexample test demonstrated the defect; it did not mean acceptance passed.
- Independent review setup first used an incorrect copied-fixture database guard, then SQL_ASCII inherited from the cluster. UTF-8 review databases and complete relevant reruns are retained separately.
- Checkpoint integration/concurrency setup initially used a name outside the unchanged disposable-reset prefix. Both runs failed before domain assertions. New compliant UTF-8 databases were used for the complete reruns; the guard was not changed.
- The first new runtime regression incorrectly checked for any promotion audit across the persistent test database. It failed on earlier fixtures. The assertion now requires absence for its own attempt ID; all rejection, no-MAC, pending-state and retained-liability assertions remain. This is a test-fixture correction, not a weakened security requirement.
- A subsequent scratch-copy substitution duplicated part of the disposable database name and failed the test guard. The copy transformation was corrected, then the complete runtime gate restarted on another new database. Production code and checked-in guards were unchanged.
- An in-progress SQLite remainder was intentionally interrupted when that test assertion was corrected. It contributes no completion evidence. The full two-part manifest was restarted.
- Broad concurrency reproduced M2, and its first isolated replay also failed. The first fixture correction was refused by the frozen control-revision guard. The corrected fixture advances revision rather than weakening that guard; isolated and complete-file reruns pass. All these failures remain recorded.
- Two intermediate scratch setup-migration logs were overwritten during retries. Their exact commands, exit statuses, durations and original checksums remain, but their original raw bytes are unavailable. Their associated failure logs, the pre-existing implementation evidence and all final acceptance logs remain intact. This limitation is recorded per command in the evidence and is not represented as verified historical log content.
- Historical implementation failures and partitioned reruns remain intact. No failed execution was relabeled as a pass.

PHPStan exited 1 with the exact inherited 21 diagnostic objects (path, line, message, identifier and tip); zero new diagnostics and no suppression. Type checking and the 12 assistant UI tests passed. Affected PHP syntax and Pint passed. No frontend file changed: the retained 9,719 ESLint errors and four existing resource-format failures remain inherited debt, not newly passing checks. No unrelated cleanup occurred. The inherited SQLite warning is reported in the fresh results; no new warning is dismissed merely by being labeled inherited. Review found no demonstrated connection from this debt to a Phase 7B.3c-1 authority or custody failure.

No real provider API calls occurred. No secrets, profiles, grants, acknowledgements or activation approvals were provisioned. Both verification switches and both inference switches remain hard-disabled in checked-in configuration; customer credential consumption remains absent. Tests use disposable databases and synthetic custody material, cleaned by their fixtures. Passing offline tests does not establish production FPM/APM isolation or a live account/model entitlement.

## Project-plan checkpoint and deferred backlog

**Phase 7B.3c-1 is accepted and frozen at this exact content inventory.** Independent security review: PASS after bounded M1 correction and M2 fixture/verification closure. CR1 admission serialization, authenticated/request-local verification authority, atomic active lifecycle, bounded secret custody and gateway isolation are verified within the approved offline/single-primary boundary. No unresolved BLOCKER, HIGH or MEDIUM remains. The original failed review is preserved as pre-remediation history. The existing phase plan in README points here and to the machine-readable evidence. Further capability work requires a new explicit authorization.

Explicitly deferred: Phase 7B.3c-2; gateway credential consumption; additional providers; new UI; production activation; unrelated lint/format cleanup; all other capability increments. These require a later separately authorized phase. No preparation code was added.

Before any later activation decision, retain applicable approved evidence and collect missing deployment/account evidence for the following prerequisites. Do not reopen settled architecture or replace existing approvals with new assumptions:

1. Exact provider organization/workspace/model entitlement and permissible operation/cost evidence.
2. Accountable technical/operations/privacy owner, billing owner and approved bounded budget.
3. Privacy, retention and geography decisions with account-specific evidence.
4. Production runtime-secret custody (KEK/signing/provider separation), access/recovery and rotation evidence, FPM/APM/log isolation, and proof all participating roots share one authoritative primary/history.
5. A controlled, explicitly authorized rehearsal, rollback/kill-switch procedure and reviewed results. This checkpoint does not authorize the rehearsal.

Stop at this checkpoint. No commit, push, deployment or activation was performed.

PHASE 7B.3c-1 — ACCEPTED AND FROZEN
