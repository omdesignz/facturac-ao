# Phase 4D implementation report

## Round 2 implementation completion — 2026-10-08

This section supersedes the earlier implementation status below. The corrected bounded Sol handoff in the Round 2 query-plan review is the authority for this work. All required local implementation gates are complete. **PHASE 4D IMPLEMENTATION — READY FOR FINAL ASTRA REVIEW.** This is implementation handback, not final Astra approval, deployment or external enablement.

### Exact implemented adjustment and preserved boundaries

PostgreSQL candidate discovery now uses the approved nested `first_keys AS MATERIALIZED` containing only ID/date, ordered at LIMIT 50000; one last composite key from that relation; a second independently bound workspace/entity/environment/month range strictly after that scalar boundary, ordered at LIMIT 50001; and UNION ALL with the global date/ID order and LIMIT 100001. Both ranges feed the unchanged bounded LEFT LATERAL ID/context lookup and one financial/count/invalid fold. The `WITH candidates` execution hook remains intact. SQLite retains the equivalent single ordered range and unique-ID/context left join; business expressions are shared.

No scope, route, response, OpenAPI bytes, financial definition, context/sponsor authority, production restriction, quota identity/budget, guard lease, transaction/deadline, audit, correlation, last-use, A1 or SA1 change. Unknown/incomplete/conflicting AGT evidence cannot authorize receipts; age alone still does not invalidate proven acceptance. Billing remains an immutable locally recorded issue metric, independent of AGT eligibility. No migration, index, dependency, planner setting, memory setting, hint or manual-vacuum prerequisite was added. External access remains disabled.

Round 2 changes relative to the content snapshot taken before this work are limited to `app/Analytics/BillingSummaryQuery.php`, `tests/Feature/BillingSummaryApiTest.php`, `tests/Unit/PostgresBillingSummaryTest.php` and this report. The entire inherited dirty tree is preserved. Both authorized Phase 4D migrations, all earlier contracts/reviews/specifications, configuration, routes and frontend files are unchanged.

### Completed test additions and acceptance mapping

The 28 earlier remediation additions remain. Round 2 adds 64 API/application cases and 38 PostgreSQL cases, while strengthening existing plan and OpenAPI assertions:

| Acceptance boundary                 | Concrete coverage                                                                                                                                                                                                                                                                                                                                                                                                              |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Exact candidates and control rows   | Streaming row-for-row comparison against an independently specified ordered prefix at 0/1/100/49999/50000/50001/99999/100000/100001/200001; strict increasing composite keys prove no duplicate. Backdated/nonmonotonic dates, shared-date ties, first/last month boundaries, eight currencies, mixed six types and six states, ordinary drafts, null currency-control group and RG.                                           |
| Capacity and corruption             | Actual complete totals at valid cap; generic no-partial GET/HEAD at overflow; verified corrupt physical positions 50000/50001/100000/100001; drafts/RG at both range boundaries; overflow entirely from financially excluded RG rows.                                                                                                                                                                                          |
| Real snapshot behavior              | Independent reader/writer processes, asserted `WITH candidates` hooks, actual draft save/issue, linked credit, receipt, poll and reconstruction transactions; commit before/after the read, source rollback, and a draft moved into the first date range across a 50000-row tie boundary. Exact complete old/new currency arrays. No invented void operation.                                                                  |
| Immutable fiscal and AGT separation | Real draft deletion and issued deletion/update rejection; unknown/partial/conflicting reconstruction and replay preserve attempts/frozen bytes; stale proven acceptance retains SA1 authority; authoritative invalidity, request rejection and processing cancellation preserve recorded billing without receipt authority.                                                                                                    |
| Required audit                      | Human, machine and support × unsaved/rolled-back/buffered/disabled/cancelled/throwing persistence × GET/HEAD: all withhold results, success audit and last-use and release the guard. Existing dual attribution/correlation/redaction tests retained.                                                                                                                                                                          |
| Generic protocol                    | Actual database-cache outage with debug enabled; complete applicable GET/HEAD error schema/status/header/redaction matrix; unsupported OPTIONS/POST retained; all local OpenAPI references resolve, published contract equals the normative copy and named route/method inventory matches.                                                                                                                                     |
| Native quota identity               | Effective human 6/minute; shared workspace 30/minute across five humans plus an integration; native clock window observations and legal six-plus-six minute-boundary burst; all inherited integration/rotation/alias/earlier quotas retained.                                                                                                                                                                                  |
| PostgreSQL failure recovery         | Actual ACCESS EXCLUSIVE contention under 250ms lock timeout, terminated backend with no financial retry/publication, and SIGKILL of a worker inside the actual read transaction; immediate denial then native ten-second lease recovery. Existing read-only/statement-timeout/setting restoration checks retained.                                                                                                             |
| Physical plans                      | Original skewed fixture plus five independently fresh volume/context fixtures, one million out-of-month distractors, same workspace/entity homologation distractors, normal statistics refresh repeated three times, and an additional fresh million-distractor mixed/backdated fixture. Catalog index definitions, conditions, loops, rows, buffers, statistics, visibility and native settings retained alongside each plan. |

The existing full suite continues to cover all monetary/date/scoping/lifecycle/migration/UI baselines. Detailed final runs and measured plans will be recorded below once the complete gates finish.

### Failed attempts and fixture corrections

An initial new quota case lacked its required boolean dataset and stopped test discovery; added the dataset. The first PostgreSQL sequence comparison exceeded PHP's default memory limit because it retained two 100001-row object arrays; replaced that test-only retention with an exact streaming row comparison, without changing any database memory/resource limit. All 36 audit variants initially compared a newly created model's attributes against a reloaded row containing database defaults; snapshotting the freshly loaded row fixed the fixture and all 36 passed. The first complete error matrix had 12 passes/two failures because revocation correctly returns 401 before the named capability denial audit; the corrected 500 fixture cancels the required scope-denial audit and all 14 passed. No authorization or assertion was weakened.

