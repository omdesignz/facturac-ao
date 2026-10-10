# Phase 5A — bounded customer creation and command idempotency

Implementation date: 2026-10-08. Governing base revision: `f887e69`, with the inherited signed Phase 1–4D working tree preserved. This report describes the implementation of the exact Sol Medium handoff in `phase-5a-customer-create-command-contract.md`; A1, SA1 and all other signed decisions remain authoritative. No deployment, live provisioning or external enablement is performed.

**PHASE 5A IMPLEMENTATION — READY FOR ASTRA REVIEW**. The bounded implementation and applicable validation are complete. This is not Astra approval or external enablement.

## Implemented boundary

Exactly one external command:

`POST /api/integrations/commands/v1/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/customers`

Capability `customers.create`, independently granted scope `customers:create`. Its separate global command middleware has no browser/session/CSRF authentication. Both `integrations.enabled` and `integrations.commands_enabled` must be enabled; their checked-in defaults and actual local configuration are false. MySQL and production SQLite fail closed. SQLite command execution is permitted only in isolated application tests; PostgreSQL 18 is the certified command database.

Authentication establishes a sealed, non-serializable command context from the existing hash-only bearer credentials. The current credential and parent grants, immutable integration/workspace/entity/production binding and original sponsor membership remain separate authorization boundaries. Current verified email, configured/confirmed MFA, active membership and owner/administrator authority are revalidated. Mutable browser workspace, cookies, token-creator relationships and re-created membership do not grant authority. Attempted delegation/impersonation is denied. Rotation cannot widen authority or reset idempotency or quotas. Backend provisioning/rotation rejects create scopes for homologation, including malformed stored grants at issuance; execution also independently rejects homologation before domain/ledger access.

`CustomerCommands` is the shared persistence and required-audit action used by the existing Inertia store and the machine command. Core name/NIF/country validation is extracted into `CustomerCreateInput::coreRules()` and reused by the existing form request. Human form contacts, commercial settings, existing defaults, roles and redirects remain supported. Machine input is separately sealed as the approved three-field representation; the broader human DTO cannot be supplied to machine execution. Machine persistence additionally requires its live, correctly bound executing reservation. Existing update/delete/import paths are not expanded.

## Input, canonicalization and responses

The external input contains only name, NIF and optional country. NFC, ASCII edge whitespace, ASCII uppercasing, Unicode code-point limits, control rejection and omitted-country AO follow the signed contract. JSON is an object, depth bounded to four, at most 4096 actual bytes, strings only, no unknown fields or decoded duplicate member names. Media/compression, query, method override, duplicate detectable idempotency headers, printable-ASCII key bounds (including positive 1/128-byte and exact 4096-byte body cases) and bounded correlation validation are enforced before execution.

Canonicalization uses the unchanged AGT `CanonicalJson` encoder inside the separate `command-json-v1` envelope. It includes integration public identity, explicit context, capability/version, normalized input and `customer-create-v1` defaults policy. The parsed opaque key and canonical bytes are SHA-256 hashed independently. No payload/key/plaintext credential is stored in the ledger or audit. Golden-byte/hash tests pin normalization and context/principal/version separation. Volatile defaults, credential identity and request IDs are excluded from the fingerprint.

First execution and authorized replay return 201 and the same stored canonical bytes:

```json
{
    "data": { "public_id": "<lowercase customer ULID>" },
    "meta": { "operation_id": "<UUID v4>" }
}
```

Only `Idempotency-Replayed` distinguishes initial/replay. Each HTTP attempt has a fresh `X-Request-ID`; private/no-store and Authorization variation are preserved. No Location/current customer details/internal IDs appear. Model visibility changes, rename, deactivation, deletion and changed/ambiguous defaults do not change completed replay or cause another customer.

Errors use the normative constant Portuguese message, bounded code and request ID. No SQL, exception text, payload, ledger IDs, capacity, partial result or prior customer identifier is returned. Unsupported methods authenticate, charge quotas and enforce scope/context before 405/Allow POST; HEAD suppresses the body. Disabled paths return generic 404 before quota/authentication/business work. Runtime is compared with the unchanged normative OpenAPI.

## Atomic protocol and database integrity

`ExternalCustomerCommand` wraps the separately reusable `CommandIdempotency` protocol. There is one outermost primary READ COMMITTED transaction with no automatic transaction retry. Locks are acquired in the signed order: sponsor/user and original membership SHARE; integration and current credential UPDATE; exact scope rows SHARE; workspace/entity KEY SHARE. Current authorization is repeated after waits and before commit/replay release. Expiration uses `clock_timestamp()`, not frozen Carbon or transaction-start time.

