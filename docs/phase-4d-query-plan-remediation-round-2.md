# Phase 4D PostgreSQL plan remediation — Round 2

Review date: 2026-10-08. Narrow Astra design/review handback; no runtime implementation or final Phase 4D trust-boundary approval.

**PHASE 4D QUERY-PLAN REMEDIATION ROUND 2 — APPROVED FOR SOL**

Select a second **query-only** adjustment: partition the same ordered candidate prefix into a first 50000-key range and a strictly subsequent, at-most-50001-key range, within the same SQL statement. Preserve the outer `ORDER BY document_date,id LIMIT 100001`, bounded LEFT LATERAL payload lookup and existing financial fold. The existing index suffices. No migration, index change, memory increase, planner setting or financial/security/API amendment is approved.

This review supersedes Round 1's single-range prescription and implementation-shape assertions, not the signed logical row cap, deterministic membership, same-statement snapshot, no-spill requirement or deadline. Precisely one narrow 50000-key materialization and its one-key boundary calculation are authorized below. The old prohibition on repeatedly materializing wide fiscal payloads remains. The revised gate examines access structure rather than requiring an index by name. It does not accept the failing bitmap/sort plan.

## 1. Scope and evidence

Read the governing Phase 4D analytics contract, including its normative OpenAPI, Round 1 remediation review/evidence and current implementation report. Followed the **current** exact Astra handback near the top of that report; the original wide-query handback retained later is historical. Inspected BillingSummaryQuery, indexes/migration, physical-volume fixtures, structural assertions and all 28 added regression cases. Applied the Laravel query/performance guidance. No application, test, migration, configuration, route or specification was edited.

The supplied “four failing PostgreSQL plan cases” description needs correction: the recorded full group has **three plan failures** (original dense case and independent 100000/100001 cases) plus an unrelated inherited native-minute quota failure. The quota failure cannot be counted as a fourth plan failure. Its unchanged passing rerun does not erase the failed complete run.

[Round 2 evidence](phase-4d-query-plan-round-2-evidence.json) retains the incoming full plans, synthetic SQL/bindings, catalog/statistics snapshots, all query alternatives, measured result comparisons and this review's test logs. Experiments ran outside application code, against guarded disposable `facturac_test_phase1`, PostgreSQL 18.6, macOS arm64. They used the Laravel primary connection and actual current SQL/financial expressions. Statement experiments used a dedicated read-only transaction, statement_timeout=2000ms, lock_timeout=250ms, work_mem=4MB, hash_mem_multiplier=2, random_page_cost=4 and seq_page_cost=1. No manual VACUUM, planner toggle, extension, hint, memory override or schema change was used. Normal ANALYZE and automatic visibility maintenance were observed, not disabled.

## 2. Exact remaining failure

The current application selects only `id, document_date` before looking up payload. That fixed the earlier wide materialization, but not every candidate-order plan. Ordering is explicitly in BillingSummaryQuery, not an ORM scope or accidental duplicated GROUP BY order. Both columns are necessary to the signed total order: date alone leaves ties; ID alone is not date order when documents are backdated.

| Incoming independent fixture | Candidate path                  | Estimated candidate rows | Actual candidate rows | Candidate cost / ordering                     | Full statement |
| ---------------------------- | ------------------------------- | -----------------------: | --------------------: | --------------------------------------------- | -------------- |
| Valid 100000                 | Bitmap Heap Scan → Sort → Limit |                   105052 |                100000 | Sort startup 59510.61; Limit total 59760.62   | 245.452 ms     |
| Over-cap 100001              | Bitmap Heap Scan → Sort → Limit |                   104620 |                100001 | Sort startup 58967.65; Limit total 59217.65   | 241.957 ms     |
| Over-cap 200001              | Index Only Scan → Limit         |                   203546 |                100001 | Index full cost 68709.69; Limit cost 33756.90 | 257.251 ms     |