The first complete PostgreSQL billing matrix had 10 passes/five errors, 3812 assertions in 367.209 seconds. All structural/statistics/GET result paths passed; each error was the nonexistent Laravel `headJson` test helper. Replaced it with the supported `json('HEAD', ...)` call. The first 38-case PostgreSQL adversarial run had 33 passes, one failed case and four errors, 1270 assertions in 291.026 seconds. Three dense fixtures omitted the contract-required ANALYZE after load; their reads failed generically. Added normal fixture ANALYZE rather than increasing deadlines or tuning the database. The corrected complete 38-case adversarial rerun passed all 38 cases / 1369 assertions / 189.107 seconds, including all three formerly failing dense reads. Both draft interleavings attempted an issuance date earlier than the existing series' last issued date; the domain correctly blocked it and the reader barrier timed out. The corrected fixture issues its source at the earlier date so the move is legitimate under the unchanged series rule. These corrections are not a new fiscal/backdating permission.

The first complete SQLite run had 1497 passes, 26 errors, 122 PostgreSQL skips and one inherited warning, 9456 assertions in 280.669 seconds. All 26 errors were OpenSSL fixture random-state writes to an unwritable sandbox default. Subsequent complete runs use the same writable temporary RANDFILE as the PostgreSQL gate. The failed run is retained. Early targeted audit, projection, error-schema, deletion and rejection/cancellation reruns passed; they do not replace final complete runs.

### Final fresh PostgreSQL plan evidence

Every fixture retains one million out-of-month distractors. The five volume fixtures also retain same-month foreign workspace, sibling entity and same workspace/entity homologation distractors. All first measurements have **relallvisible=0**. No manual vacuum or native setting override was used. Initial ANALYZE plus three normal refreshes pass independently; the original skewed sparse/dense test also passes.

|             Population | First + three refresh times (ms)      | First relation pages / all-visible | Selected first / tail keys | Temp read/write blocks |
| ---------------------: | ------------------------------------- | ---------------------------------- | -------------------------- | ---------------------- |
|                      0 | 2.641 / 0.096 / 0.075 / 0.068         | 41834 / 0                          | 0 / 0                      | 0 / 0                  |
|                    100 | 6.211 / 0.407 / 0.356 / 0.362         | 41838 / 0                          | 100 / 0                    | 0 / 0                  |
|                 100000 | 236.829 / 232.312 / 232.804 / 236.454 | 46000 / 0                          | 50000 / 50000              | 0 / 0                  |
|                 100001 | 233.562 / 230.979 / 236.666 / 233.572 | 46001 / 0                          | 50000 / 50001              | 0 / 0                  |
|                 200001 | 410.112 / 236.882 / 236.341 / 269.468 | 50167 / 0                          | 50000 / 50001              | 0 / 0                  |
| 100000 mixed/backdated | 271.906 / 276.528 / 270.143 / 266.769 | 46009 / 0                          | 50000 / 50000              | 0 / 0                  |

Exactly two forward scoped B-tree key ranges execute once, with both month bounds at access and the tail's strict composite seek. Every fiscal scan is one of those two ranges or the bounded unique-ID/context payload point access. Fresh dense first-range heap fetches equal 50000; tail heap fetches equal 50000/50001. Zero heap fetches is not required. No unrelated fiscal scan, bitmap access, full-month payload side, temporary I/O, disk sort or spilled hash batch occurs. The boundary reads only the reused first-key CTE; dense boundary sort uses 25 KiB, sparse maximum sort uses 34 KiB. Fold groups remain at most nine and API output eight buckets. Catalog index definitions and synthetic SQL/bindings are retained, not inferred from a specific index name.

Native work_mem=4MB, hash_mem_multiplier=2, random_page_cost=4, seq_page_cost=1, PostgreSQL 18.6. EXPLAIN and actual read/capability/API calls retain the read-only 2000ms statement / 250ms lock protections. Dense exact totals and both overflow GET/HEAD results passed independently, with restoration/guard/audit/last-use checks. No partial totals or historical fiscal certainty are introduced.

Raw runtime plans, all four statistics/visibility/index/settings snapshots per fixture and synthetic statement bindings are retained under `/var/folders/43/2ktn8hj17719wf_3v7179w2h0000gn/T/phase4d-round2-runtime-plan-{0,100,100000,100001,200001,mixed}.json`. The original skewed plans are in the same directory's `phase4d-query-plans.json`. These are isolated test evidence, not application logs or production sizing promises.

### Final validation and handback

Final complete SQLite: **1541 passed / 10000 assertions / 1664 total / 123 PostgreSQL-only skips / 289.943 seconds**, one inherited warning. This retains all 1477 prior passes and adds all 64 Round 2 API/application cases. The preceding complete run (before the final three AGT-outcome cases) also passed: 1538 / 9967 assertions / 1661 total / 123 skips / 314.815 seconds. Full PostgreSQL concurrency/plans: **123 passed / 6148 assertions / 592.100 seconds**, one inherited warning, no failures/errors/skips. All 85 prior cases and 38 Round 2 cases pass in this complete run, including the inherited native-minute quota case unchanged. Full PostgreSQL API/identity/lifecycle/master-data/AGT/fiscal/billing/migration selection: **777 passed / 5964 assertions / 560.308 seconds**, no failures/errors/skips/warnings. All 713 previous cases and 64 Round 2 additions pass. PHPStan currently matches the exact inherited 21-diagnostic file/message/identifier multiset, with zero new/removed; types pass. Repository ESLint reports the inherited 9719 errors; resource Prettier reports the same four unchanged files. Pint dirty passes; all three changed PHP files pass syntax checks; changed report Prettier and `git diff --check` pass. No frontend file changed. No test, invariant, suppression, diagnostic baseline or dependency was weakened or deleted. No remaining bounded implementation blocker or architectural deviation is identified. **PHASE 4D IMPLEMENTATION — READY FOR FINAL ASTRA REVIEW.**

Hosted CI, intended production PostgreSQL version/distribution, operational cancellation/logging, migration maintenance/backup/restore and AGT homologation evidence remain release requirements.