Namespace is integration + workspace + entity + environment + command + capability version + SHA-256 key. A database unique constraint and conflict-tolerant reservation followed by a separate authoritative read are the concurrency boundary. Completed matching commands replay the immutable result after required audit; hash mismatch fails 409 without disclosing prior content. Integration lock contention is bounded and returns 409/Retry-After 1. Different keys retain per-integration serialization.

Customer, authorized/create audit, finalized operation, trigger-maintained capacity and monotonic last-used metadata commit together before response bytes. The success result is validated before commit. Required audit is explicitly enabled, unbuffered, persisted and on the same connection; success events require their transaction. Connection checking occurs before the audit write as well as afterward. Automatic Customer creation logging is suppressed on that instance only and replaced by the explicit required `customer.created` event under the existing customer log. Other model events remain intact.

Killing a precommit worker rolls back the reservation and all effects. A deliberately discarded committed response is recovered by a new process replaying the ledger. Faults after customer insert, audit, ledger finalization, counter increment and immediately before commit roll back everything; a same-key retry succeeds. Uncertain commit is classified separately and never triggers automatic second execution. No lease, recovery worker or durable abandoned executing state is introduced.

The PostgreSQL gate enforces 1-second lock, 2-second statement and 5-second transaction timeouts, plus the application transactional deadline. Preliminary identity/audit statements are also bounded; settings are restored, or a failed connection is discarded. Database/query-event logging is suppressed for the sensitive operation and human customer insert; sanitized exceptions retain no SQL/binding exception chain.

## Migration and retention

One additive migration: `2026_10_08_080150_add_external_customer_command_foundation.php`.

It adds only `customers:create` to both scope allowlists, preserving the five read scopes and existing credential-grant immutability. No implicit grant, customer schema change, historic customer conversion or backfill occurs.

`external_command_operations` stores UUID operation, namespace/hash/canonical version, immutable origin credential composite FK, workspace/entity composite FK, immutable A1 sponsor attribution snapshot, nullable current origin-user relationship, executing→succeeded state, minimal ULID acknowledgement/status/body and UTC timestamps. The result has no live Customer FK. Creation checks validate binding/hash/version/state; update/delete guards preserve evidence and closed result shape. Origin user SET NULL is permitted only following actual account deletion, not an arbitrary raw SQL detach. PostgreSQL's deferred constraint trigger reads the final persisted row and prohibits commit of executing reservations.

`external_command_capacity` is a per-integration FK/PK counter initialized at zero. Only the successful transition trigger increments it by one, in the same transaction, up to 100000. PostgreSQL rejects direct counter updates outside the completion trigger, decrement/delete/reset and nonzero insertion. SQLite provides the fast equivalent shape/state tests but does not claim deferred-commit or production concurrency guarantees.

Completed evidence and the small result remain indefinitely, including after revocation/rotation/customer deletion. No expiry, key reuse, cleanup or admission-counter reset is implemented. At 100000, a new command fails generic 503 before creation, while an authorized completed replay still works. Guarded isolated fixtures seed historical successes for the capacity test without disabling triggers or backfilling production data.

Down refuses new grants, operations/capacity or command-audit evidence. Empty down/up preserves old grants/guards. Four old A1 migration fixtures now first roll back the empty newer command migration before rebuilding the legacy user schema; assertions remain unchanged, and the complete current schema is restored by their existing cleanup. This corrects fixture chronology rather than weakening the old identity tests.

## Audit and quotas

Events: attempted (outside mutation transaction), authorized, customer.created, replayed (required transactional), idempotency-conflict after rollback, authenticated denial, and failed with known rollback versus outcome_unknown. Machine causer, current credential/sponsor, immutable origin credential/sponsor snapshots, real/effective actors, tenant/entity/production context, server/client correlation and stable operation UUID remain distinct. Pre-reservation machine attempts/denials/conflicts have no invented operation UUID. Standing-grant approval references existing lifecycle evidence; no per-customer ceremony or agent/delegated authority is introduced.

Audit properties exclude name/NIF/contact, request/result body, keys/hashes/fingerprints, SQL/bindings, uncontrolled headers/User-Agent and financial values. User-Agent is explicitly null. Internal Customer subject association is permitted; no Customer model is serialized as properties. Sensitive DTO/context JSON/PHP serialization is rejected and log redaction covers them and idempotency material.

The existing database-backed native fixed-minute limiter is reused with distinct command-prefixed identities: IP 60, integration 10, workspace 30. Current credential rotation shares the integration budget. Authenticated malformed, replay, conflict, unsupported method and authority-denied requests consume applicable budgets; pre-authentication transport failures consume IP only. Disabled requests consume none. Independent-process PostgreSQL quota races admit exactly ten of twelve rotated-credential attempts, without relaxing budgets or using frozen clocks.

## Acceptance coverage