In the first two plans, the exact offending node is the **Sort directly below the candidate Limit**, upstream of the payload nested loop. Sort key is `(fiscal_documents.document_date, fiscal_documents.id)`, estimated width **12 bytes**, actual input/output respectively 100000 and 100001 rows, one loop. Method is **external merge**, reported disk space **2160 KiB**, temporary reads/writes **270/271 blocks**. EXPLAIN does not report this external sort's exact peak resident memory: 4MB is the configured work_mem budget, not a measured peak. Estimated row width is not the sort's complete in-memory tuple/array overhead; multiplying 12 by the row count does not establish that sorting fits.

The child is a Bitmap Heap Scan fed by `fiscal_documents_tenant_status_date_index`. Its index condition binds workspace/entity/date; environment is a heap filter. Each dense plan touches 4168 exact heap blocks. A bitmap path returns heap order, not the required date/ID order, so the sort is necessary for that chosen path. Payload retrieval then performs exactly one same-tuple unique-ID lookup per selected key. The final eight-group hash aggregate reports Disk Usage 0. Temporary counters on ancestors include child activity: do not add them together or describe the final aggregate as spilling.

LIMIT does not turn the dense sort into an effective small top-N operation. At 100000/100001 it retains all actual matches, and the estimates place it near the entire population. The recorded method is external merge, not top-N heapsort. At 200001 there is **no candidate Sort at all**: a different plan streams ordered index keys and stops at 100001. LIMIT's fraction of the estimated 203546 rows is about 49.1%, accounting for the discounted 33756.90 cost versus the full 68709.69 index cost. It therefore avoids both reading the remainder and sorting it. This is not evidence that larger populations require less sorting memory; they induce a different access plan. PostgreSQL documents this ordered-index/LIMIT tradeoff and partial-consumption cost model: [index ordering](https://www.postgresql.org/docs/18/indexes-ordering.html), [EXPLAIN costs](https://www.postgresql.org/docs/18/using-explain.html).

### Freshness explains the Round 1 feasibility mismatch

This review reproduced the old dense test without editing it: **one expected failure, 74 assertions, 54.784 seconds**, failing temp-read 270 versus zero. Immediately measured current SQL again spilled (255.026 ms). Catalog values were 1099992 estimated tuples, 45834 heap pages, **relallvisible=0**. Workspace/entity/environment/status each had one distinct value, date three; date and ID correlations were 1. Date frequencies estimated 98596 February rows versus 100000 actual. This is not a gross stale-statistics or selectivity error.

On that same fixture the chosen bitmap/sort path's candidate cost was 58043.02..58289.51. The ordered scan visible in the two-range prototype has the same base predicates and a full-path cost of **88078.32**, reduced to **44666.48** by its first 50000-row LIMIT. This is direct supporting cost evidence for the plan choice; ordinary EXPLAIN does not enumerate every rejected path.