| Final local gate                                           | Result                                                                                                                    |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------- |
| Complete SQLite                                            | 1541 passed, 10000 assertions, 123 PG-only skips, inherited warning                                                       |
| Complete PostgreSQL concurrency/plans                      | 123 passed, 6148 assertions, inherited warning                                                                            |
| Complete PostgreSQL API/integration selection              | 777 passed, 5964 assertions; no failures/errors/skips/warnings                                                            |
| PHPStan                                                    | Exact inherited 21 instances; zero new/removed                                                                            |
| Type checking                                              | Passed                                                                                                                    |
| Pint / changed PHP syntax / changed Markdown format / diff | Passed                                                                                                                    |
| Repository ESLint                                          | Failed with the same 9719 inherited errors                                                                                |
| Resource Prettier                                          | Failed only for unchanged Establishments/Index.vue and promo/coming-soon.html, render.mjs, switch.html                    |
| Artifact preservation                                      | Only four Round 2 files changed; governing contracts, migrations/indexes, routes, specs/configuration/resources unchanged |
| External access                                            | integrations.enabled=false                                                                                                |

Complete logs are `/private/tmp/phase4d-r2-sqlite-complete.log`, `/private/tmp/phase4d-r2-pg-concurrency-complete.log` and `/private/tmp/phase4d-r2-pg-api-complete.log`. Final plan/statistics/settings, protected-file hashes, exact PHPStan comparison and all Round 2 attempt-log results are bundled in `/private/tmp/phase4d-round2-implementation-validation-evidence.json`. The historical failures below remain historical; they are not silently replaced by this handback.

**Exact recommended next action — Astra Medium, separate final Phase 4D review:**

> Perform only the final Phase 4D recorded-billing disclosure and trust-boundary review. Read the signed Phase 1–4C authorities, A1/SA1, Phase 4D analytics contract and normative OpenAPI, the Round 2 query-plan amendment/evidence, this implementation report and final runtime validation evidence. Inspect BillingSummaryQuery, shared capability/adapter/resource, scope/index migrations and complete retained/new regression coverage. Verify monetary/date/currency semantics, immutable evidence versus AGT eligibility, identity/context/sponsor freshness, scope isolation, generic GET/HEAD/errors, durable audit/correlation/redaction/last-use, native quotas/guard/failure recovery and bounded fresh PostgreSQL plans/snapshots. Review the exact four-file Round 2 change set without reopening unrelated signed decisions. Decide final bounded Phase 4D approval or return precise findings. Do not implement the next phase, change signed architecture silently, enable external access or treat local tests as production/homologation approval.

Stop here for that Astra Medium review. Receivables/collections/aging/history, mutations/fiscal issuance through APIs, customer dimensions/trends, new scopes/limits/timezones/currencies, agents/BYOW/autonomy, webhooks and external production enablement remain deliberately unimplemented.

No new AGT homologation evidence is produced by fake-gateway tests. This work does not perform the separate final Astra trust-boundary review, approve production deployment or enable external access.

## Historical prior implementation and stop records

Decision date: 2026-10-07. Bounded Sol Medium implementation against the signed Phase 4D contract, retaining Phase 1–4C authorities, A1 and SA1. **PHASE 4D IMPLEMENTATION — NOT READY.** Implementation stopped at the mandatory PostgreSQL plan gate; this is partial implementation for Astra review, not release approval.

## Current remediation status (supersedes the historical result below)

The approved query-only shape has been implemented. **PHASE 4D IMPLEMENTATION — NOT READY.** The fresh dense PostgreSQL structural gate still fails; Sol stopped further implementation as required by the remediation handoff. The original implementation and validation history below is retained verbatim after this current section.

Candidate discovery now selects only ID/document_date, ordered by document_date/id with LIMIT 100001. PostgreSQL uses LEFT JOIN LATERAL with unique ID plus the explicit workspace/entity/environment tuple and LIMIT 1; SQLite uses a unique-ID/context left join. A single bounded fold reuses BillingDefinition money expressions. Normal drafts have a null financial type, all physical candidates count, unsupported currencies share only an internal null control group, and global count/validity flags are folded before that group is ignored. Missing payload/null required values fail closed. Metadata and observation time remain in the same primary statement/read-only transaction. No API, authority, quotas, audit, deadline, migration, index, financial definition or normative OpenAPI change was made.

The latest human request explicitly requires structural validation rather than a particular named index. Tests therefore inspect the selected candidate index's actual ordered columns and scoped scan predicates, not its name. Both authorized migrations and every existing index remain unchanged. The original sparse/dense assertions and sentinel remain; new independent volume cases prevent a dense failure from hiding later sentinel evidence. PostgreSQL EXPLAIN reports logical counts as JSON numbers that may decode to floats; integer normalization is only for these plan counters, not money or API values.

### Current blocker and precise Astra decision

On freshly loaded fixtures at native work_mem=4MB, the 100000 and 100001 populations still choose the older tenant/status/date bitmap access followed by a narrow date/id external-merge sort before LIMIT. Payload access is correctly bounded, the final aggregate does not spill, and execution is below two seconds; nevertheless the candidate sort violates the signed gate. This is not permission to accept the plan. No VACUUM-only visibility assumption, forced index, planner toggle, smaller fixture, increased memory or alternative optimization was introduced.

Initial remediated plan observations (each independently recreated one million out-of-month distractors, plus same-month foreign-workspace, sibling-entity and homologation distractors):

| Month population | EXPLAIN time | Candidate/payload work                                                              | Temporary I/O                 | Outcome                                                                                             |
| ---------------: | -----------: | ----------------------------------------------------------------------------------- | ----------------------------- | --------------------------------------------------------------------------------------------------- |
|                0 |     4.627 ms | 0 keys / 0 point lookups                                                            | 0                             | Structural access passes; initial assertion needed JSON-number normalization                        |
|              100 |     5.085 ms | 100 keys / 100 one-row point lookups                                                | 0                             | Structural access passes; initial assertion needed JSON-number normalization                        |
|           100000 |   333.882 ms | bitmap scan of 100000, external date/id sort, 100000 one-row lookups                | 270 read / 271 written blocks | Fails candidate access/no-spill gate                                                                |
|           100001 |   307.741 ms | bitmap scan of 100001, external date/id sort, 100001 one-row lookups                | 270 read / 271 written blocks | Fails candidate access/no-spill gate                                                                |
|           200001 |   308.803 ms | ordered index-only scan stops at exactly 100001 keys / 100001 one-row point lookups | 0                             | Structurally bounded; initial assertion needed JSON-number normalization before API sentinel checks |

Raw SQL, controlled fixture bindings, settings and complete plans are captured by the tests as `phase4d-remediated-plan-{0,100,100000,100001,200001}.json` in the system temporary directory. These are test evidence, not application logs. They contain only synthetic records. No actual customer/fiscal secrets or production database was used.