The new tests cover strict transport/parser/field/key rules, canonical equivalences and differences, closed schemas, default-none/one/ambiguity and context scoping, original membership freshness, browser independence, all five read scopes versus create, V1/V2 read denial for create-only scope, homologation tampering, rotated origin/current attribution, immutable replay after customer changes/deletion, quota aliases/boundaries, required audit failure/redaction/connection rules, migration chronology/roundtrip/evidence refusal and raw SQL forgery/unique/composite/result integrity.

PostgreSQL tests use synchronized independent processes and actual application/kernel/lifecycle/account writers: two and ten identical requests with two rotating credentials; different-payload and two-integration NIF races; both withdrawal orders against first execution and replay for credential/parent/scope/role/member/MFA/email/account deletion; expiry during a lock wait; killed worker; discarded committed response; each transactional fault stage; lock/statement/transaction bounds and retry recovery; immutable raw changes; executing-commit rejection; native quota race; guarded 99999→100000/refusal/replay and fresh query plans. Database effects, counters and required audits are asserted, not only statuses.

## Validation results

Retained commands, exact result objects, diagnostic comparison, file hashes and PostgreSQL plans are in [phase-5a-implementation-evidence.json](phase-5a-implementation-evidence.json). PHP 8.4.25, Laravel 13.24.0, Pest 5.0.4, Activitylog 5.0.0 and disposable PostgreSQL 18.6 were used. PostgreSQL gates ran serially on `facturac_test_phase1` at port 55439, never the application's ordinary MySQL database. SQLite used its guarded in-memory database.

| Gate                                                                         | Final observed result                                                                                                                                                                                                                                                                             |
| ---------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Complete SQLite before the final two PG-only race cases                      | **1655 passed, 10975 assertions, 189 PG-only skips**, 432825 ms. One inherited discovery warning.                                                                                                                                                                                                 |
| Complete PostgreSQL concurrency group                                        | **189 passed, 6999 assertions**, 837157 ms. Includes the 128 inherited cases and 61 new actual-process cases. One inherited discovery warning.                                                                                                                                                    |
| Exact inherited 17-file PG API/domain selection + then-current command files | **888 passed, 6909 assertions**, 738703 ms.                                                                                                                                                                                                                                                       |
| Final complete PG command HTTP/schema selection                              | **112 passed, 863 assertions**, 101558 ms. Re-runs all command files and includes the last three positive narrowed-rotation/key/body-boundary cases added after the broad PG selection began. All 17 inherited files remain unchanged in the CI selection, with the final command files appended. |
| Final command process suite after final audit/redaction changes              | **61 passed, 784 assertions**, 87023 ms. The two subsequently added default-price-list deletion races also passed: **2 passed, 26 assertions**, 2220 ms; no runtime code changed after the broad gates.                                                                                           |
| PHPStan                                                                      | **Exactly 21 inherited diagnostics; zero added/removed**, exact file/line/message/identifier/details match. No baseline/ignore changes. Global analysis still exits nonzero for inherited debt.                                                                                                   |
| Type checking                                                                | `npm run types:check` **passed**.                                                                                                                                                                                                                                                                 |
| Pint                                                                         | Required dirty formatting applied; subsequent dirty run **passed**.                                                                                                                                                                                                                               |
| PHP syntax                                                                   | All 24 changed/new PHP files **passed**; final refresh recorded in evidence.                                                                                                                                                                                                                      |
| Changed-file formatting / whitespace                                         | Changed YAML/report/evidence Prettier and `git diff --check` checked before final handback. No JS/Vue source changed, so changed-file ESLint is not applicable.                                                                                                                                   |
| Full ESLint                                                                  | **9719 inherited errors, zero warnings**, unchanged.                                                                                                                                                                                                                                              |
| Full resources Prettier                                                      | Same **four inherited failures**: Establishments/Index.vue and the three promo files named in evidence.                                                                                                                                                                                           |

At 100000 retained operations, the fresh authoritative namespace lookup is an **Index Scan on command_namespace_unique**, returning one row; the latest group run measured **0.024 ms**. The scoped NIF lookup uses **customers_entity_tax_id_unique**, measured **0.015 ms**. Independent empty-table plans return zero rows via these indexes, respectively **0.043/0.033 ms**. Full JSON plans and buffers are retained. These are local disposable-database measurements, not a production load certification. Runtime contains no ledger/customer count scan; counts in setup/evidence are offline test verification only.

All signed read/fiscal/identity/SA1 regressions passed. The new suite contains **175 cases**: 42 protocol/core, 51 HTTP/disclosure/lifecycle/quota, 19 schema/migration and 63 PostgreSQL process/concurrency/resource cases. The final two PostgreSQL-only races were verified separately after the complete gates; those gates retain their actual observed counts rather than being reported as a combined rerun. Exact counts are meaningful coverage summaries, not proof by test quantity.

### Failed attempts and inherited debt

