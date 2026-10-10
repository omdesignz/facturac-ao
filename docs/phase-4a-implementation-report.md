# Phase 4A customer/catalogue implementation report

> **Status-authority amendment SA1 — 2026-10-07:** Read this historical report together with [SA1](phase-4b-status-authority-amendment.md). Only its AGT evidence/status, receipt-eligibility and inherited v1 status semantics are superseded as listed in SA1’s downstream register. Unrelated decisions, historical findings and test results are unchanged. The qualified evidence gate is fail-closed; an unqualified legacy status is not fiscal authorization.

Date: 2026-10-07. Model routing: bounded Sol Medium implementation; next action is Astra Medium review. This report records implementation evidence, not architectural sign-off or external release approval.

## Governing scope

Implemented only the exact bounded Sol Medium handoff in `phase-4a-customer-catalogue-read-contract.md`, decisions D1–D10. The six Phase 1–3 governing contracts/reviews, resolved Phase 3A report, and Phase 4A design were read. All seven governing files remain byte-for-byte unchanged against the pre-implementation SHA-256 inventory. No signed invariant was redesigned; no architectural blocker or deviation was found.

Existing Laravel Customer/CatalogueItem models, domain semantics, DocumentReadContext, ExecutionContext, IntegrationReadContext, credential lifecycle, required activity audit and shared quotas are reused. No parallel business engine or replacement Inertia behavior was introduced.

## Implemented capabilities and routes

Shared capabilities are `CustomerCapabilities::listCustomers/readCustomer` and `CatalogueCapabilities::listItems/readItem`. Four typed list/read commands validate inputs independently of transport. Selected-column queries and allowlisted resources implement the same minimized representation for direct application callers and both HTTP adapters.

Both `/api/v1` (session) and `/api/integrations/v1` (external) use the explicit suffix prefix `/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}`. Each now has exactly these four additions, supporting GET and implicit HEAD:

| Suffix                            | Capability                       | Machine scope    |
| --------------------------------- | -------------------------------- | ---------------- |
| `/customers`                      | `customers.read` → listCustomers | `customers:read` |
| `/customers/{customerPublicId}`   | `customers.read` → readCustomer  | `customers:read` |
| `/catalogue-items`                | `catalogue.read` → listItems     | `catalogue:read` |
| `/catalogue-items/{itemPublicId}` | `catalogue.read` → readItem      | `catalogue:read` |

The two existing document routes per adapter remain. Thin controllers resolve trusted context, validate path/filter input, call shared capabilities and construct response envelopes. No management/provisioning, mutation, fiscal or agent route was added. Both OpenAPI documents now have six paths, with explicit scope/production rules, closed schemas, bounded metadata and bodyless HEAD responses. No OAuth declaration was introduced.

## Response schemas and query semantics

Customer list/detail data contains exactly `public_id`, `name`, `country_code`, `is_active`. NIF, contacts, addresses, commercial information, internal IDs and other model fields are omitted.

Catalogue list data contains exactly `public_id`, `code`, `type`, `name`, `unit_of_measure`, `unit_price_minor`, `price_scale`, `currency_code`, `tax_type`, `tax_code`, `tax_percentage`, `tax_exemption_code`, `is_active`. Detail adds only nullable `description`. The price is the stored base price as a canonical non-negative decimal string bounded by signed BIGINT; scale is 2. Tax percentage is the stored two-place decimal string; existing tax fields are represented, not repaired or certified. Customer-specific/negotiated prices, inventory and stock fields are excluded. Invalid stored representations fail closed before success audit/release. Resource allowlists also prevent additional fields from leaking if projection/model serialization changes later.

Lists accept only `page` (1–10000, default 1), `per_page` (1–50, default 25), `status` (active/inactive/all, default active), and optional `q`. Catalogue additionally accepts the existing type enum and exact case-sensitive `code`, without trimming code. Detail accepts no query parameters. Unknown/invalid filters return 422.

Search trims only ASCII edge spaces, requires 2–80 UTF-8 characters and rejects Unicode control characters. PostgreSQL `strpos` and SQLite `instr` implement case-sensitive literal substrings: customer name; grouped catalogue name OR code. Quotes, `%` and `_` are literal values, not SQL wildcards. Ordering is internal `id DESC`, never an exposed sequential identifier. Pagination supplies page/per_page/total/last_page, without pagination URLs. Response metadata includes server request ID, public workspace/entity IDs, production environment and `data_scope: legal_entity_master`.