**Exact next action — Astra Medium narrow review:**

> Read the governing Phase 4D contract, the approved query-plan remediation review, this updated implementation report, BillingSummaryQuery and the independently retained fresh dense/cap/over-cap EXPLAIN evidence. The approved narrow ordered keys → bounded LEFT LATERAL unique-ID/context payload lookup → single fold has been implemented without migration/index or planner/memory changes. At 100000 and 100001 freshly inserted candidates, PostgreSQL 18.6 still chooses tenant/status/date bitmap access and spills a narrow date/id sort before LIMIT at native work_mem=4MB; at 200001 it uses ordered covered keys and stops at 100001 without spill. Resolve only this remaining structural-plan failure, including whether the prior fresh-row feasibility experiment is representative of freshly rebuilt test/production visibility and statistics. Authorize a precise bounded remedy or explicitly amend the affected constraint; Sol may not invent one, force planner behavior, assume VACUUM visibility, raise memory, shrink fixtures or weaken no-spill/order/cap/deadline/security/financial semantics. Preserve both authorized migrations unless Astra explicitly decides otherwise. Then return bounded Sol instructions to complete the still-open Phase 4D acceptance cases and rerun all gates. Do not approve final Phase 4D review or external enablement as part of this diagnosis.

### Additional acceptance coverage completed before the stop

- Actual SaveFiscalDocumentDraft/IssueFiscalDocument FT, FR, GF, ND and linked NC flows, same/other-month credits and source-transaction rollback. Existing domain engine requires adjustment references; those prerequisites are retained. Historical standalone NC coverage remains a complete-marker fixture, not a new standalone issuance permission.
- Actual partial/full RG issuance, multiple invoice/NC-source allocations, withholding apportionment and failed overpayment; all preserve recorded billing buckets.
- Actual fake-gateway AGT acceptance/polling rejection and projection rebuild preserve billing and frozen document bytes; SA1 eligibility remains separately evaluated.
- Mixed supported/unsupported currency null-control groups and malformed issued rows fail closed while ordinary drafts contribute zero financial value.
- GET/HEAD support dual attribution with the actual non-console ambient User-Agent hook; no month/secret/request-content disclosure.
- Debug GET/HEAD errors for cancelled/throwing audit, unsupported cache backend, JSON serialization failure and corrupt totals: generic/no-store/empty HEAD, no success audit or last-use.
- New-scope lifecycle rotation, original sponsor membership deletion/recreation, and homologation create/rotate/expiry-replacement denial.
- Native-window integration quota sharing across methods/encoded paths/months/rotation and shared workspace quota across six distinct integrations. Rollover is observed using the native clock, rather than Carbon freezes; this is not completion of every human/boundary-burst quota case.
- Five independent 0/100/100000/100001/200001 PostgreSQL fixtures and recursive all-node spill/candidate/payload predicates/index-column/deadline/guard checks. Existing process snapshot/authority/read-only/deadline tests remain intact; `WITH candidates` hooks still match.

Only these files changed relative to the remediation-start content snapshot: `app/Analytics/BillingSummaryQuery.php`, `tests/BillingFixtures.php`, `tests/Feature/BillingSummaryApiTest.php`, `tests/Unit/IntegrationLifecycleTest.php`, `tests/Unit/PostgresBillingSummaryTest.php`, and this report. No new migration or route/specification/configuration/dependency/CI change. Synthetic issuance tests enable only the isolated test production-context flag and fake signer/gateway with network prevention and queued work faked; application/production AGT and external switches are untouched.

### Remediation attempts retained

| Attempt log in /private/tmp   | Passes | Failures/errors | Assertions | Seconds |
| ----------------------------- | -----: | --------------: | ---------: | ------: |
| phase4d-domain-first.log      |      2 |               1 |         16 |   1.916 |
| phase4d-domain-second.log     |      0 |               1 |          4 |   1.003 |
| phase4d-domain-third.log      |      0 |               1 |          4 |   1.070 |
| phase4d-domain-fourth.log     |      1 |               0 |         12 |   0.979 |
| phase4d-security-first.log    |      4 |               8 |         86 |   6.359 |
| phase4d-security-second.log   |     10 |               0 |        120 |   5.115 |
| phase4d-receipts-first.log    |      1 |               1 |         17 |   1.970 |
| phase4d-more-first.log        |      1 |               2 |         20 |   2.139 |
| phase4d-more-second.log       |      3 |               0 |         65 |   2.370 |
| phase4d-lifecycle-first.log   |      3 |               1 |         18 |   1.157 |
| phase4d-lifecycle-second.log  |      4 |               0 |         22 |   1.196 |
| phase4d-remediation-plans.log |      0 |               5 |        181 | 266.514 |

Fixture corrections: explicitly match connection/series production tuple; supply required generic-line operation date and adjustment source reference; assert persisted withholding relation instead of a nonexistent total attribute; send bearer in the low-level HTTP call's server headers; request analytics scope explicitly on rotation (default documents scope cannot widen a credential); generate/hash the credential secret before insert rather than deleting immutable referenced credential rows. No domain rule/assertion was relaxed. The initial structural tests decoded EXPLAIN logical row counts as floats; normalized those counters for exact logical integer comparison. Dense temporary I/O failures are actual blocking failures, not fixture corrections.

A direct Pest diagnostic attempt rejected the unsupported `--no-ansi` option before executing tests; no passing test evidence is attributed to it. The first targeted query selection passed 10 tests / 75 assertions / 6.351 seconds. An automatic approval check rejected a proposed wholesale trailing test-block replacement as a risk to required assertions. That edit never executed; the original test was preserved and strengthened, and five independent cases were appended. No outstanding approval request or workaround remains.

### Still open because implementation stopped

Dense structural-plan acceptance; recent visibility/order proof; full mixed-state/draft/RG/late-corrupt capacity matrix; actual domain issuance/credit statement interleavings and concurrent receipt/poll/rebuild/source rollback; complete unknown/partial/conflicting/stale projection invariance; remaining human/machine audit cancellation/unsaved/buffering/rollback variants and error/HEAD schema matrix; human/native-boundary quota cases; actual cache outage, PostgreSQL lock contention, worker death/ten-second lease recovery and connection-loss cancellation. Existing tests for related lower-level services remain baseline evidence, not substitute proofs for the new capability. Hosted CI, production database/version/performance, writer-paused migration/backup/restore, store availability, connection cancellation and operational logging remain separate release gates. No new AGT homologation evidence was produced.