Early implementation/fixture failures were resolved without deleting tests or weakening security assertions: strict error-code forwarding; named NIF constraint classification; migration function replacement/single prepared statements; PostgreSQL statistics snapshot refresh in race synchronization; native expiry fixture ordering; actual replay callback instead of a mock of a final domain class; JSON-object/route/status-set fixtures; typed generate_series parameters and EXPLAIN numeric comparisons; raw origin-null integrity; disabled cached-audit status and pre-write connection validation.

The first broad SQLite attempt had 26 OpenSSL random-state sandbox errors, four old attribution migration fixture errors and an inherited WorkSession test race with only one real second left before expiry. A writable `RANDFILE` resolved the OpenSSL environment issue. The four fixture setups now roll back the newer empty command migration before reconstructing old user schema. WorkSession assertions and runtime were not changed; its focused run and all final complete reruns pass. Native command/read quota assertions were not relaxed or frozen.

The SQLite/PG discovery warning is the inherited ineffective `use DomainException` in a subscription test. PHPStan's unchanged 21 findings are in prior fiscal/transport/analytics files; none is a new command diagnostic. The 9719 lint errors and four format failures are unchanged resource debt. The protected frontend/fiscal/read sources are unchanged and all applicable regressions pass; no inherited issue identified here compromises the Phase 5A boundary. They remain repository debt, not a clean-global-CI claim. npm also emits existing user/unsafe-perm configuration warnings.

## Files and preservation

New runtime files: `app/Fiscal/{CustomerCreateInput,IntegrationCommandContext,HumanCustomerCommandContext,CustomerCommands,ExternalCustomerCommand,CommandIdempotency,CommandDatabase,CommandAudit}.php`, `app/Http/Middleware/ExternalCommandBoundary.php`, `app/Http/Controllers/Api/ExternalCustomerCreateController.php`, `routes/integration-commands.php`, and the migration above.

Changed existing files: `CustomerController`, `StoreCustomerRequest`, `IntegrationCredentials`, `IntegrationLogRedactor`, `bootstrap/app.php`, `config/integrations.php`, `.env.example`, `.github/workflows/tests.yml`, and the four fixture setups in `tests/Unit/IntegrationLifecycleTest.php`.

New tests: `tests/CustomerCommandFixtures.php` and `tests/Unit/{CustomerCommandTest,CustomerCommandBoundaryTest,CustomerCommandMigrationTest,PostgresCustomerCommandTest}.php`. Their Unit location intentionally avoids RefreshDatabase's outer test transaction; credential disclosure and the command must execute a real outermost transaction. CI retains all original PG selection files and adds the command files; the process suite joins the existing mandatory PostgreSQL group.

Documentation: this report and retained implementation evidence. Governing signed documents, OpenAPI, AGT canonicalization, fiscal services, read policies/resources/queries and prior tests are preserved apart from the chronological fixture adjustment described above. No dependency is changed.

## Deferred and operational gates

No architectural deviation is intended. No extra customer fields, update/delete/merge/import commands, catalogue writes, fiscal mutations/issuance/numbering/corrections/receipts/payments/AGT operations, webhook/worker/outbox, agent/BYOW/delegation/autonomy, management UI, live provisioning or external enablement is implemented.

Eventual deployment still requires backup/restore and migration rehearsal, PostgreSQL/timeouts/load verification, edge/header/body behavior and proxy/APM/DB redaction, storage/audit monitoring, legal retention/deletion policy and a separate reviewed human provisioning/enablement decision. Existing AGT homologation and operational gates are unchanged; this customer command makes no new AGT assumption.

## Exact bounded Astra Medium review handoff

Perform the Phase 5A Customer Create / Command Idempotency Trust-Boundary Review using Astra Medium. Read all governing Phase 1–4D contracts/reviews, A1/SA1, the approved Phase 5A contract and normative OpenAPI, this implementation report and its retained evidence. Inspect actual code, migrations and tests; do not trust the report alone. Attempt to falsify exactly-one durable customer/result/required-audit/counter atomicity, canonical equivalence and separation, current authority on first execution/replay, per-integration namespace across rotation, original membership/context/production isolation, enumeration resistance, quotas, closed schemas, audit/redaction, raw SQL guards, bounded storage/queries/timeouts and crash/rollback/lost-response recovery. Independently inspect the 100000-row plans and actual independent-process races. Verify the shared Inertia path and all signed read/fiscal/identity baselines remain intact. Correct only narrow BLOCKER/IMPORTANT findings within the approved slice; stop for an architectural decision if a signed invariant must change. Re-run all applicable gates, distinguish inherited debt and external/operational gates, and conclude PHASE 5A — APPROVED or PHASE 5A — NOT APPROVED with precise findings/remediation. Keep both external switches disabled. Do not deploy, provision credentials, expand mutations or begin the next phase. Stop after the trust-boundary review.