## Context, authorization and credential boundary

Every operation freshly authorizes its capability before any master-data query/count. Both direct and HTTP contexts enforce production-only access to environmentless master data. Homologation returns 403 before record lookup and cannot disclose existence. Explicit path binding never inherits browser current-workspace state.

Human context refreshes User, legal entity and membership from the primary connection and preserves active membership, MFA/verification, existing permitted roles and signed read-only impersonation semantics. Machine identity remains distinct from sponsor authority. A valid credential is insufficient: final authorization revalidates the fixed permission→scope mapping, parent AND credential grants, immutable workspace/entity/environment binding, credential/integration state and expiry, and the original active Owner/Administrator sponsor's verification/MFA membership in one fresh primary snapshot. The only read mappings are documents.read/documents:read, customers.read/customers:read and catalogue.read/catalogue:read.

Existing document-only grants are unchanged and gain no master-data authority. Existing internal lifecycle operations reject new master scopes on homologation before secret generation; rotation/replacement retain atomicity and existing expiration/revision/snapshot rules. One-time disclosure, SHA-256 credential hashing, immutable creator attribution and secret-redaction rules are preserved. No live credential was provisioned.

The signed final-authorization linearization rule is preserved: a withdrawal committed before final authorization denies; an operation already authorized may complete. Independent-process tests demonstrate both orderings. No stronger serializable revocation guarantee is claimed.

## Audit, errors, HEAD and rate limits

Successful operations require durable `customers.list/read` or `catalogue.list/read` audit after validating the entire projection and before response release or credential last-used update. Audit includes trusted actor/context, operation/correlation IDs, capability version and master-data scope. Integration remains the actor; sponsor is separate authority, with no invented human executor. List properties contain bounded pagination, status and search-applied boolean, never search values. Detail success identifies the resolved public resource only.

Server route metadata classifies denials, including responses generated before Laravel assigns a route. Master-data 401/403/404/405/422/429 denial events are durable and fail closed on audit failure. Unmatched external paths retain generic `integration.read.denied`; existing document event behavior is preserved. Missing and foreign records use the same scoped 404 behavior. Validation/errors omit rejected values, confidential URLs and record contents.

SensitiveReadQuery disables query dispatch/logging during master reads, restores prior connection settings, and replaces database exceptions without retaining sensitive bindings or previous exceptions. Master-data exception reporting is sanitized. Search values, price/content payloads, raw URLs and credentials are not audit metadata. Proxy/APM capture is a separate release gate.

GET/HEAD execute identical authentication, authorization, lookup, quota and audit rules; HEAD has no body, including error responses. Nonempty GET/HEAD request bodies are rejected. Existing correlation and client-request-ID handling remain. External IP 120/min, integration 60/min and workspace 300/min budgets are shared across document/customer/catalogue paths and overlapping credentials. Session throttling remains unchanged. External access remains globally disabled (`integrations.enabled = false`, verified locally).

## Migration and PostgreSQL query plans

Added `2026_10_07_142703_extend_integration_read_scopes.php`; historical migrations were not edited. It atomically extends both scope CHECK allowlists to exactly documents:read/customers:read/catalogue:read. It grants nothing. PostgreSQL validates replacement constraints transactionally; SQLite rebuilds only the two grant tables, preserving their keys/FKs and credential-scope immutability guard, then checks foreign keys. Existing identity/context/credential guards remain. Rollback refuses new grant evidence or customer/catalogue capability audit evidence, including audit remaining after grants are removed, requiring forward repair.

The same migration adds only the two explicitly permitted, measured indexes: `customers_scoped_read_order` and `catalogue_items_scoped_read_order`, both `(workspace_id, legal_entity_id, id)`. No environment column, identifier backfill or other schema change was added. Migrations ran only on guarded disposable test databases; the application database was not migrated.

The PostgreSQL EXPLAIN ANALYZE test uses 20,000 records per table (1,000 target entity, 19,000 another entity). Before these indexes, descending list/search scans used primary keys and discarded 19,000 unrelated records. Afterward both use the scoped index, with zero filtered foreign records in the measured sample:

| Query                      | Before, ms | After, ms |
| -------------------------- | ---------: | --------: |
| Customer list              |      1.982 |     0.024 |
| Customer substring search  |      1.873 |     0.018 |
| Catalogue list             |      2.253 |     0.030 |
| Catalogue substring search |      2.193 |     0.022 |
| Customer scoped count      |      0.129 |     0.131 |
| Catalogue scoped count     |      0.122 |     0.137 |

Counts use the existing scoped active-status index and scan the 1,000 scoped records. These warm local timings justify the optional ordering indexes, not a production SLA. Substring search and counts still have cardinality-dependent cost within the tenant; deep offsets also cost more. The plan benchmark uses representative name-substring queries; the complete grouped catalogue name/code behavior is verified by API tests. Production workload/plan monitoring and confidential query capture suppression remain operational gates. No search service was introduced.

## Regression coverage

Added 143 fast tests (119 master-data API cases, 14 migration cases and 10 lifecycle cases) and 10 PostgreSQL cases, preserving all old tests. Coverage includes direct/session/machine agreement, exact schemas and future-field allowlist defense, all scope subsets, primary authority withdrawals, cross-tenant/entity/environment bindings, browser-state independence, production/homologation denial before master query, literal Unicode/search/filter/pagination semantics, default active status, malformed stored data, BIGINT boundaries, negotiated-price exclusion, durable audit failures, confidential query instrumentation, correlation, GET/HEAD bodies and errors, disabled access and enumeration resistance, shared alternating-path quotas, migration checks/immutability/populated upgrade/rollback/atomic failure, independently committed withdrawals, post-authorization revocation, parallel mixed-resource quota requests, and measured query plans.

Existing route/spec tests were extended from two to six approved paths; their original document assertions remain. The fiscal rollback test now rolls back six rather than five migrations to include the new additive migration and still exercises the same prior fiscal rollback invariant. Both new feature files were added to the existing PostgreSQL API CI gate; concurrency cases use its existing PostgreSQL group. No tests, baseline diagnostics or assertions were removed or suppressed.

## Final validation

Final results below were run against the completed migration including both measured indexes:

| Gate                                                                                                 | Result                                                                                                                                                        |
| ---------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Complete SQLite suite: `php artisan test --compact`                                                  | **1,100 passed / 6,521 assertions**, 31 PostgreSQL-only skips, one inherited warning, zero failures; 110.972 s                                                |
| PostgreSQL concurrency: existing fiscal/integration group plus new cases                             | **31 passed / 357 assertions**, zero skips/failures, one inherited warning; 28.415 s                                                                          |
| PostgreSQL API/attribution/lifecycle plus both new feature files                                     | **276 passed / 2,169 assertions**, zero failures/skips/warnings; 55.959 s                                                                                     |
| PHPStan                                                                                              | Nonzero exit for **21 inherited diagnostics**, exact file/line/message/identifier before/after match; **zero new diagnostics**, no baseline/suppression edits |
| `npm run types:check`                                                                                | Passed                                                                                                                                                        |
| `vendor/bin/pint --dirty --format agent`                                                             | Passed                                                                                                                                                        |
| Changed-file ESLint                                                                                  | Passed; no new frontend source was introduced                                                                                                                 |
| Prettier: both OpenAPI documents, CI workflow, relevant pre-existing dirty Vue files and this report | Passed                                                                                                                                                        |
| PHP syntax                                                                                           | All 32 changed/new PHP files passed `php -l`                                                                                                                  |
| Route/OpenAPI schema/HEAD/scope assertions                                                           | Passed on SQLite and PostgreSQL                                                                                                                               |
| `git diff --check` and governing-file preservation                                                   | Passed; no existing file changed outside this implementation's manifest                                                                                       |

PostgreSQL gates used PostgreSQL 18.6 at 127.0.0.1:55439, disposable `facturac_test_phase1`, `FISCAL_PG_GATE=1`, with existing destructive-test database guards. These are actual PostgreSQL runs, not SQLite substitutes. Hosted CI and deployment PostgreSQL parity were not executed/verified here. The inherited warning is reported by the existing harness without warning_details; it was not hidden. npm reports inherited user-config notices (`user`, `unsafe-perm`) without failing the gates.