### Current validation

Full SQLite suite: **1477 passed / 9089 assertions / 1562 total / 85 PostgreSQL-only skips / 219.793 seconds**, one inherited warning. All previously required SQLite coverage is retained, with 23 additional passing SQLite cases. The inherited WorkSession timing assertion passed unchanged in this complete run.

PHPStan final: **21 inherited diagnostics, zero new or removed**, exact file/message/identifier multiset against the retained Phase 4C baseline. The first remediation invocation had one new `match.alwaysTrue` diagnostic from redundant driver matching; removed the redundant branch after the preceding supported-driver guard, without changing SQL or adding a suppression. Types passed. Pint dirty pass, five changed PHP syntax checks and diff check passed. Repository-wide ESLint still fails with **9719 inherited errors**; resource Prettier still fails only the same four unchanged files. All resource files, governing contracts/reviews/specifications, both authorized migrations and integration configuration are byte-identical to the remediation-start snapshot.

Full PostgreSQL concurrency/plan group: **81 passed / 4 failed / 1316 assertions / 85 cases / 430.063 seconds**, one inherited warning. Three failures are the billing candidate no-spill gate (the retained original dense test and independent 100000/100001 cases). The fourth is unchanged PostgresIntegrationConcurrencyTest native-minute quota expectation, observed 66 instead of 60, consistent with the known native-minute boundary flake. That inherited test freezes Carbon but the limiter uses native time(); it does not capture native window timestamps, so the exact boundary crossing is inferred rather than independently recorded. Unchanged targeted rerun: **1 passed / 9 assertions / 2.589 seconds**. Both runs are retained; the complete group is not claimed green. Other inherited concurrency cases and new sparse/empty/200001 volume checks passed.

The final 200001 case passed the actual API generic 503/no data/no success audit/no last-use/guard release checks. Its EXPLAIN selected exactly 100001 ordered keys and at most one same-tuple payload row per selected key; it scanned neither the whole month nor a hash payload side, and all nodes had zero temporary I/O. Exactly-100001 actual API checks and dense result checks remain unreached after the structural assertion, and remain open rather than being inferred from the over-cap result.

Final independently captured plans: 0 candidates **3.367 ms**; 100 **3.959 ms**; 100000 **245.452 ms** (270 read / 271 written temporary blocks); 100001 **241.957 ms** (270 / 271); 200001 **257.251 ms** (zero temporary blocks). Each uses the actual dedicated read-only transaction with statement_timeout 2000ms / lock_timeout 250ms, native work_mem 4MB and no planner/memory override. The 0/100/200001 structural/result paths restore connection settings; the dense structural assertions fail before their subsequent actual-result/restoration assertions. The final eight-bucket aggregate itself has Disk Usage 0; only candidate ordering spills. Raw final plans/SQL/synthetic bindings/settings, including the retained original test, are bundled at `/private/tmp/phase4d-remediation-final-plan-evidence.json`; they supersede the per-case temporary capture files overwritten by the full rerun. Initial attempt observations remain in this report and failure log.

First complete PostgreSQL API selection: **709 passed / 1 failed / 3 errors / 5024 assertions / 713 cases / 351.875 seconds**. The new analytics-scope lifecycle fixtures leaked rows into subsequent old-schema migration fixtures. MasterDataScopeMigrationTest and QualifiedAgtScopeMigrationTest correctly refused narrower CHECK constraints and observed extra grants. This is a test-harness isolation defect caused by the new fixtures, not a migration/allowlist defect. Added guarded afterEach cleanup to IntegrationLifecycleTest, rolling back any open test transaction and rebuilding only the disposable schema. Existing migration assertions/rows/constraints were not relaxed. A fresh complete SQLite and PostgreSQL API rerun follows. The PostgreSQL concurrency group is unaffected by this file-scoped non-postgresql lifecycle hook; its runtime/query/plan tests remain unchanged since the recorded full group.

Post-cleanup full SQLite rerun: **1477 passed / 9136 assertions / 1562 total / 85 PostgreSQL-only skips / 230.614 seconds**, one inherited warning. The 47 extra assertions relative to the first passing full run confirm successful disposable-schema cleanup after each lifecycle case; no tests were removed or assertions relaxed. WorkSession again passed unchanged. This is the final complete SQLite result.

Final complete PostgreSQL API/identity/lifecycle/master-data/AGT/fiscal/billing/migration rerun: **713 passed / 5100 assertions / 400.437 seconds**, no failures/errors/skips/warnings. The fixture isolation failures are resolved; all 690 previous cases plus 23 new cases pass. Actual issuance/credit/receipt/poll/rebuild, support/debug/lifecycle/quota and control-group additions therefore pass on both engines. This does not discharge the explicitly still-open acceptance matrix or the failed PostgreSQL structural gate.

| Final gate                        | Result                                                                                                                     |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------- |
| Full SQLite                       | 1477 passed, 9136 assertions, 85 PG-only skips, 230.614 s; one inherited warning                                           |
| Full PostgreSQL concurrency/plan  | 81 passed, 4 failed, 1316 assertions, 430.063 s; three blocking billing plans and one inherited native-clock quota failure |
| Unchanged inherited quota rerun   | 1 passed, 9 assertions, 2.589 s; failed complete-run record retained                                                       |
| Full PostgreSQL API/integration   | 713 passed, 5100 assertions, 400.437 s                                                                                     |
| PHPStan                           | Exact 21 inherited diagnostics; zero new/removed; no suppression/baseline change                                           |
| Type checking                     | Passed                                                                                                                     |
| Pint dirty agent                  | Passed                                                                                                                     |
| Five changed PHP syntax checks    | Passed                                                                                                                     |
| Report format and diff whitespace | Passed                                                                                                                     |
| Repository ESLint                 | Same 9719 inherited errors; no frontend changes                                                                            |
| Repository resource Prettier      | Same four inherited failures; no resource changes                                                                          |

External integrations remain disabled; the separate final Astra trust-boundary review has not been started. The disposable PostgreSQL service is stopped after validation. No live database, rollout or next phase was performed. The plan gate is blocking independently of other passes.

