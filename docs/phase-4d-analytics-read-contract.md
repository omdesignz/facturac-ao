# Phase 4D — bounded recorded billing analytics read contract

Decision date: 2026-10-07. Astra design only. Governing authorities are the signed Phase 1–4C contracts, implementation reports and trust-boundary reviews, including A1, SA1 and the exact next handoff in `phase-4c-trust-boundary-review.md`. Earlier full governing-document and master-roadmap reads were reused; the current Phase 4C review, analytics sources and relevant domain/tests were inspected directly. This document does not amend SA1 or historical reports.

**PHASE 4D DESIGN — APPROVED FOR SOL IMPLEMENTATION**

Approval is for the smallest useful recorded-billing summary below. It does not approve an external receivables, collections, aging, financial-statement or historical-balance API. Those metrics require further reconciliation of existing domain semantics. No implementation, migration or external enablement occurs in this design phase.

> **Final Astra review erratum H1 — 2026-10-08:** The normative OpenAPI Cache-Control literal is corrected to `no-store, private`, matching the existing Laravel/Symfony response and preceding read-plane specifications. The directives, privacy/no-cache policy and runtime behavior are unchanged. This explicitly corrects documentation/transport parity; no financial, authorization or resource-bound decision is amended. See `phase-4d-trust-boundary-review.md`.

## 1. Inventory and scope decision