Intermediate failures were resolved and superseded by the final runs: a query-plan test compared decoded numeric row counts without normalizing their type; ExecutionContext initially added seven PHPStan union-type diagnostics and now checks actual model types; an intermediate PostgreSQL migration test ran against a schema initialized before the optional indexes were added and was rerun on a fresh final schema. No test was weakened to accommodate an implementation defect. Earlier middleware/HEAD fixture failures were corrected before the complete successful runs.

## Files changed

This is the task-specific manifest, measured against the pre-implementation file inventory; it does not claim unrelated pre-existing dirty/untracked Phase 1–3 work as new changes.

- `app/Fiscal/CatalogueCapabilities.php`
- `app/Fiscal/CatalogueListCommand.php`
- `app/Fiscal/CatalogueReadCommand.php`
- `app/Fiscal/CustomerCapabilities.php`
- `app/Fiscal/CustomerListCommand.php`
- `app/Fiscal/CustomerReadCommand.php`
- `app/Fiscal/ExecutionContext.php`
- `app/Fiscal/IntegrationCredentials.php`
- `app/Fiscal/IntegrationReadContext.php`
- `app/Fiscal/ReadOperationAudit.php`
- `app/Fiscal/SensitiveReadQuery.php`
- `app/Http/Controllers/Api/V1/CatalogueReadController.php`
- `app/Http/Controllers/Api/V1/CustomerReadController.php`
- `app/Http/Controllers/Api/V1/ExternalCatalogueReadController.php`
- `app/Http/Controllers/Api/V1/ExternalCustomerReadController.php`
- `app/Http/Middleware/ExternalIntegrationBoundary.php`
- `app/Http/Middleware/ReadApiResponse.php`
- `app/Http/Requests/ReadCatalogueApiRequest.php`
- `app/Http/Requests/ReadCustomerApiRequest.php`
- `app/Http/Resources/CatalogueItemReadResource.php`
- `app/Http/Resources/CustomerReadResource.php`
- `database/migrations/2026_10_07_142703_extend_integration_read_scopes.php`
- `docs/openapi-external-read-v1.json`
- `docs/openapi-read-v1.json`
- `routes/api.php`
- `routes/integrations.php`
- `tests/Feature/ExternalIntegrationTest.php`
- `tests/Feature/MasterDataReadApiTest.php`
- `tests/Feature/MasterDataScopeMigrationTest.php`
- `tests/Feature/ReadFirstDocumentApiTest.php`
- `tests/Unit/IntegrationLifecycleTest.php`
- `tests/Unit/PostgresFiscalConcurrencyTest.php`
- `tests/Unit/PostgresIntegrationConcurrencyTest.php`
- `.github/workflows/tests.yml`
- `bootstrap/app.php`
- `docs/phase-4a-implementation-report.md`

## Deliberately deferred and review decision

Unimplemented: customer NIF/contact/address disclosure; negotiated/customer-specific pricing; stock/inventory exposure; homologation master datasets; customer/catalogue mutations; fiscal mutations/issuance/receipts/corrections; AGT production/configuration/credentials; analytics/exports/delivery; provisioning routes/commands/UI or live credentials; agent tools/delegation/workflows/BYOW/autonomy; general webhooks; external production enablement; Phase 4B or any subsequent roadmap work.

No AGT homologation assumption was resolved or changed by these reads. Phase 1–3 homologation evidence and operational release requirements remain. Before any external release, Astra must review master-data disclosure, context/scopes/authority freshness, audit/redaction, shared quotas and migration/PG evidence. Hosted CI, production DB parity/migration timing, proxy/APM query/response redaction, trusted proxies, audit retention and incident ownership remain separate release gates. External enablement is neither authorized nor performed.

The bounded Phase 4A implementation and required local quality gates are complete, with no implementation blocker. This is readiness for review, not signed approval or permission to release. Exact recommended next action: **Astra Medium — Phase 4A customer/catalogue disclosure and trust-boundary review**, reading the signed governing contracts, this report, implementation and regressions; accept or identify bounded remediation before any next phase. Stop here; do not automatically execute that review or begin Phase 4B.

**PHASE 4A IMPLEMENTATION — READY FOR ASTRA REVIEW**