## Stop condition and precise Astra decision

The approved sparse/dense PostgreSQL fixture failed exact scoped-index use in the dense 100,000-candidate plan: PostgreSQL selected the older `fiscal_documents_tenant_status_date_index`, then performed an external-merge sort. That sort also spilled to disk. The final eight-bucket hash aggregate itself used 32 KiB and reported zero disk usage; candidate/checked materialization and sorting used temporary blocks. The failure must not be described as the final hash aggregate spilling. Section 8 and the exact handoff require Sol to stop if the plan/budget gate fails. The gate is retained; no timeout, row cap, currency definition, index, caching or test requirement has been relaxed.

Astra must review whether the approved query/index and database memory constraints can meet the no-spill gate at the approved volume, and authorize a bounded remedy or an explicit contract amendment. In particular, Sol has not selected a new index/INCLUDE clause, changed database memory settings, added caching, or changed financial semantics. After that decision, Sol must complete the outstanding acceptance matrix and rerun all gates.

Exact recommended narrow Astra review request:

> Read the signed Phase 4D contract, this report, BillingSummaryQuery, the authorized analytics-month index migration and the retained sparse/dense EXPLAIN evidence. Resolve only the mandatory dense-plan failure: at 100,000 rows PostgreSQL uses fiscal_documents_tenant_status_date_index rather than fiscal_documents_analytics_month_idx and sorts/materializes with temporary I/O at native work_mem 4MB. Preserve recorded-billing semantics, eight currencies, 100001 sentinel, two-second statement deadline, tenant/context isolation, read-only execution and disabled external access. Determine the smallest approved query/planner/memory or explicitly amended schema remedy without silently relaxing acceptance. Return exact bounded Sol instructions to finish the remaining Phase 4D tests and rerun all gates. Do not authorize another capability or roadmap phase. Passing ordinary correctness tests cannot substitute for this decision.

## Implemented backend and semantics

One shared capability `analytics.billing.read`, exposed through GET/HEAD `/api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/analytics/billing-summary?month=YYYY-MM`. Independent scope `analytics:billing:read`; no implicit grants or changed default scope. No new human HTTP route.

Shared `BillingDefinition` extracts the existing billable-type and aggregate arithmetic used by `ReceivablesQuery`; existing Inertia consumers retain their return contract. The new analytics query uses that classification/arithmetic, without payment, receipt, customer, AGT or evidence joins. FT/FR/GF/ND contribute invoice amounts; NC contributes credit amounts; RG and ordinary drafts contribute no monetary amounts. Recognized non-draft local states retain recorded issue facts irrespective of AGT knowledge. Inconsistent markers, amounts, unsupported types/currencies/statuses fail the entire summary with generic 503. This metric grants no fiscal or receipt eligibility.

Exactly eight native-currency buckets, ordered AOA/BRL/CNY/EUR/GBP/NAD/USD/ZAR, including zeros. Each has currency/scale, two integer counts, six unsigned monetary strings and three signed after-credit strings. No grand total, FX conversion, float arithmetic, negative zero, implicit currency reassignment or floor on credit-only balances. Aggregate values outside the approved signed-64-bit range fail closed.

The explicit resource returns only `metric_version`, `date_basis`, `timezone`, `as_of`, `period`, `currencies`; period has month/from/to_exclusive, each bucket exactly thirteen keys, meta only trusted request/workspace/entity/environment public identifiers. The published normative OpenAPI is `docs/openapi-external-billing-v2.json`. Earlier V1/V2 specs and wire formats are unchanged.

Strict raw query parsing accepts only one month parameter, valid 2000–9999 calendar month no later than current Luanda month; repeated/encoded duplicate/array/extra dimensions and oversized malformed queries fail 422. Membership uses document-date first-day inclusive / next-month exclusive, not issuance, creation, update or payment timestamps. Current-month future document dates remain included. Entity timezone must be Africa/Luanda; unsupported metadata fails closed before fiscal SQL and is checked again in the aggregate statement. Observation time is captured in that statement, not a historical financial cutoff.

## Trust boundaries, protocol and operational effects

Machine identity remains distinct from both scope ceilings and explicit immutable workspace/entity/environment binding. Sponsor authority is checked afresh at the shared capability boundary and immediately before the short query transaction. Homologation/foreign bindings deny before fiscal SQL. Human calls accept only trusted execution context, owner/admin/accountant with existing verification/MFA requirements; viewer/billing and automation contexts deny. Existing support attribution is retained, but dedicated new support coverage remains outstanding.

Existing disabled-access, TLS/bearer, correlation, body/delegation, generic errors and normal quotas remain in place. GET/HEAD run equal authority, query, quota, audit and last-use work; HEAD has no body. Decoded unsupported methods receive 405 rather than automatic OPTIONS success. Conditional headers do not turn the route into a 304/cache bypass. Explicit resources prevent model-field serialization expansion.

Extra native-minute quotas: integration 6, effective human-user/workspace 6, workspace 30. Validated context identities determine keys, not credential/month/verb/path. Existing shared IP120/integration60/workspace300 limits are unchanged. Nonblocking workspace database-cache lock has a ten-second lease and finally release. One bounded primary SQL statement supplies totals, consistency/candidate flags, metadata and observation time. Candidate relation is ordered by date/id and capped at 100001 including drafts/RG; no partial overflow result. PostgreSQL uses a dedicated read-only transaction with local statement_timeout=2000ms and lock_timeout=250ms; ambient outer transactions fail 503. No replica, background work, retry loop, network call or domain mutation.

Required unbuffered success audit `analytics.billing.read` runs after the read transaction and before monotonic last-use. Named denial is `analytics.billing.read.denied`; capability version 2 / metric version recorded-billing-v1, trusted identity/context/correlation/operation UUID and method. No month, results, counts, currency presence, User-Agent, query, SQL/bindings, target document or secret is recorded. Required audit failure withholds data; denial-audit failure stays generic 500.

## Migrations and rollout safeguards

Only the two authorized migrations were added:

- `2026_10_07_220541_extend_billing_analytics_read_scope.php`: five-value exact CHECK allowlists on integration and credential scopes; PostgreSQL transactional replacement; SQLite table/trigger/FK preservation. No implicit grant. Down refuses retained billing grants or named success/denial audit, including revoked history; generic older V2 events alone do not block it.
- `2026_10_07_220542_add_billing_analytics_month_index.php`: nonunique `(workspace_id, legal_entity_id, environment, document_date, id)` index named `fiscal_documents_analytics_month_idx`; no INCLUDE/partial predicate/backfill/replacement. Down drops only this index.

Applied only to guarded disposable testing databases. No application/production database migrated. Deployment, if subsequently approved, requires backup/restore verification and maintenance with writers paused; ordinary index creation is not zero-lock/online deployment. Stop the reader before rollback; retained authority/audit evidence requires forward repair.

## Tests added and coverage still unfinished

`BillingSummaryApiTest`: amounts/types/states, eight currencies/large integers/overflow, empty/credit-only months, corrupt markers/data, date boundaries, strict raw queries, 32 scope subsets, reverse scope isolation, binding/homologation denial before fiscal SQL, sponsor/MFA/revocation/expiry freshness, human roles/automation denial, cookie identity confusion, GET/HEAD/encoded method handling, audit refusal/buffering/outer transaction/timezone failures, quota/query guard, explicit resource poisoning and normative success/error validation.

`BillingAnalyticsMigrationTest`: populated old grant preservation, exact malformed-scope rejection, immutable credential/FK/duplicate constraints, named-audit downgrade refusal, older generic V2 audit behavior, index/fiscal-byte roundtrip and interrupted scope migration rollback.

`PostgresBillingSummaryTest`: independent-process invoice/credit commit visibility before/after captured statement, scope withdrawal before/after final authorization, shared process guard, actual read-only write rejection and statement timeout/settings restoration, sparse/dense volume plans and over-cap assertion. The older environment migration tests now remove/restore the authorized dependent index when reconstructing their old schema; their existing assertions are retained. Concurrent issued records currently use complete issued factories; they are not proof of the actual fiscal issuance application flow.

Outstanding signed acceptance criteria include real domain issuance/credit fixtures; receipt/allocation/withholding/failed-overpayment exclusion changes around the summary; explicit projection poll/rebuild invariance; source-transaction rollback; support dual-attribution case; lifecycle rotation and sponsor deletion/recreation with the new scope; multiparty/native-window quota bypass matrix; PostgreSQL lock-timeout contention and worker-death/lease recovery; cache-backend failure; complete debug/redaction/error/HEAD schema matrix; and successful dense/100001 plan/result checks. Existing inherited fiscal/receipt/SA1/lifecycle tests are preserved but do not replace these new capability-specific proofs. The plan test stops at its unchanged failed dense assertion before reaching its final over-cap assertion.

## Validation

### Measured PostgreSQL plan evidence

Local PostgreSQL 18.6, PHP 8.4, macOS 27.0.1 arm64, ten logical CPUs; disposable `facturac_test_phase1` on loopback port 55439. Fixture: 1,000,000 same-bound-tuple records outside February 2024; sparse February has 100 rows; dense February adds 99,900 rows, evenly cycling all eight currencies. ANALYZE precedes each EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON). Native `work_mem=4MB`, `max_parallel_workers_per_gather=2`; no planner/memory override is used. This local result is not a hosted-CI or production hardware claim.

| Fixture | Execution  | Candidate access and result                                                                                                                                                                                                      |
| ------- | ---------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sparse  | 4.725 ms   | approved analytics-month index scan; 100 candidates; eight grouped currency rows; no temporary blocks; final hash aggregate 32 KiB / Disk Usage 0                                                                                |
| Dense   | 311.565 ms | older tenant/status/date bitmap index; 100,000 candidates; external-merge sort, Disk 9,992 KiB; candidate temp read/write 1,249/1,252 blocks; final hash aggregate has eight rows, 32 KiB / Disk Usage 0, with upstream temp I/O |

Both EXPLAIN times fit two seconds, but dense fails required approved-index use; its ordering/materialization also spills. The dense runtime result and final 100001 overflow assertion are not reached after this unchanged failed plan assertion. Full raw plan is retained locally at `/var/folders/43/2ktn8hj17719wf_3v7179w2h0000gn/T/phase4d-query-plans.json`; the full suite failure log is `/private/tmp/phase4d-pg-full.log`.

### Quality gate results

- Full PostgreSQL concurrency group: **79 passed / 1 failed / 993 assertions / 80 cases / 183.133 seconds**. All inherited 70 cases and nine new cases passed; new dense plan gate failed. One inherited warning. No clean PostgreSQL gate claim.
- PHPStan: **21 inherited diagnostics; zero new or removed**, exact file/message/identifier multiset comparison. The normal non-debug invocation exited nonzero without diagnostics; the project-standard debug invocation with 1 GiB memory produced the compared report. No suppressions/baseline edits.
- Type check passed. Published OpenAPI JSON parsed, all 35 local references resolved and one path verified; runtime tests validate representative success/error shapes against that unchanged normative copy. PHP syntax: 19 new/changed PHP files passed before the historical fixture adjustment, with that additional file checked at finalization. Pint dirty fix pass. Changed OpenAPI/workflow/report formatting passes at finalization.
- Repository-wide ESLint reports **9,719 errors** in unchanged existing frontend/skill files; repository-wide resource Prettier reports four unchanged files: Establishments/Index.vue and promo coming-soon.html/render.mjs/switch.html. No JavaScript/Vue/resource source changed during this phase. This is inherited debt, not a clean overall composer/CI claim.
- Initial complete SQLite: **1,452 passed / 2 errors / 8,818 assertions / 1,534 total / 80 PG-only skips / 255.752 seconds**, one inherited warning. Both errors were old-schema fixture setup attempting to remove environment before rolling back the newly dependent index. Fixture setup was corrected in migration order; no assertion weakened. Affected fixture rerun: **11 passed / 39 assertions / 1.240 seconds**. Final full rerun results follow below.

### Final remaining gate results