| Existing source                                                | Actual semantics / issue                                                                                                                                                                                                 | Phase 4D disposition                                                                                                                                                          |
| -------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ReceivablesQuery::billableTypes`, `summarise`, `totalsByType` | FT/FR/GF/ND billing, NC credits, integer stored totals. `issued()` is merely non-draft; `forLegalEntity()` scopes entity/workspace/entity currency but not environment.                                                  | Reuse the shared billing classification and conditional sum arithmetic through a bounded scoped query; do not export the existing aggregate unchanged.                        |
| `withBalances`, `outstandingMinor`, `paidMinor`                | Present non-draft receipt allocations and linked NC values; FR counts paid immediately. Current cohort balance, not historical balance.                                                                                  | Reuse later only after the balance contract is resolved. No payment or balance fields now.                                                                                    |
| Entity/customer `summarise` outstanding                        | Floors each invoice at zero, then subtracts standalone NC at aggregate level. Aging totals instead add invoice balances. Standalone customer credits can therefore affect a broader entity total differently from aging. | Explicit reconciliation dependency; do not silently choose one result as universal debt or modify the browser.                                                                |
| `AgingQuery`                                                   | Loads customer documents/contact-adjacent commercial data; due-date buckets 0, 1–30, 31–60, 61–90, 91+; null due date currently goes into current. Statement computes separate running movements.                        | No customer rows, statements or aging surface. Avoid wholesale reuse of its loading/serialization.                                                                            |
| `AnalyticsController`, `AnalyticsPeriod`                       | Browser workspace and oldest entity; document-date periods; several independent queries; unknown period falls back; trends and customer rankings; operations include submission lifecycle counts/durations and stock.    | None of these controller defaults or representations becomes an API contract. Reject invalid input, never fall back. Trends, rankings, stock and operational ratios excluded. |
| `DashboardQuery`                                               | Uses receivables plus qualified `CurrentAgtState` predicates; totals/focus/collections are separate views at potentially different instants.                                                                             | No direct dashboard export. Existing UI compatibility is preserved.                                                                                                           |
| `FiscalReceiptCalculator`, `IssueFiscalDocument`               | Gross allocations with tax/withholding proportions, positive net receipt restrictions, signed treatment of NC sources; source eligibility revalidated through SA1.                                                       | Authoritative for issuance/allocation; analytics must not recreate those decisions. Payments/withholding do not change issued invoice totals.                                 |
| `FiscalCalculator`, fiscal currency configuration, SAFT        | Integer minor units (scale 2); eight accepted currencies; frozen FX used for separately defined print/SAFT conversions.                                                                                                  | Separate native-currency buckets only; neither SAFT AOA totals nor current FX becomes billing totals.                                                                         |
| Fiscal issue fields and immutable guards                       | Local finalization writes document number, issue/frozen timestamps and signing evidence atomically. AGT projection is distinct. Existing analytics fixtures often only assign raw valid/number.                          | Require local issue markers for this new metric; raw non-draft/valid alone is insufficient. Do not rewrite fixtures used to document old UI semantics.                        |

The original roadmap calls for analytics within the read-first foundation. A monthly recorded-billing summary is useful without deciding revenue recognition, debt collectability or historical cash. Narrowing the initial metric set is intentional data minimization, not completion of every reporting idea in the roadmap.

## 2. Exact capability and shared implementation boundary

One capability: `analytics.billing.read`. One external scope: `analytics:billing:read`. One GET/HEAD route:

`/api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/analytics/billing-summary?month=YYYY-MM`

Suggested existing-structure components: `app/Fiscal/AnalyticsCapabilities.php`, a typed monthly read command under `app/Fiscal`, and `app/Analytics/BillingSummaryQuery.php`, with a thin V2 controller and explicit resource. Names may follow sibling conventions; semantics may not change. Reuse `DocumentReadContext`, private integration resolution, `RequiredAudit`, rate-limit infrastructure and the shared billing type/arithmetic definitions. A permission-specific method on `ExecutionContext`/`IntegrationReadContext` is required; accepting an arbitrary DTO as authority is forbidden.

The query belongs to the shared analytics/application layer. Extract the common billing type/conditional-sum definitions used by `ReceivablesQuery` if needed so the UI and external capability do not develop independent financial definitions. Preserve existing UI output and method signatures. No frontend conversion to this endpoint, broad rewrite of aging/receivables, second accounting engine or duplication of financial rules inside controllers/resources. Tests must compare the existing and new shared billing arithmetic over the same eligible fixture population, accounting explicitly for the new environment, currency and issue-evidence boundaries.

The exact billed type set is **FT, FR, GF, ND**. Credits are **NC**. **RG** is recognized but contributes neither count nor monetary billing value. These six codes are the entire supported issued-type universe for this version; any other non-draft type (including another known enum such as FA) fails the whole summary with generic 503 instead of being silently omitted. No future addition to `FiscalDocumentType::issuable()` automatically expands this contract. Existing shared classification must agree with this frozen set; additions require a versioned design review. Transport documents, quotes, SaaS subscription charges/receipts, hosted payment statuses and stock never enter this query.

## 3. Population, fiscal state and metrics

The physical candidate population is all `fiscal_documents` rows in the authenticated workspace, legal entity and **production** environment with document calendar date in the requested month. Candidate inspection is bounded as in section 8; no customer/line/evidence relationship is loaded.

A normal draft contributes nothing. A counted issued document must have a recognized non-draft fiscal enum, nonempty document number, non-null `issued_at` and `frozen_at`. These establish a locally recorded immutable issue fact under the existing database/application guards; they do not cryptographically reverify a document or establish AGT acceptance. A non-draft candidate missing these markers, a draft with contradictory issue markers, unknown type/status/currency, negative stored totals, or `net_total_minor + tax_payable_minor != gross_total_minor` is unsupported/inconsistent data: return generic 503 for the entire summary, never a misleading partial total. Normal drafts need no issue markers or valid financial totals. Validate currency/type/totals for issued candidates, including recognized RG, before excluding RG from billing. The query never repairs or signs a record.

Recognized non-draft statuses are issued, received, processing, valid, invalid and contingency. They describe retained local state, **not evidence-qualified acceptance**. AGT unknown, incomplete, conflicting, stale, invalid, request rejection, processing cancellation, retry or refresh does not remove an immutable locally issued invoice/credit from recorded billing. These outcomes do not by themselves reverse the fiscal ledger under SA1. A properly issued NC contributes its own credit in its own document-date month; no automatic reversal, deletion or inferred cancellation is permitted. Already issued receipts/settlements remain unchanged. No claim of legal validity or collectability follows from inclusion.

No AGT metric or status grouping/filter is exposed here, so the query must not join raw submission status, reimplement the reducer, or invoke receipt eligibility. Future AGT-qualified analytics must reuse `CurrentAgtState` and a separately signed aggregation contract. V1 remains legacy summary; the Phase 4C qualified representation remains unchanged.

For each currency, let B be eligible FT/FR/GF/ND documents and C eligible NC documents in the month. All sums use frozen document-native totals:

| Exact field                 | Definition                                                                               |
| --------------------------- | ---------------------------------------------------------------------------------------- |
| `billed_document_count`     | Number of B documents, including debit notes and FR once each. Not all issued documents. |
| `credit_note_count`         | Number of C documents, whether linked to a current/prior-month invoice or standalone.    |
| `invoiced_gross_minor`      | Sum B.gross_total_minor, before credits.                                                 |
| `invoiced_net_minor`        | Sum B.net_total_minor, before credits.                                                   |
| `invoiced_tax_minor`        | Sum B.tax_payable_minor, before credits.                                                 |
| `credit_gross_minor`        | Sum C.gross_total_minor, expressed as a nonnegative credit magnitude.                    |
| `credit_net_minor`          | Sum C.net_total_minor, nonnegative magnitude.                                            |
| `credit_tax_minor`          | Sum C.tax_payable_minor, nonnegative magnitude.                                          |
| `after_credits_gross_minor` | invoiced_gross_minor minus credit_gross_minor; may be negative.                          |
| `after_credits_net_minor`   | invoiced_net_minor minus credit_net_minor; may be negative.                              |
| `after_credits_tax_minor`   | invoiced_tax_minor minus credit_tax_minor; may be negative.                              |

For all three families, net + tax = gross. Do not floor signed after-credit values at zero. Credits are counted once by their own document date, never once via a source balance and again as a movement. Linked credit/source months can differ. FR contributes billing once; later receipt allocations never contribute billing again. Partial/full payment, withholding, overpayment attempts and payment method/date do not change these metrics. Discounts already reflected in frozen totals are not recalculated. These are recorded invoicing amounts, not recognized revenue, profit, bank-confirmed cash, outstanding debt, VAT remittance or fiscal authority. For example, an AOA invoice gross 10000/net 8600/tax 1400 and same-month NC gross 2500/net 2150/tax 350 yield after-credit gross 7500/net 6450/tax 1050. A partial receipt does not change those values. If that NC is dated next month, the first month remains 10000 gross and the next month can be -2500 after credits. USD amounts are never added to the AOA bucket.

## 4. Currency, precision and date rules

Phase 1 requires separate native-currency buckets. Return exactly eight buckets, including zero buckets, in this fixed ASCII order: **AOA, BRL, CNY, EUR, GBP, NAD, USD, ZAR**. This is the current approved currency set frozen for this metric version. No currency query/filter or grand total exists. Company base-currency changes cannot remove historical foreign billing. No FX multiplication, current rate, SAFT conversion or base-currency relabelling. A newly configured currency requires Astra contract review before expansion; it must not appear automatically.

Every bucket has `currency_code`, `minor_unit_scale=2`, the two counts and nine money fields above. Money is a canonical decimal **string of integer minor units**, never a JSON float/number: no plus sign, leading zeros, decimal point, exponent, whitespace or negative zero. Nonnegative sums must be within 0..9223372036854775807; signed differences within -9223372036854775807..9223372036854775807. Counts are JSON integers 0..100000. PostgreSQL numeric SUM results must be range-checked as strings before any PHP cast; SQLite integer-overflow errors close the read with 503. No saturation, floating conversion, wraparound or truncation. Subtraction occurs only after safe range checks. No new rounding occurs; preserve the fiscal engine's stored half-up/allocation results.

The only parameter is required `month=YYYY-MM`, valid calendar year 2000..9999 and month 01..12, no later than the current month in the entity's approved timezone. One complete calendar month, at most 31 days; no defaults, relative period aliases, arbitrary ranges, granularity, status, currency, customer, type, amount, group, sort, page, cursor, search or `as_of` input. Unknown, repeated, array/bracket or malformed query keys/values return 422; inspect the raw query for duplicates rather than trusting PHP's last-value collapse. Canonical percent decoding is allowed once; normalized duplicate names still reject. Do not log raw query text.

Use the legal entity's timezone field, but this first Angola contract supports exactly `Africa/Luanda`, as required by Phase 1. Missing/other timezone is a configuration failure (503), never a silent fallback to app/server/browser timezone. Supporting another zone requires a reviewed amendment. Validate this before business-record SQL. Date-only `document_date` is never UTC-shifted. Compare `>= first calendar day` and `< first day of next month`, including the PostgreSQL DATE and SQLite midnight serialization representations. No driver-specific truncation of a column that defeats index range use. The returned period is `from` and `to_exclusive`, both date strings, and the original `month`.

`as_of` is the UTC database statement observation time, RFC3339 to seconds with `+00:00`, from the same SQL statement/snapshot as the metrics. It means **committed records visible now**, not a historical cutoff supplied by the caller or the AGT's effective time. Use PostgreSQL `statement_timestamp()` for this purpose, not an earlier transaction-start timestamp. Later backdated issuance can change a prior month's result. Current-month future document dates are included if a locally issued record already exists and satisfies the issue markers; a request for an entirely future month rejects. `issued_at`, `created_at`, payment date, due date and AGT observation date do not select the billing period. A changed midnight/current month during execution must not result in contradictory metadata: capture the validated calendar boundary once and use it consistently; statement time remains the actual database observation.

## 5. Receivables, collections and aging: explicit deferral

These definitions explain the inventory and prevent relabelling; they authorize **no fields or additional implementation** in this phase.

- Per-invoice recorded outstanding is conceptually max(0, gross less applicable issued credit adjustments less issued gross settlement allocations) in the same currency/context. Partial payment means a positive settlement and a still-positive residual after applicable credits. These are recorded balances, not proof of legal collectability.
- Existing UI uses current non-draft allocations/credits, including later document/payment dates, for a selected invoicing cohort. It cannot answer historical as-of balances. Its aggregate subtraction of unapplied NC must not be silently reused to offset another customer's invoice or made inconsistent with aging. Separate unapplied credit/refund values and their lawful allocation need a future contract grounded in the receipt/credit engine.
- `FiscalReceiptCalculator` allows NC source allocation with a negative factor and requires an overall positive receipt, while stored allocation magnitudes are positive. Withholding reduces cash paid without changing gross settled liability. Consequently `paid_minor` is not automatically recorded cash collected; FR gross and RG gross are not blindly additive bank cash. No new collections arithmetic is approved here.
- Overdue under Phase 1 means positive outstanding and due calendar date strictly before today in Africa/Luanda; due today and future due dates are not overdue. Null due date supplies no evidence of lateness. Existing aging buckets are current (days <=0), 1–30, 31–60, 61–90 and 91+. Existing null-as-current presentation needs explicit unknown-due treatment before an external aging contract. Calendar days, not elapsed hours, define those boundaries.
- Incomplete/unknown/conflicting AGT evidence never makes receipt issuance more permissive. Conversely a later rejection/cancellation label must not retrospectively erase an issued settlement or invoice balance without an authorized correction. AGT operational status is not a write-off instruction. Future-dated invoices, payments/credits, reversed settlements, excess credits, unsupported legacy allocations and historical cutoff semantics need dedicated fixtures and reconciliation before exposure.
- Time series, comparisons, customer concentration, average days-to-settle, submission acceptance ratios and document-state counts remain deferred. No historical record is reconstructed into fiscal certainty. This phase introduces no predictive analytics, reports/export surface or customer-level aggregates.

## 6. Authority, disclosure and errors

`analytics:billing:read` grants only `analytics.billing.read` and implies none of documents/customers/catalogue/qualified-AGT scopes; none implies it. `reports:read` is a roadmap concept, not an implemented all-purpose authority. Do not add it, `analytics:read`, wildcards/admin/read-all or a future-scope prefix match. The new exact grant must exist on both integration ceiling and immutable credential grant set. Defaults remain documents:read; no existing integration/credential receives a grant automatically. Rotation/reduction/revision/lifecycle rules remain as signed; homologation integration issuance with the new scope must be rejected by lifecycle validation, while raw invalid historical grants still fail at execution.

Machine authority remains credential identity ∩ parent/credential scopes ∩ immutable workspace/entity/environment binding ∩ current original sponsor authority ∩ capability authorization. Revalidate on the primary immediately before the protected read; no browser/session fallback, serialized authority, sponsor replacement through a new membership, delegation, impersonation or agent headers. The operation is **production-only**, enforced in the shared capability as well as the adapter; homologation returns 403 before fiscal/business-record SQL, independent of data existence. Production here is a context label, not AGT enablement or external rollout.

Human direct capability use requires explicit read context, verified email/MFA and active owner, administrator or accountant membership. Billing/viewer roles receive no new analytics permission. Recheck role/context at invocation; do not change their existing document permissions. Read-only support impersonation follows existing dual real/effective attribution and the effective actor's allowed role; it confers no new authority. No new human HTTP endpoint or UI is included. Reject human contexts carrying an automation ID; scheduled/agent use is not authorized by this read grant.

Preserve the existing V2 protocol: global disabled 404; TLS required; normal shared quotas; bearer authentication; client correlation/delegation/body checks; capability authority, closed path/query validation and context comparison; analytics-specific quota/concurrency gate; query; complete allowlist and numeric/JSON validation; durable audit; monotonic successful last-use; response. The capability revalidates even if the controller already checked. Matching malformed path selectors return 422 only after appropriate authentication/authorization; syntactically valid wrong binding returns 403 without business-record lookup. No resource-specific 404 exists for an empty period: authorized empty months return all-zero buckets. Unknown routes remain 404.

Inherited error codes are exact: 401 AUTHENTICATION_REQUIRED (WWW-Authenticate: Bearer), 403 FORBIDDEN, 404 NOT_FOUND, 405 METHOD_NOT_ALLOWED (Allow: GET, HEAD), 422 VALIDATION_FAILED, 429 RATE_LIMITED (Retry-After), 503 SERVICE_UNAVAILABLE, 500 INTERNAL_ERROR only for required denial-audit failure. Bodies use the existing generic Portuguese message, error/meta-only allowlist and request ID. Do not return row counts, currencies present, query plans, timing breakdowns, bound overflow values, configuration details or reasons for a 503. No automatic SQL/network retry or partial/cached fallback. HEAD performs exactly the same authority/query/audit/quota/last-use work and returns no body on success/error. Normalized encoded paths cannot obtain automatic OPTIONS 200. Conditional headers do not produce 304, ETag or Last-Modified. Always `Cache-Control: private, no-store`, `X-Request-ID`; application log/report sanitization covers the new route.

Only trusted context public IDs and aggregate billing amounts are disclosed; no customer/NIF/contact/address/name, document number/ID, price/line/stock, raw evidence, status, signature, hash or credential. The scope intentionally grants entity-wide monthly financial visibility, which may involve only one invoice; it is **not** an anonymity or differential-privacy guarantee. Fixed month, no customer/dimension filters, fixed currency buckets and quotas reduce differencing opportunities without making false privacy promises. Further suppression/randomization would change financial truth and requires a separate contract.

## 7. Snapshot, audit and side effects

All metrics, candidate-limit/consistency flags and observation time must come from **one primary-database statement**. No independent per-metric selects and no PHP loading of fiscal models. Use a bounded derived relation/CTE, conditional aggregates by currency and one captured database time. The bound legal-entity metadata may be joined to confirm its timezone still matches the validated context in this statement; a changed/unsupported timezone fails closed. No AGT/submission, payment/settlement, customer or line joins. The entire result reflects the statement's committed snapshot, so a concurrent issue/credit commit is wholly before or after the result. Polling/rebuild cannot change recorded billing totals. Do not use long REPEATABLE READ authority snapshots, replicas or locks on fiscal rows to solve consistency.

For PostgreSQL, execute the statement inside a short dedicated top-level read-only transaction with SET LOCAL time limits as specified below; then end it before required audit. Reject an already open application transaction for this capability (503), rather than claiming audit is durable inside an unknown outer transaction. SQLite tests use a short equivalent read transaction; PostgreSQL proves read-only/time-limit behavior. Authority is revalidated just before this transaction, not from inside a stale historical snapshot. A revocation committed before final authorization denies; one committed after that point may allow this read to finish, and subsequent invocations deny. No promise about future authority or remote AGT knowledge.

Audit event/operation `analytics.billing.read`; denial `analytics.billing.read.denied`; `capability_version=2`, `metric_version=recorded-billing-v1`, method, trusted actor/sponsor/context/correlation, distinct operation UUID and outcome. No target document exists: omit resource ID/subject document. Machine causer remains integration; human is human with existing support attribution. Explicitly suppress ambient User-Agent for human and machine. Do **not** record raw/normalized month, result counts/amounts, currency presence, response body, query hash/SQL/bindings, projection/evidence or untrusted context selectors. Safe failure logging is request/operation ID and generic code only. Server request ID and bounded client ID remain distinct.

Required success audit must actually persist, unbuffered, after the read transaction commits and before successful last-use/response. Audit refusal, cancellation, exception, buffering or serialization failure withholds data (503) and does not mark success. Authenticated protocol denials preserve existing required denial audit, with generic 500 if it fails. Cache/quota accounting and the named audit/last-used writes are the only allowed operational side effects. No domain write, job, AGT call, backfill or reconciliation occurs.

## 8. Cost, quotas and migration proposal

No caching, export, precomputed materialization, arbitrary grouping or async report job. Maximum one month, eight fixed buckets and **100,000 physical candidate rows**. The bounded SQL must materialize at most 100,001 visible candidates ordered by `(document_date, id)` within the immutable tuple/month using the approved index. The extra row is an overflow sentinel; if present return 503 and no partial data. The cap includes drafts and RG so exclusions cannot hide unbounded candidate aggregation. This is a logical visible-row bound, not a claim that MVCC/index page I/O is identical to that count; the timeout and measured plan bound the remaining work. Validate/fold within the same statement; do not first count the entire tenant or scan unrelated records. Successful result cardinality is eight irrespective of customer/document cardinality.

The existing IP120/integration60/workspace300 per native fixed minute limits remain unchanged and shared with V1/V2. Additionally, this shared capability consumes native-minute **analytics integration 6 / analytics workspace 30** counters. Human direct calls use **analytics human-user 6 / the same analytics workspace 30**; human identity is the effective user plus workspace, not ambient session. Keys derive only from validated IDs and this fixed operation family, never credential, month, verb, path encoding or request ID. Failed admitted executions consume their attempt; no refund/replay exemption. No other capability is throttled by these added counters.

At most one billing query per workspace may execute at a time across callers. Use the existing shared database cache lock, workspace-bound, nonblocking, 10-second lease, and release in finally after the query transaction; lock contention returns 429 with Retry-After: 1. It is a resource guard, not a fiscal lock or authority token. The maximum statement duration is **2,000 ms** and PostgreSQL lock_timeout **250 ms**, both SET LOCAL and restored by ending/rolling back the dedicated transaction. Deadline/driver/lock backend failures return 503, with no result/audit success/last-use. SQLite remains a fast correctness test engine and cannot certify these limits. Do not add sleep/retry loops, renew a lock indefinitely, or run subsequent work if the query timed out. Release guards on every exception; process-death recovery is bounded by the 10-second lease. Deployment must ensure statements are cancelled on connection loss; the statement timeout remains the hard bound even if a worker dies. Network/server request timeouts and shared-store availability require operational verification.

Representative PostgreSQL acceptance must demonstrate the 100,001-row sentinel is bounded before aggregation, exact scoped index use at volume, no unrelated tenant scan as the access path, no N+1 or per-document hydration, no disk spill for the result aggregation and successful completion under the 2-second statement budget on the documented CI fixture. Record EXPLAIN (ANALYZE, BUFFERS), machine/runtime and data distribution. Test both a sparse month amid at least 1,000,000 distractor rows and a month at the 100,000 cap across eight currencies. If the plan/budget fails, Sol stops for Astra; it must not loosen the cap/timeout, invent a cache or return partial results.

Only two migrations are authorized for the later bounded implementation:

1. Extend the two existing exact scope allowlists to add `analytics:billing:read`. Follow Phase 4C PostgreSQL transactional CHECK replacement and SQLite preserving-table/trigger/FK migration pattern; preserve every old grant and credential immutability guard. No grant backfill/default change. Down refuses while any new scope grant or named analytics read/denial audit exists, including revoked credentials; preserve historical evidence, forward repair if needed. Generic pre-route denial without the named operation does not claim analytics was exposed. No blanket deletion of V2 activity.
2. Add the named nonunique index `fiscal_documents_analytics_month_idx` on `(workspace_id, legal_entity_id, environment, document_date, id)`. No INCLUDE columns, partial predicate, backfill, new table/column or replacement of existing indexes. Up/down must preserve fiscal bytes, constraints, keys and triggers. Populated PostgreSQL/SQLite migration tests and interrupted transaction recovery are mandatory. Use maintenance with writers paused; ordinary transactional PostgreSQL index creation is approved only under that rollout condition. Do not claim online/zero-lock deployment. Drop only this index on rollback, after stopping the new reader.

No migrations are applied by this design. No dependencies change. Any additional schema, quota identity, currency, metric or architectural adjustment needs Astra review.

## 9. Exact wire contract / OpenAPI

Success has exactly `data` and `meta`. Data has exactly metric_version, date_basis, timezone, as_of, period and currencies. Period has exactly month/from/to_exclusive. Meta has exactly request_id/workspace_public_id/legal_entity_public_id/environment. Every bucket has exactly currency_code/minor_unit_scale and the eleven metric fields in section 3. All fields are required; none is nullable. No links or pagination. Integer money strings and exact ordering are normative; arithmetic relationships and current-month/range checks require semantic tests in addition to schema validation.

The embedded OpenAPI 3.1 document below is normative for the new surface only. During implementation publish it as `docs/openapi-external-billing-v2.json`; do not change the V1 or qualified-AGT schemas. Its `x-` semantics point to this signed document, not a hidden alternative. The exact error behavior and request order above take precedence over any generic framework defaults. Bearer OpenAPI security is not OAuth; required scope is described and tagged explicitly rather than inventing an OAuth flow.

```json
{
    "openapi": "3.1.0",
    "info": {
        "title": "Facturac external recorded billing read — Phase 4D design",
        "version": "1.0.0",
        "description": "Design only. External access disabled. Normative semantics: docs/phase-4d-analytics-read-contract.md. Production context is not production enablement. No fiscal authority, cash, debt, FX, status or customer-level metrics."
    },
    "security": [
        {
            "bearerAuth": []
        }
    ],
    "paths": {
        "/api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/analytics/billing-summary": {
            "parameters": [
                {
                    "name": "workspacePublicId",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "pattern": "^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"
                    },
                    "description": "Explicit binding selector; canonicalized to lowercase. Does not confer authority."
                },
                {
                    "name": "entityPublicId",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "pattern": "^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"
                    },
                    "description": "Explicit binding selector; canonicalized to lowercase. Does not confer authority."
                },
                {
                    "name": "environment",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "enum": ["production", "homologation"]
                    },
                    "description": "Only production is permitted. Homologation denies 403 before business SQL, independently of record existence."
                },
                {
                    "name": "month",
                    "in": "query",
                    "required": true,
                    "schema": {
                        "$ref": "#/components/schemas/Month"
                    },
                    "description": "Only allowed query key; repeated/array/unknown keys rejected, including encoded duplicates."
                },
                {
                    "name": "X-Client-Request-ID",
                    "in": "header",
                    "required": false,
                    "schema": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9._-]{1,64}$"
                    },
                    "description": "Optional bounded client correlation, never authority; distinct from server UUID."
                }
            ],
            "get": {
                "operationId": "readRecordedBillingSummary",
                "summary": "Monthly native-currency recorded billing summary",
                "x-required-scope": "analytics:billing:read",
                "x-capability": "analytics.billing.read",
                "x-unknown-query-parameters": "reject",
                "responses": {
                    "200": {
                        "description": "Complete recorded billing snapshot. Does not assert revenue, cash, debt or fiscal authority.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Success"
                                }
                            }
                        }
                    },
                    "401": {
                        "description": "AUTHENTICATION_REQUIRED",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "WWW-Authenticate": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "Bearer"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error401"
                                }
                            }
                        }
                    },
                    "403": {
                        "description": "FORBIDDEN",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error403"
                                }
                            }
                        }
                    },
                    "404": {
                        "description": "NOT_FOUND",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error404"
                                }
                            }
                        }
                    },
                    "405": {
                        "description": "METHOD_NOT_ALLOWED",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "Allow": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "GET, HEAD"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error405"
                                }
                            }
                        }
                    },
                    "422": {
                        "description": "VALIDATION_FAILED",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error422"
                                }
                            }
                        }
                    },
                    "429": {
                        "description": "RATE_LIMITED",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "Retry-After": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "pattern": "^[1-9][0-9]*$"
                                },
                                "description": "Positive seconds; 1 for nonblocking workspace-query contention."
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error429"
                                }
                            }
                        }
                    },
                    "500": {
                        "description": "INTERNAL_ERROR — required denial audit failed.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error500"
                                }
                            }
                        }
                    },
                    "503": {
                        "description": "SERVICE_UNAVAILABLE",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/Error503"
                                }
                            }
                        }
                    }
                }
            },
            "head": {
                "operationId": "headRecordedBillingSummary",
                "summary": "Monthly native-currency recorded billing summary",
                "x-required-scope": "analytics:billing:read",
                "x-capability": "analytics.billing.read",
                "x-unknown-query-parameters": "reject",
                "responses": {
                    "200": {
                        "description": "Same checks/query/quotas/audit as GET. No response body.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    },
                    "401": {
                        "description": "AUTHENTICATION_REQUIRED Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "WWW-Authenticate": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "Bearer"
                                }
                            }
                        }
                    },
                    "403": {
                        "description": "FORBIDDEN Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    },
                    "404": {
                        "description": "NOT_FOUND Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    },
                    "405": {
                        "description": "METHOD_NOT_ALLOWED Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "Allow": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "GET, HEAD"
                                }
                            }
                        }
                    },
                    "422": {
                        "description": "VALIDATION_FAILED Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    },
                    "429": {
                        "description": "RATE_LIMITED Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            },
                            "Retry-After": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "pattern": "^[1-9][0-9]*$"
                                },
                                "description": "Positive seconds; 1 for nonblocking workspace-query contention."
                            }
                        }
                    },
                    "500": {
                        "description": "INTERNAL_ERROR — required denial audit failed. Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    },
                    "503": {
                        "description": "SERVICE_UNAVAILABLE Bodyless.",
                        "headers": {
                            "X-Request-ID": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "format": "uuid"
                                }
                            },
                            "Cache-Control": {
                                "required": true,
                                "schema": {
                                    "type": "string",
                                    "const": "no-store, private"
                                }
                            }
                        }
                    }
                }
            }
        }
    },
    "components": {
        "securitySchemes": {
            "bearerAuth": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "fcr1.<selector>.<secret>",
                "description": "Existing stateless credential with current sponsor, explicit immutable context and exact scope on integration and credential; valid bearer alone is insufficient."
            }
        },
        "schemas": {
            "Count": {
                "type": "integer",
                "minimum": 0,
                "maximum": 100000
            },
            "NonnegativeMinor": {
                "type": "string",
                "pattern": "^(0|[1-9][0-9]*)$",
                "maxLength": 19,
                "description": "Integer minor units; semantic maximum 9223372036854775807. No rounding or FX."
            },
            "SignedMinor": {
                "type": "string",
                "pattern": "^(0|-?[1-9][0-9]*)$",
                "maxLength": 20,
                "description": "Integer minor units; absolute value at most 9223372036854775807. Negative zero prohibited."
            },
            "Month": {
                "type": "string",
                "pattern": "^[2-9][0-9]{3}-(0[1-9]|1[0-2])$",
                "description": "Year 2000..9999, not after current month in Africa/Luanda. Exactly one month; no defaults."
            },
            "PublicId": {
                "type": "string",
                "pattern": "^[0-7][0-9a-hjkmnp-tv-z]{25}$"
            },
            "Bucket": {
                "type": "object",
                "additionalProperties": false,
                "required": [
                    "currency_code",
                    "minor_unit_scale",
                    "billed_document_count",
                    "credit_note_count",
                    "invoiced_gross_minor",
                    "invoiced_net_minor",
                    "invoiced_tax_minor",
                    "credit_gross_minor",
                    "credit_net_minor",
                    "credit_tax_minor",
                    "after_credits_gross_minor",
                    "after_credits_net_minor",
                    "after_credits_tax_minor"
                ],
                "properties": {
                    "currency_code": {
                        "type": "string",
                        "enum": [
                            "AOA",
                            "BRL",
                            "CNY",
                            "EUR",
                            "GBP",
                            "NAD",
                            "USD",
                            "ZAR"
                        ]
                    },
                    "minor_unit_scale": {
                        "type": "integer",
                        "const": 2
                    },
                    "billed_document_count": {
                        "$ref": "#/components/schemas/Count"
                    },
                    "credit_note_count": {
                        "$ref": "#/components/schemas/Count"
                    },
                    "invoiced_gross_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "invoiced_net_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "invoiced_tax_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "credit_gross_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "credit_net_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "credit_tax_minor": {
                        "$ref": "#/components/schemas/NonnegativeMinor"
                    },
                    "after_credits_gross_minor": {
                        "$ref": "#/components/schemas/SignedMinor"
                    },
                    "after_credits_net_minor": {
                        "$ref": "#/components/schemas/SignedMinor"
                    },
                    "after_credits_tax_minor": {
                        "$ref": "#/components/schemas/SignedMinor"
                    }
                }
            },
            "Period": {
                "type": "object",
                "additionalProperties": false,
                "required": ["month", "from", "to_exclusive"],
                "properties": {
                    "month": {
                        "$ref": "#/components/schemas/Month"
                    },
                    "from": {
                        "type": "string",
                        "format": "date"
                    },
                    "to_exclusive": {
                        "type": "string",
                        "format": "date"
                    }
                }
            },
            "Data": {
                "type": "object",
                "additionalProperties": false,
                "required": [
                    "metric_version",
                    "date_basis",
                    "timezone",
                    "as_of",
                    "period",
                    "currencies"
                ],
                "properties": {
                    "metric_version": {
                        "type": "string",
                        "const": "recorded-billing-v1"
                    },
                    "date_basis": {
                        "type": "string",
                        "const": "document_date"
                    },
                    "timezone": {
                        "type": "string",
                        "const": "Africa/Luanda"
                    },
                    "as_of": {
                        "type": "string",
                        "format": "date-time",
                        "pattern": "^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\\+00:00$",
                        "description": "UTC primary statement observation time, not historical cutoff or AGT effective time."
                    },
                    "period": {
                        "$ref": "#/components/schemas/Period"
                    },
                    "currencies": {
                        "type": "array",
                        "minItems": 8,
                        "maxItems": 8,
                        "items": false,
                        "prefixItems": [
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "AOA"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "BRL"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "CNY"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "EUR"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "GBP"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "NAD"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "USD"
                                            }
                                        }
                                    }
                                ]
                            },
                            {
                                "allOf": [
                                    {
                                        "$ref": "#/components/schemas/Bucket"
                                    },
                                    {
                                        "properties": {
                                            "currency_code": {
                                                "type": "string",
                                                "const": "ZAR"
                                            }
                                        }
                                    }
                                ]
                            }
                        ]
                    }
                }
            },
            "Meta": {
                "type": "object",
                "additionalProperties": false,
                "required": [
                    "request_id",
                    "workspace_public_id",
                    "legal_entity_public_id",
                    "environment"
                ],
                "properties": {
                    "request_id": {
                        "type": "string",
                        "format": "uuid"
                    },
                    "workspace_public_id": {
                        "$ref": "#/components/schemas/PublicId"
                    },
                    "legal_entity_public_id": {
                        "$ref": "#/components/schemas/PublicId"
                    },
                    "environment": {
                        "type": "string",
                        "const": "production"
                    }
                }
            },
            "Success": {
                "type": "object",
                "additionalProperties": false,
                "required": ["data", "meta"],
                "properties": {
                    "data": {
                        "$ref": "#/components/schemas/Data"
                    },
                    "meta": {
                        "$ref": "#/components/schemas/Meta"
                    }
                }
            },
            "Error401": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "AUTHENTICATION_REQUIRED"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error403": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "FORBIDDEN"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error404": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "NOT_FOUND"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error405": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "METHOD_NOT_ALLOWED"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error422": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "VALIDATION_FAILED"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error429": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "RATE_LIMITED"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error500": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "INTERNAL_ERROR"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            },
            "Error503": {
                "type": "object",
                "additionalProperties": false,
                "required": ["error", "meta"],
                "properties": {
                    "error": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["code", "message"],
                        "properties": {
                            "code": {
                                "type": "string",
                                "const": "SERVICE_UNAVAILABLE"
                            },
                            "message": {
                                "type": "string",
                                "const": "Não foi possível concluir a consulta."
                            }
                        }
                    },
                    "meta": {
                        "type": "object",
                        "additionalProperties": false,
                        "required": ["request_id"],
                        "properties": {
                            "request_id": {
                                "type": "string",
                                "format": "uuid"
                            }
                        }
                    }
                }
            }
        }
    },
    "x-execution": {
        "external-enabled": false,
        "candidate-row-limit": 100000,
        "overflow-sentinel": 100001,
        "statement-timeout-ms": 2000,
        "lock-timeout-ms": 250,
        "query-lock-lease-seconds": 10,
        "concurrent-queries-per-workspace": 1,
        "cache": "none",
        "shared-minute-limits": {
            "ip": 120,
            "integration": 60,
            "workspace": 300
        },
        "additional-billing-minute-limits": {
            "integration": 6,
            "human-user-within-workspace": 6,
            "workspace": 30
        },
        "clock": "native fixed-minute windows; boundary bursts allowed",
        "maximum-months": 1,
        "maximum-days": 31,
        "maximum-buckets": 8
    }
}
```

## 10. Acceptance and adversarial gates

Required tests, without weakening any earlier assertions:

1. Real issue fixtures with complete local markers; FT/FR/GF/ND invoice amounts, NC linked/standalone, RG ignored, drafts zero. Assert every field/key/type, exact eight buckets/order, zero months, signed credit-only month and all arithmetic identities. No query/model extra field can serialize itself into the response.
2. Partial/full receipts, multiple allocations, NC source allocation, withholdings and failed overpayment attempts leave billing totals unchanged; linked credit in another month affects only its own selected month; an issued credit counts once. Preserve existing payment/receipt and UI arithmetic tests. This proves exclusions rather than introducing a balance formula.
3. Invalid/rejected/processing-cancelled/unknown/partial/conflicting/stale projections do not change the recorded billing result or satisfy receipt eligibility. Raw legacy valid without local issue markers is not manufactured into issuance. Receipt/SA1 tests still fail closed. No AGT/receipt gate is called from billing.
4. Each of eight currencies, mixed base/foreign currency and a changed company base currency remain isolated. Frozen FX variation leaves all native totals unchanged. Integers above JavaScript's safe range serialize exactly as strings. Test zero, max permitted, overflow on PostgreSQL and SQLite, credit-only negative values, no negative zero/floats/exponents, corrupt inconsistent totals and unsupported new currencies/types.
5. Month/day limits, leap February, year rollover, dates at both boundaries, PostgreSQL DATE and SQLite midnight strings, server/browser zone differences, non-Luanda entity, current/future month and current-month future-dated issued records. Later backdated issuance updates the appropriate month; no historical-as-of promise. Reject every unsupported filter, repeated/encoded duplicate parameter, brackets, huge month/query/body, invalid calendar value and normalized route/verb bypass.
6. All 32 subsets of the five exact integration scopes; ordinary reports/admin/write/unknown grants rejected; no implicit analytics grant, independent ceiling/credential restrictions, rotation/revocation/expiry/original sponsor deletion-recreation/role/MFA/verification loss, cookie/session/ambient impersonation confusion. Human owner/admin/accountant allowed with fresh explicit context, billing/viewer denied, support retains dual attribution. Direct capability calls cannot bypass scope, production, quota or audit.
7. Cross-workspace, sibling entity, other environment and sponsor with independent memberships still deny before business SQL. Homologation denies before fiscal reads even if valid data exists; zero month is 200, not an existence oracle. Invalid/expired/revoked credential envelopes/headers match existing protocol. No small-aggregate anonymity claim; no customer selector accepted.
8. GET/HEAD have equal authority/query/quota/audit/last-use semantics and statuses/headers; HEAD always empty. Conditional headers do not bypass. Encoded aliases and unsupported OPTIONS/POST receive the signed behavior and named audit. Every error is generic and no-store; debug mode, SQL errors, input injection and timeout do not expose bindings, amounts or infrastructure.
9. Durable human/machine/support audit attribution and operation/correlation separation; no month, response, financial values, user-agent/token or selectors. Cancelled/unsaved/buffered/throwing audit, JSON failure and rollback withhold data/last-use. No domain writes, jobs, evidence reads, customer loads or network calls. Outer transaction invocation fails closed.
10. PostgreSQL independent-process tests: issuance and credit commit before/after the aggregate statement produce complete old/new totals; receipt commit and poll/rebuild leave billing unaffected; transaction rollback leaves no invented total; permission/grant withdrawal before final authorization denies and after it follows the signed linearization rule. No long-lived snapshot permits later calls after revocation.
11. Additional analytics quotas shared across months/methods/aliases/rotation and multiple integrations; workspace resource guard across processes, cache failure, query cancellation, lock timeout, exception cleanup, worker death/lease recovery. Measure native clock windows explicitly, including legal boundary bursts; Carbon-only freezes are not quota evidence. Earlier quotas retain exact behavior.
12. PostgreSQL index/query-plan gates at sparse/dense/max/over-cap volumes; candidate100001 returns no partial result; transaction read-only/timeouts are actually enforced and reset on pooled connection reuse. Eight output rows, bounded selected columns, no customer/type high-cardinality group, no additional arbitrary scans.
13. Populated scope/index upgrade/down/refusal/interruption, preserved grant rows/guards/FKs/frozen hashes, unsupported-driver refusal. Raw SQL cannot create malformed grants. No UI or permission regression from extracting shared billing definitions.
14. Parse/resolve all embedded OpenAPI references and validate representative success/error/HEAD responses against the published copy, exact enums/fields/monetary formats, semantic relationships and route inventory. V1 and Phase 4C specs/runtime unchanged. Use existing tooling, no dependency solely to validate this small contract.

Run the complete SQLite suite; full existing PostgreSQL concurrency group plus new cases; complete Phase 4C PostgreSQL API/identity/lifecycle/master-data/AGT/receipt selection plus billing/migration cases; PHPStan multiset comparison (21 inherited instances, zero new permitted); types; Pint; changed-file syntax/lint/format and diff checks. Preserve baseline counts (1,334 SQLite passes/70 PostgreSQL-only skips; PG70/905; PGAPI570/4,066) as coverage to retain, not targets to game. Report any count changes, all failed attempts and fixes. Inherited native-clock test flakiness must be classified rather than hidden or assertions relaxed. Production-version/hosted CI and migration/backup/restore/logging/AGT homologation remain separate release gates.

## 11. Design validation, remaining decisions and exclusions

This phase changed only this new design document; a review-start content snapshot confirms no pre-existing application, migration, route, test, specification or governing report changed. External integrations remain disabled.

Executed existing SQLite tests: `FiscalDocumentPdfTest`, `SaftExportTest`, `CustomerAnalyticsTest`, `DashboardTest`, `WithholdingAndCurrencyTest` and `ReceiptDocumentsTest`: **124 passed / 610 assertions / 9.490 seconds**. Initial smaller selections encountered existing cross-file helper dependencies: first 52 passed / 24 undefined `pdfFixture()` errors; adding its defining file yielded 109 passed / 4 undefined `saftFor()` errors; including both defining files produced the passing final selection. No tests or helpers were changed. These fixture dependencies also explain why this selection is larger than the analytics files alone.

The embedded OpenAPI JSON was parsed, all **35 local references** resolved, exact 13-key currency buckets and one GET/HEAD path checked, and HEAD responses verified to declare no body. Design-document Prettier and `git diff --check` passed. Npm user/unsafe-perm configuration warnings are inherited. No runtime source changed, so PHPStan/types/Pint and the full SQLite/PostgreSQL suites were not rerun in this design phase; the immediately preceding Phase 4C review provides their historical baseline. PostgreSQL contention/performance, new-schema runtime checks and new acceptance cases above are mandatory implementation gates, not evidence already obtained. No PostgreSQL server or production database was used for this design.

No blocker remains for **this monthly recorded billing summary**. The following need another Astra design before implementation: reconciled receivables/unapplied credits/refunds and aging; payment-date recorded collections with withholding/NC-source treatment; historical as-of reconstruction; AGT-qualified aggregate states and effective dates; more currencies/timezones/types, arbitrary ranges/trends, caching or materialized views; expanded quotas/limits beyond the signed values. An implementation inability to meet cost/integrity gates is a stop condition, not permission for Sol to redesign.

Hard exclusions: financial/receipt/correction/mutation APIs, authorization-to-issue signals, bank/revenue-recognition claims, production AGT operations/configuration/credentials, report builders/SQL/export, customer/contact drilldowns or rankings, negotiated prices/stock, predictive/AI analytics, management/provisioning UI/live credential rollout, webhooks, agents/BYOW/autonomy and external production enablement. No Phase 4E or other roadmap work is authorized.

## 12. Exact bounded Sol Medium implementation handoff

> Begin the bounded Phase 4D implementation using Sol Medium. Read all governing Phase 1–4C contracts/amendments/implementation reports/reviews, especially SA1 and the Phase 4C trust-boundary review, and read `docs/phase-4d-analytics-read-contract.md` in full including its embedded normative OpenAPI. Execute only this handoff. Preserve every signed identity, scope, context, audit, fiscal, currency, migration and disabled-access invariant.
>
> Implement exactly one shared `analytics.billing.read` capability and the GET/HEAD V2 `analytics/billing-summary` adapter with required month parameter. Add only the independent `analytics:billing:read` scope and the two authorized scope/index migrations. Implement shared billing classification/arithmetic extraction and the bounded primary statement in the existing analytics layer, explicit allowlisted representation, separate eight currency buckets and exact eleven metrics, local issue-marker/data-quality rules, date-only monthly semantics and statement observation time. Do not expose receivables, collections, aging, AGT counts/status, trends, customer dimensions or historical balances. Do not turn raw non-draft/valid into evidence-qualified issuance or authorization; do not erase recorded fiscal facts because AGT knowledge changes.
>
> Preserve original sponsor authority and immutable workspace/entity/environment binding, both scope ceilings, capability revalidation and production-only execution before business SQL. Human shared-capability permission is owner/admin/accountant with existing verified/MFA context and support attribution; no new human HTTP surface. Implement the exact additional attempt quotas, workspace query guard, row sentinel, PostgreSQL read-only statement/lock deadlines, generic failure/redaction, required durable audit, monotonic last-use and GET/HEAD/normalized-method parity. No cache, replica, background task, network request or fiscal write. Reject ambient outer transactions rather than weakening durable audit/snapshot semantics.
>
> Publish the embedded normative specification as `docs/openapi-external-billing-v2.json` without changing earlier API contracts. Preserve Inertia/browser behavior while sharing billing definitions. Apply migrations only to guarded disposable test databases; follow the populated migration/rollback/maintenance safeguards. Add all acceptance/adversarial tests in section 10, especially monetary overflow/currency/date boundaries, scope/context isolation, PG snapshot/authority/quota/worker recovery and measured query plans at the signed volume/budget. Keep the external switch off. No dependency additions or unrelated schema changes.
>
> Run every quality gate in section 10 and report exact files, migrations, metrics, schemas, authority/scope/cost/audit behavior, tests, all attempts/results, PHPStan baseline/new diagnostics, deliberate deferrals and operational requirements in `docs/phase-4d-implementation-report.md`. If the signed behavior contradicts existing invariants or cannot meet its integrity/performance gates, stop with the precise Astra decision required; do not loosen definitions, limits or tests. Conclude **PHASE 4D IMPLEMENTATION — READY FOR ASTRA REVIEW** or **PHASE 4D IMPLEMENTATION — NOT READY**. Do not enable external access or proceed beyond this bounded implementation. Stop for Astra review.

**PHASE 4D DESIGN — APPROVED FOR SOL IMPLEMENTATION**

External access remains disabled. The handoff has not been executed.