Round 1's covered-key full costs were only 5626.11 initially and 13706.18 for its fresh March cohort. Its fresh cohort had 100000 heap fetches, but it was added to an already populated table rather than a fresh whole-table fixture. That review did not retain relallvisible, so its exact visibility fraction cannot be reconstructed. Fresh rows within an otherwise all-visible table are **not** proof of planner behavior with no all-visible heap pages. Index-only eligibility and visibility economics are separate; [PostgreSQL's visibility explanation](https://www.postgresql.org/docs/18/indexes-index-only-scans.html) applies even when the key columns are covered.

Round 2 observed the distinction directly: an independently rebuilt exact-overflow fixture initially had relallvisible=0 and spilled; a later measurement had all 45959 pages all-visible and the unchanged query no longer spilled. No manual vacuum or planner change intervened. Later ANALYZE repetitions also passed after visibility improved. These are separate measured states, not grounds to require vacuum before a read. The selected remedy must pass the freshly rebuilt state as well as refreshed-statistics/older-table states.

## 3. Re-inventory against the narrow current query

Catalog definitions are unchanged. Sizes below are the reproduced 1.1-million-row fixture; evidence stores exact bytes. They are not production sizing promises.

| Index                                                      | Ordered columns / purpose                                                  | Current candidate fit                                                                                                                                                                 |
| ---------------------------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| analytics_month_idx (full name prefixed fiscal_documents_) | workspace, entity, environment, date, ID; nonunique B-tree; 64978944 bytes | Covers every selected key, all equality/range predicates and the exact order. Both prototype ranges use it. No new index needed.                                                      |
| tenant_status_date_index                                   | workspace, entity, status, date; 7274496 bytes                             | Smaller repeated-key index, cheap bitmap access in this skew; missing environment and ID ordering, intervening status. Cannot stream the required full order. Retain other workloads. |
| environment_identity                                       | ID, workspace, entity, environment; unique; 64618496 bytes                 | Correct bounded payload point access. ID-leading layout is not selective monthly candidate traversal.                                                                                 |
| pkey                                                       | ID; unique; 24731648 bytes                                                 | Possible payload identity access; ID traversal would filter unrelated context/month rows and change the capped prefix.                                                                |
| tenant_entity_id_unique                                    | ID, workspace, entity; unique; 44564480 bytes                              | Existing reference integrity/point access; not monthly traversal.                                                                                                                     |
| workspace_id_customer_id_document_date_index               | workspace, customer, date; 7200768 bytes                                   | Customer history; lacks required entity/environment/order.                                                                                                                            |
| public_id_unique                                           | public ID; 54722560 bytes                                                  | Detail identity, not this aggregate.                                                                                                                                                  |
| environment_number_unique                                  | entity, environment, document number; 113434624 bytes                      | Fiscal uniqueness, no monthly key order.                                                                                                                                              |
| series_sequence_unique                                     | series, issue sequence; 7102464 bytes                                      | Allocation integrity, no monthly key order.                                                                                                                                           |
| references_document_id_index                               | source document ID; 7102464 bytes                                          | Correction references, no monthly key order.                                                                                                                                          |

Do not drop/reorder old indexes to force a preferred plan. No evidence warrants payload INCLUDE columns, production/issued partial indexes, a duplicate covering index, CLUSTER or new statistics objects. An issued-only index would also be unsafe as the sole candidate source because drafts/RG/corrupt candidates consume the cap. The scope migration is unrelated. Preserve both authorized migrations and their existing maintenance/backup/rollback safeguards. This review authorizes **no additional migration** and incurs no new persistent index storage/write cost.

## 4. Selected exact query adjustment

For PostgreSQL, replace only the contents of the existing `candidates` CTE with the following relational design. Bind each tuple/month occurrence from the same trusted context/command; keep parameter binding, never interpolate selectors.

```sql
WITH candidates AS (
    WITH first_keys AS MATERIALIZED (
        SELECT id, document_date
        FROM fiscal_documents
        WHERE workspace_id = ? AND legal_entity_id = ? AND environment = ?
          AND document_date >= ? AND document_date < ?
        ORDER BY document_date, id
        LIMIT 50000
    ), boundary AS (
        SELECT id, document_date
        FROM first_keys
        ORDER BY document_date DESC, id DESC
        LIMIT 1
    )
    SELECT id, document_date FROM first_keys
    UNION ALL
    (
        SELECT id, document_date
        FROM fiscal_documents
        WHERE workspace_id = ? AND legal_entity_id = ? AND environment = ?
          AND document_date >= ? AND document_date < ?
          AND (document_date, id) > (SELECT document_date, id FROM boundary)
        ORDER BY document_date, id
        LIMIT 50001
    )
    ORDER BY document_date, id
    LIMIT 100001
), checked AS (
    -- Existing bounded LEFT LATERAL ID/context lookup and checks, unchanged.
    ...
), folded AS (
    -- Existing single count/validity/currency/money fold, unchanged.
    ...
)
-- Existing flags, metadata and observation time, unchanged.
...
```

This is one statement, not application pagination, multiple snapshots, a retry loop or a new business engine. Only first_keys is explicitly materialized, with two fixed-width columns and at most 50000 rows. Its reuse supplies the first range and the last composite key. Do not materialize checked/payload/full candidate rows. The scalar boundary is computed once; it is not a per-document query. The final prototype retains `WITH candidates` so existing execution-barrier hooks can still identify the statement; tests must assert those hooks actually fired.

Observed plan: ordered indexed first range → narrow CTE reuse; a **one-element top-N** over those already bounded keys finds the boundary (25 KiB, no disk); an indexed second range uses the strict composite seek condition plus the complete context/month predicates; **Merge Append → outer Limit** preserves the global order; existing one-row LEFT LATERAL payload access follows. There is no sort of the complete fiscal candidate population. The scalar-boundary form avoids the extra 50001-row ordering sort seen in an intermediate correlated-range prototype; use the final form above.

### Membership, cap and snapshot proof

Let S be all statement-visible keys satisfying the immutable tuple and half-open month predicates, in unique ascending `(date, ID)` order. P is its first min(50000, |S|) elements. If P is empty, the scalar boundary is null, no tail key qualifies, and the existing metadata/zero result survives. Otherwise let b be P's last key. Every key strictly after b forms the remaining ordered suffix. Taking at most 50001 of that suffix and merging it with P yields **exactly the first min(100001, |S|) keys of S**, without overlap or omission. The outer limit/order remains explicit. No assumption that ID grows with date is used.

At 100000 physical matches all are folded and a consistent month returns its complete eight buckets. At 100001 or more, the existing global count rejects before any successful external result/audit/last-use. The extra row is **detection-only for acceptance**, never a permission to return truncated totals. Internal arithmetic on selected sentinel rows is not an external partial result; malformed data/overflow also fail the whole response. Drafts and RG remain physical candidates, including either side of the new boundary. Do not filter them, unknown currencies or invalid rows before counting.

All ranges, payload lookups, flags, timezone and statement time share the same primary SQL statement snapshot. Inserts committed after snapshot acquisition cannot appear only in the tail; a concurrent draft update cannot change a selected key's payload version mid-statement. Deletion/void-like changes likewise resolve wholly before or after the snapshot, subject to existing fiscal immutability rules. This review authorizes no new void operation or issued-document update. Unsupported states still fail closed; issued documents are not rewritten. Existing fiscal immutability enforcement remains unchanged. Key rereads use unique ID plus the same tuple; missing payload still fails closed rather than reducing the selected count. Authority remains freshly checked before the dedicated transaction, audit after it.

SQLite may retain the current single-range ordered candidate CTE and unique-ID/context left join: it selects exactly the same S prefix and executes identical financial/control expressions. A driver-specific key-access fragment is sufficient; do not duplicate the billing engine. Preserve SQLite overflow behavior and cross-engine result equivalence.

## 5. Measured alternatives and final feasibility

Measurements are local feasibility evidence, not an implementation pass or production performance guarantee. Every final prototype's financial/control rows matched the unchanged original query, excluding only `as_of` from statements observed at different instants. Monetary strings were not cast to floats.

| Fixture / final nested prototype                                                                 | Selected keys / payload lookups |                      Execution | Spill |
| ------------------------------------------------------------------------------------------------ | ------------------------------- | -----------------------------: | ----- |
| Independent fresh valid 100000; no all-visible heap pages                                        | 50000 + 50000 / 100000          |                     244.467 ms | None  |
| Independent fresh exact overflow 100001; no all-visible heap pages                               | 50000 + 50001 / 100001          |                     244.142 ms | None  |
| Independent fresh 200001, final nested shape; no all-visible heap pages                          | 50000 + 50001 / 100001          |                     231.579 ms | None  |
| 200001, final nested shape after additive fixture                                                | 50000 + 50001 / 100001          |                     221.273 ms | None  |
| Independent fresh 200001, equivalent top-level scalar form                                       | 50000 + 50001 / 100001          |                     240.296 ms | None  |
| Sparse 100, with 100000 same-entity/month homologation distractors and over a million other rows | 100 + 0 / 100                   |                       0.357 ms | None  |
| Empty month on that large relation                                                               | 0 + 0 / 0                       |                       0.075 ms | None  |
| Exact overflow after three independent normal ANALYZE refreshes (visibility had improved)        | 50000 + 50001 / 100001 each     | 228.838 / 233.984 / 222.339 ms | None  |

The final nested shape therefore passes all three independently fresh dense/cap/over-cap populations with relallvisible=0. Each first range performs 50000 visibility heap fetches; each tail performs the remaining 50000 or 50001. The evidence separately records fresh and later visibility states; it does not represent later all-visible measurements as fresh-table proofs.

Added 100 synthetic backdated February-1 documents after the 200001 population, so higher IDs precede lower IDs in date order. A single read-only SQL multiset comparison against the original ordered LIMIT returned **100001 keys, 100001 distinct IDs, zero differences**. This diagnostic comparison is not the production query-plan resource gate; it intentionally includes a reference set comparison. It provides direct membership evidence beyond equal totals in uniform FT fixtures.

Rejected alternatives:

- **ID-only order:** changes the over-cap prefix on backdated dates and cannot promise identical membership. The measured version still spilled (222 temporary blocks) on the fresh fixture. The primary/composite ID indexes also lack a selective leading context/month range. No order amendment is justified.
- **Date-only order / remove ordering:** loses deterministic ties or violates the signed prefix. No accidental order component was found.
- **Equivalent composite date/ID range bounds alone:** measured 238.444 ms and 271 written temp blocks in the initial fresh dense comparison; did not fix access economics.
- **Unordered two-range UNION:** membership is equivalent, but the selected design also retains explicit global ordering. Do not rely on physical Append iteration order.
- **Correlated tail with outer ordering:** no spill in the measured case, but it introduced an avoidable 50001-row in-memory sort. The scalar boundary gives ordered merge inputs directly.
- **Accept 2160-KiB spill because execution is fast:** rejected. A measured no-spill query-only alternative exists under the same budget; there is no justification to relax the resource contract.
- **More memory, forced paths, manual-vacuum prerequisites, smaller fixtures, higher cap or new index:** neither required nor approved. The existing index supports both selected ranges with recent-row heap visibility checks.

## 6. Updated structural PostgreSQL gate

Replace obsolete SQL-string/“exactly two Limit nodes”/blanket-AS-MATERIALIZED assertions with the following stronger semantic/structural checks. Preserve every prior fiscal, isolation, deadline, no-spill and result assertion. Do not merely delete a failing assertion. No specific index **name** is required; inspect the actual catalog definition and scoped access conditions.

1. Retain independent 0, sparse 100, valid 100000, exact-overflow 100001 and dense-overflow 200001 populations, each with at least 1000000 out-of-month distractors where already required. Retain the original skewed sparse/dense case. Same-month foreign workspace, sibling entity and **same workspace/entity homologation** populations must be excluded in access predicates. The current independent homologation fixture belongs to another entity/workspace, so it does not alone prove that last boundary. Add varied/backdated dates and draft/RG/mixed states without reducing volumes.
2. Identify the two fiscal key-access ranges, their outer candidate order/limit and boundary dependency. First range limit 50000; second limit 50001 and strict `(date,id)>boundary`; total unique keys min(N,100001). Each fiscal range executes at most once, is an ordered B-tree Index/Index Only Scan, applies workspace/entity/environment and both month bounds at index access, and the tail applies its composite seek there. No unrelated fiscal scan, bitmap/heap-order scan plus full candidate sort, repeated OFFSET traversal or whole-month count. Zero heap fetches is **not** required. Record actual rows, loops, filters, index conditions, buffers, estimates and visibility.
3. The first-key CTE has only ID/date, at most 50000 rows and no temp I/O. Its bounded reuse is allowed. Boundary selection reads only that CTE, returns at most one key and uses constant-memory top-N or equivalent bounded reduction; no new fiscal scan for the boundary. Do not infer materialization size from Plan Width alone. Do not require every internal row operation to total 100001: CTE rereads revisit already selected keys, not additional fiscal candidates.
4. Global candidate order remains date/ID and outer limit 100001; ordered merge/access may satisfy it without a Sort. No unbounded candidate ordering work is allowed. The boundary's bounded one-key selection is an explicit permitted sort exception, **after** first-range limiting. Small sparse currency-fold input sorts may remain in memory; the group domain stays eight currencies plus one null control group. The earlier blanket prose restricting every result sort to nine input rows did not describe the already passing sparse plan, which sorts 100 input rows. The resource gate is structural bounded work and no disk/temp spill, not a mandate that the planner always choose HashAggregate.
5. Payload access is left-preserving, unique-ID plus explicit same tuple, at most one row per key and at most 100001 executions. No second full-month payload scan/hash side; no payload hydration, customer/line/evidence joins or second range predicate per point. Missing payload sets invalidity. Both branches must feed the **same** existing fold exactly once.
6. At **every node**, zero Temp Read/Written Blocks, zero disk hash usage/spilled batches and no Disk sort. Record hash/sort memory where EXPLAIN provides it. Final aggregate has at most nine groups; final API has exactly eight fixed buckets. Do not accept ~250ms as a substitute for these checks.
7. Full production-shaped statement stays below 2000ms and executes under actual read-only/2000ms/250ms protections, with settings and transaction restored on success/failure. Verify both EXPLAIN and actual capability/API calls. At 100000 valid data, complete exact amounts; at 100001 and 200001, generic 503/no data/no success audit/no last-use/guard release, including HEAD. Tests must reach these result checks independently of a previous failed fixture.
8. ANALYZE after each independent load. Retain evidence for freshly rebuilt/low-visibility rows **and** repeated normal statistics refreshes; record relpages, reltuples, relallvisible, relevant pg_stats, index definitions/sizes and native settings alongside every plan. Do not assume ANALYZE sets visibility or deliberately suppress normal vacuum. At least three normal ANALYZE repetitions and a freshly rebuilt dense/cap/over-cap matrix must remain stable. If selected plans spill or lose bounds on required distributions/target PostgreSQL, stop for Astra; do not tune around the failure.

## 7. Exact regression work and retained coverage

All 28 additions remain: **19 API cases, four lifecycle cases, five independent PostgreSQL volume cases**. The API additions exercise real issuance/credits/rollback; supported and unsupported draft control groups; support GET/HEAD attribution; ten debug/audit/error variants; real receipts/allocations/withholding/overpayment; authoritative poll/rebuild invariance; integration quota alias/rotation sharing; and a six-integration workspace quota case. Lifecycle additions exercise production grants/rotation/original-membership deletion-recreation and three homologation refusals. The five volume cases are the structural matrix above. Their historical passes do not certify the new prototype.

Two coverage limits must remain explicit: the workspace-quota test's title mentions human identities but its six callers are integrations; its title is not human quota proof. The debug “cache” variant changes to an unsupported store, not an actual database-cache outage. Complete those missing cases; do not relabel them as done. The four-case accounting distinction in section 1 also remains explicit.

Sol must add/complete these tests, reusing domain helpers and preserving existing assertions:

- Exact candidate-ID multiset/order parity for 0, 1, 49999, 50000, 50001, 99999, 100000, 100001 and 200001; shared-date ties spanning the 50000 boundary; nonmonotonic ID/date insertion; first/last month boundaries; no duplicate/omitted key; empty boundary; all eight currencies and control-null group. Include mixed types/statuses, drafts/RG at both boundaries, invalid data at positions 50000/50001/100000/100001, and overflow caused entirely by excluded monetary rows. At most 100000 returns complete totals only if all data checks pass; 100001 never returns partial totals.
- Real issue/credit transactions committed before/after actual statement snapshot acquisition; rolled-back source transaction; draft date/payload changes across the range boundary. Use independent-process barriers and assert hooks fired. Show either the complete pre-commit or complete post-commit result, never mixed membership/payload or double counting. Do not introduce an unsupported void operation; test relevant existing deletion/immutability rejection and rollback paths. Receipt commit and qualified projection poll/rebuild cannot change billing totals.
- Complete unknown/incomplete/contradictory/stale projection invariance without changing SA1 or frozen evidence; full human/machine/support durable-audit cancellation/unsaved/buffered/exception/rollback and generic GET/HEAD/error/redaction parity; actual cache outage, lock contention/250ms timeout, connection-loss cancellation and worker death/10-second lease recovery.
- Effective-human and mixed human/integration quota sharing, recorded native-minute windows and legal boundary bursts; preserve inherited quotas. No Carbon-only native-clock proof. Lifecycle/sponsor freshness, scope isolation and all previous signed regression gates remain.

No case authorizes fiscal writes through the API, different status semantics, new credentials or live AGT traffic. Real domain fixtures remain isolated with fake signer/gateway/network and queue protections. Complete the still-open signed acceptance matrix in the implementation report before requesting final trust-boundary review.

## 8. Review validation and limits

This is a design review with disposable SQL feasibility experiments. Existing runtime deliberately remains failing until Sol implements the selected shape. The unchanged original dense test and independent 100000/100001 tests reproduced the expected temp-I/O failure; the unchanged 200001 test passed its structural and actual no-partial API assertions. Test-log details, repeated attempts, fresh-visibility confirmation and artifact checks are retained below and in the evidence file.

| Review execution of unchanged test                    | Result                                    | Assertions | Seconds |
| ----------------------------------------------------- | ----------------------------------------- | ---------: | ------: |
| Original sparse/dense                                 | Expected dense spill failure              |         74 |  54.784 |
| Independent 100000                                    | Expected spill failure                    |         10 |  55.759 |
| Independent 100001, first reproduction                | Expected spill failure                    |         10 |  54.056 |
| Independent 100001, immediate final-prototype capture | Expected spill failure                    |         10 |  54.170 |
| Independent 200001, first reproduction                | Passed including actual overflow response |         78 |  60.416 |
| Independent 200001, immediate final-prototype capture | Passed including actual overflow response |         78 |  59.021 |

Two initial filter attempts selected zero tests; neither is counted as validation. All final-prototype experiment results were programmatically compared against the unchanged statement. Recursive validation confirmed zero temporary/disk spill at every node, fiscal access through Index/Index Only Scan and execution below 2000ms across all ten retained final nested-shape measurements. Exact membership comparison additionally returned zero differences. These comparisons do not replace Sol's required adversarial/application/concurrency tests.

Historical implementation gates remain **1477 SQLite passes / 9136 assertions; PostgreSQL API 713 passes / 5100 assertions; full PostgreSQL concurrency/plans 81 passes / four failures / 1316 assertions; PHPStan 21 inherited diagnostics, zero new; types/Pint passed; inherited ESLint 9719 errors and four resource-format failures**. They were not rerun wholesale for documentation-only review and are not new green claims. Sol must run all full gates after implementation. No tests or baseline were weakened. Hosted CI, production PostgreSQL/version/distribution, rollout/backup/restore and operational cancellation/logging remain release requirements. No new AGT homologation evidence exists.

Only the new Round 2 report/evidence and a supersession notice in the Round 1 report are review artifacts. External configuration was checked: enabled=false. No live database, migration, production service, deployment or subsequent roadmap work was touched.

Review-start SHA-256 comparison confirms every pre-existing application, test, migration, configuration, route and specification file is unchanged; the sole modified pre-existing document is the Round 1 supersession notice. Both new artifacts parse/format successfully, all three changed documentation files pass Prettier, and `git diff --check` passes. Fresh dense/cap/over-cap evidence was also checked programmatically for exactly two scoped key scans, one execution per range, exact 50000 plus 50000/50001 key counts and corresponding fresh-row heap fetches. The disposable PostgreSQL server was stopped after capture.

## 9. Exact bounded Sol Medium handoff

> Resume only Phase 4D using Sol Medium. Read the signed Phase 1–4C authorities, A1/SA1, Phase 4D analytics contract and normative OpenAPI, current implementation report, historical Round 1 review and this Round 2 review/evidence. Execute only this corrected bounded remediation handoff. This is permission to finish the existing implementation, not final Phase 4D sign-off or external enablement.
>
> In BillingSummaryQuery, implement the exact PostgreSQL two-range candidate CTE in section 4: first_keys is only ID/date, ordered and MATERIALIZED at LIMIT 50000; compute its last composite key once; take at most 50001 strictly subsequent keys with the same explicit tuple/month predicates using the scalar boundary; merge with UNION ALL and retain global ORDER BY document_date,id LIMIT 100001. Preserve the existing bounded left payload lookup, single financial/count/invalid fold, null control accounting, metadata and statement time. Keep first_keys bounded/reused without wide materialization. Preserve the `WITH candidates` execution hooks or update and assert them explicitly. SQLite may retain its semantically equivalent single ordered range and supported left point join; share all business/control expressions.
>
> Do not change either migration, any existing index, dependency, API/specification bytes, capability/scope, monetary/status/date/currency definition, identity/context/sponsor authority, production-only boundary, quota identity/budget, workspace guard, transaction/statement/lock deadlines, read-only behavior, audit/redaction/correlation/last-use, GET/HEAD semantics, A1 or SA1. External access remains disabled. No memory/session tuning, hints/extensions, disabled plan features, manual-vacuum prerequisite, smaller fixtures, larger cap, partial/cached totals, additional business capability, live AGT operation or next phase.
>
> Replace obsolete exact-two-Limit/no-MATERIALIZED/index-name checks with every structural and semantic gate in section 6. Keep no-spill and fixed resource limits. Add the exact boundary/membership/snapshot/adversarial cases in section 7, retain all 28 additions and all previous tests, and finish every still-open signed acceptance item. Record actual plan/statistics/visibility/settings evidence on independently fresh 100000/100001/200001 fixtures, sparse/empty and context distractors, and three normal ANALYZE repetitions. Verify the actual valid-cap response and both overflow responses independently. If the approved query shape fails required bounds or reveals a signed contradiction, stop for Astra rather than adding another optimization or loosening a gate.
>
> Run the complete SQLite suite, full PostgreSQL concurrency group, complete Phase 4D/inherited PostgreSQL API/integration selection, PHPStan exact inherited-diagnostic multiset comparison, types, Pint and changed-file syntax/lint/format/diff gates. Report every failed attempt and fix, including clock flakes without hiding the complete-run result. Update the Phase 4D implementation report with exact files, remaining coverage, measured plans and all validation. Conclude PHASE 4D IMPLEMENTATION — READY FOR ASTRA REVIEW or PHASE 4D IMPLEMENTATION — NOT READY. Stop for the separate final Astra Phase 4D trust-boundary review; do not perform that review yourself, enable external access or proceed to another phase.

**PHASE 4D QUERY-PLAN REMEDIATION ROUND 2 — APPROVED FOR SOL**