- Full SQLite rerun: **1,453 passed / 1 failed / 8,822 assertions / 1,534 total / 80 PostgreSQL-only skips / 259.866 seconds**, one inherited warning. The two migration fixture errors are resolved. Sole remaining failure is unchanged WorkSessionTest's exact native-second assertion (expected 900, received 899); the test does not freeze its clock and the request crossed a second boundary. Unchanged affected-file rerun: **17 passed / 90 assertions / 1.236 seconds**. No timer assertion or implementation was changed. The full suite was executed twice; its final recorded run is not claimed green, and another clean complete run remains a sign-off gate.
- Complete PostgreSQL API/identity/lifecycle/master-data/AGT/receipt selection plus new billing/migration tests: **690 passed / 4,789 assertions / 396.405 seconds**, no errors/failures/skips/warnings. All original 570 cases and 120 new cases passed.
- Scope/index migrations and API correctness therefore pass on both engines, subject to the explicitly incomplete acceptance matrix above; PostgreSQL query-plan gate remains failed.
- Final changed-file PHP syntax: **20 files passed**, including the historical migration fixture. Pint `--dirty --format agent` passed after fixes. Changed workflow/OpenAPI/report Prettier and `git diff --check` passed. No frontend source changed; all 292 snapshotted resource files remain byte-identical.
- External config inspected through Artisan: **enabled=false**, database cache. The disposable PostgreSQL server started for this validation was stopped after the gates. No live database/service was changed.

### Failed attempts and corrections

Initial targeted API/migration runs exposed fixture-only problems: incomplete MFA setup, duplicate head-office establishment creation, malformed dataset shape, expiration earlier than credential creation, fresh-versus-unsaved migration snapshot defaults, and passing an unused document ID as query data on list routes (causing the expected closed-query 422 rather than scope denial). Corrected the fixtures and routes, preserving runtime authorization rules. Initial PostgreSQL bulk fixtures omitted required customer NIF and calculation hash; populated those required local fields. The first whole-suite discovery found a helper-name collision with existing BillingWorkflowTest; renamed the new fixture helper. Three new PHPStan diagnostics in command return checks/literal SQL typing were corrected rather than suppressed. No inherited assertion or signed rule was relaxed.

Attempt evidence remains in `/private/tmp/phase4d-api-first.log`, `phase4d-api-second.log`, `phase4d-api-third.log`, `phase4d-migration-first.log`, `phase4d-pg-first.log`, `phase4d-pg-second.log`, `phase4d-pg-plan.log` and final gate logs. Targeted third run had 117 passes / three incorrect test-route failures / 723 assertions; those route inputs were corrected before the whole-suite runs. The dense plan failed twice without architectural tuning; the failure remains intentional evidence, not an accepted behavior.

Earlier targeted attempts (all retained rather than represented as passing gates):

| Log                         | Passed | Failed/errors | Assertions | Seconds |
| --------------------------- | -----: | ------------: | ---------: | ------: |
| phase4d-api-first.log       |     81 |            13 |        557 |  57.946 |
| phase4d-api-second.log      |    107 |             1 |        630 |  60.221 |
| phase4d-api-third.log       |    117 |             3 |        723 |  65.088 |
| phase4d-migration-first.log |     13 |             1 |         21 |   1.130 |
| phase4d-pg-first.log        |      9 |             1 |         83 |  11.066 |
| phase4d-pg-second.log       |      9 |             1 |         83 |  12.801 |
| phase4d-pg-plan.log         |      0 |             1 |          9 |  61.144 |

## Files changed in this implementation

The work began in an already substantially modified Phase 1–4C checkout at HEAD f887e69. This manifest is compared against a content snapshot at this implementation start, not against Git HEAD:

- app/Analytics/BillingDefinition.php
- app/Analytics/BillingSummaryQuery.php
- app/Analytics/ReceivablesQuery.php
- app/Fiscal/AnalyticsCapabilities.php
- app/Fiscal/BillingSummaryCommand.php
- app/Fiscal/ExecutionContext.php
- app/Fiscal/IntegrationCredentials.php
- app/Fiscal/IntegrationReadContext.php
- app/Fiscal/ReadOperationAudit.php
- app/Http/Controllers/Api/V2/ExternalBillingSummaryController.php
- app/Http/Middleware/ExternalIntegrationBoundary.php
- app/Http/Resources/BillingSummaryResource.php
- routes/integrations-v2.php
- database/migrations/2026_10_07_220541_extend_billing_analytics_read_scope.php
- database/migrations/2026_10_07_220542_add_billing_analytics_month_index.php
- tests/BillingFixtures.php
- tests/Feature/BillingSummaryApiTest.php
- tests/Feature/FiscalEnvironmentBoundaryTest.php (historical fixture rolls newer index down/up before/after older environment migration; assertions retained)
- tests/Feature/BillingAnalyticsMigrationTest.php
- tests/Unit/PostgresBillingSummaryTest.php
- .github/workflows/tests.yml (adds billing/migration files to PostgreSQL API selection; concurrency group discovers its new cases)
- docs/openapi-external-billing-v2.json
- docs/phase-4d-implementation-report.md

No governing contract/amendment or prior specification was edited. No dependency changed. The external enabled switch remains false. No commits, release, deployment or next phase were performed.

## Deliberate exclusions and handoff

No receivables/collections/aging/historical balance, additional analytics, exports, FX, customer drilldowns, mutation/fiscal/receipt API, live AGT enablement, credentials UI/provisioning, webhooks, agents, BYOW or autonomy was implemented. AGT homologation assumptions are untouched; this read performs no upstream operation and establishes no new homologation evidence. Hosted CI, production-version performance, connection-loss cancellation, shared-store availability, migration/backup/restore and logging remain separate release verification.

No signed architectural decision has been deliberately changed. The implementation does not meet the mandatory performance and complete acceptance gates, so it is not complete. **Next: Astra Medium, narrowly review the recorded PostgreSQL plan failure and authorize the bounded remedy before Sol resumes remaining Phase 4D acceptance work.** Do not proceed to another roadmap phase or enable external access.

**PHASE 4D IMPLEMENTATION — NOT READY**

## Current remediation conclusion

The approved query shape is implemented and correctness coverage has expanded, but the fresh dense/cap structural plan gate is still failed. Other acceptance work stopped on that required boundary. Use the exact Astra Medium narrow review prompt in the current remediation section above to resolve this remaining blocker, then return bounded instructions to Sol. The complete initial implementation history is retained as historical evidence. This is not the final Astra trust-boundary review or permission to release/enable external access.

**PHASE 4D IMPLEMENTATION — NOT READY**
