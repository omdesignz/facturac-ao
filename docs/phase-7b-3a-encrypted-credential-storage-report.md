# Phase 7B.3a — encrypted credential storage and tenant isolation

Date: 2026-10-09. Bounded security remediation handback. **The original independent review found two MEDIUM defects. Both are addressed below; independent re-review remains required. No acceptance decision is made here.** Governing authority is the [corrected 7B.3 supplement including CM1](phase-7b-3-tenant-ai-control-plane-supplement.md), the [7B architecture](phase-7b-multi-provider-ai-gateway-contract.md) and the user's corrected storage-only handoff. Production inference and external access remain disabled.

## Initial stop and corrected implementation

The initial attempt stopped before runtime changes because the proposed `catalogue_enabled` column had no approved assistant tool and `customers_enabled` collapsed two separately executable tools. The subsequent narrow design review approved CM1: two independent customer flags and the three existing document/AGT/billing flags. That correction changed documentation only. The initial report/evidence hashes and blocked status are retained in this implementation's evidence history; they are not presented as implementation validation.

This corrected attempt implements custody only. No stored credential is available to the AI Gateway, Anthropic planner/transport or SDK. No provider verification, selection/activation service, rotation/revocation service, administration route/UI, OpenAI or compatible endpoint was introduced.

## Independent review failure and bounded remediation

The corrected implementation did not pass independent review. The reviewer reproduced (1) complete encrypted envelope disclosure through Symfony `VarCloner` plus `CliDumper`, despite `__debugInfo()`, and (2) acceptance of a configured 0600 KEK under `public/`. Both were MEDIUM findings. The earlier implementation test results remain historical evidence, not proof that these boundaries were safe. The independent review was not persisted as a separate repository report; the user's remediation request supplies its exact reproduced findings. This section preserves the sequence: initial CM1 blocker → approved schema correction/implementation → independent review failure → bounded remediation awaiting independent re-review.

### Exact remediation changes

| File                                               | Bounded change                                                                                                                                                                                                                  |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Fiscal/TenantAiEnvelope.php`                  | Keep ciphertext, wrapped DEK and KEK identifier in native opaque `SensitiveParameterValue` instances; unwrap only at the existing prepared PDO insert. Mark private constructor arguments sensitive.                            |
| `app/Models/TenantAiCredential.php`                | Wrap protected attributes before hydration originals are synchronized, and on assignment, so attributes/originals/changes contain opaque values. Preserve metadata allowlist, hidden fields, denied serialization and mutation. |
| `app/Fiscal/TenantAiKeyFile.php`                   | Require explicit canonical private custody root, component-safe containment and disjointness from effective/repository webroot and configured served storage. Retain existing file-security checks.                             |
| `config/tenant_ai.php`                             | Add `kek_root => null`; absence denies, with no implicit storage fallback.                                                                                                                                                      |
| `tests/Feature/TenantAiCustodyRemediationTest.php` | 35 new attack/positive cases, including actual captured Symfony and cached Laravel dump bytes, canonical roots, published/signed-served storage and filesystem escapes.                                                         |
| `tests/Feature/TenantAiStorageTest.php`            | Explicitly configure a synthetic private test root; preserve all 49 existing cases/assertions.                                                                                                                                  |
| `tests/Unit/PostgresTenantAiStorageTest.php`       | Explicitly configure a synthetic private test root; preserve all six PostgreSQL cases.                                                                                                                                          |
| This report and its evidence JSON                  | Record the failed review, correction, exact validation, hashes, limitations and re-review handoff.                                                                                                                              |

No migration, dependency, schema, cryptographic construction, capability mapping or accepted Phase 7A/7B.1/7B.2 runtime file changes are part of remediation.

### Actual debug boundary

Inspected installed PHP 8.4.25, Symfony VarDumper 8.1.2 and Laravel 13.24.0. `Caster::castObject` retains cast object properties and adds debug information; `__debugInfo()` is not an exclusion policy. `AbstractCloner` supports casters, but copies default casters when instantiated. Laravel's registered CLI handler captures an existing cloner; merely adding a later default caster would not reliably affect that cached instance. No custom global dump framework, handler replacement or frozen bootstrap change was introduced.

PHP's supported native `SensitiveParameterValue` container exposes no payload through an object property cast. Its underlying value is retrievable only by an explicit `getValue()` call. The envelope uses these containers for all three protected strings; the read-only credential presentation model uses them for hidden hydrated/assigned fields before originals are copied. Symfony therefore sees opaque native containers, including through already-instantiated cloners. The encrypted bytes and AES-256-GCM construction persisted to the database are unchanged. Hidden model envelope attributes now intentionally yield opaque containers to internal callers; no accepted consumer depended on retrieving their strings through this presentation model. Storage/test inspection continues through its existing bounded PDO path. No new export/getter is provided.

The regression creates a real credential through `TenantAiStorage`, reads its synthetic persisted envelope inside the test, and reconstructs the associated envelope via its existing private constructor solely for inspection. It captures actual `VarCloner`/`CliDumper` output for the model, envelope and nested pair. Positive controls prove full ciphertext and wrapped-key markers would be visible without protection; tests do not depend on string truncation. It also invokes actual Laravel `dump()` with the existing handler and cached cloner, temporarily redirecting only the output sink to a buffer and restoring it in `finally`. Assertions cover exact ciphertext, wrapped DEK, KEK version, plaintext and meaningful fragment, raw/base64 DEK and KEK, IVs, tags and encrypted values. Ordinary JSON/array/resource/native debug/serialization and successful bounded decrypt remain covered. No sensitive marker is written to the report or evidence.

This prevents ordinary developer dumping, not malicious in-process code deliberately invoking `getValue()`, raw PDO access, memory inspection or arbitrary custom casters. Those remain outside the accidental-disclosure boundary; no PHP-level container is a sandbox against authorized process code.

### Canonical private KEK policy

Resolution is configured version → allowlisted file → canonical existing root/file → strict descendant of root → root disjoint from served roots → existing protected-file checks → read. `tenant_ai.kek_root` must be an explicitly configured absolute existing directory with exactly canonical spelling, no symlink and no trailing-slash/traversal alias. A selected file must also equal its real path and be a strict descendant using a `/` component boundary. Sibling-prefix paths cannot pass. Filesystem root and custody roots equal to, inside, or encompassing a served root deny.

Served exclusions come from actual repository conventions: effective `public_path()`, repository `public/`, `storage/app/public`, local disks with `serve`, public visibility or a URL, and configured filesystem link targets. This includes the application's signed-served `storage/app/private`; its name does not make it a KEK location. Existing aliases are resolved canonically. A not-yet-created served directory is resolved from its existing canonical ancestor without accepting dot traversal or dangling links. Invalid/unresolvable configuration denies with the fixed storage exception. The original protected-parent, restrictive file permissions, regular-file, symlink, exact 32-byte size, version allowlist and path/open-file inode/device checks remain intact. Known-VAP-secret comparison retains its distinct existing protected-file boundary; it is not silently moved into tenant KEK custody.

Deployment must explicitly provision a private root and configure it. Remediation does not create/move real keys or choose a production path. Test roots are explicitly configured disposable directories; they are not a runtime fallback. Web-server aliases outside Laravel/filesystem configuration remain a deployment custody gate: operators must not publish the approved root. No static application check can discover unrelated proxy/server configuration.

Targeted cases reproduce public-file acceptance at mode 0600; deny public/signed-served/published storage even if nominated as the custody root; deny private→public, public→private and private→private symlinks, `../`, sibling-prefix and missing files; deny absent/relative/nonexistent/file/ancestor/symlink/invalid roots; retain permission/size/version/regular-file checks; and accept the exact approved synthetic private key.

### Fresh remediation validation

- Focused SQLite: **84 passed / 441 assertions**, exit 0 (35 new plus all 49 original cases).
- PostgreSQL custody/security: **90 passed / 499 assertions**, exit 0 (the same 84 cases plus all six native PostgreSQL integrity/concurrency cases). Counts overlap; do not sum them.
- Gateway/quarantine/audit closure selection: **86 passed / 910 assertions**, exit 0.
- Assistant UI: **12 passed**, exit 0. Type checking: exit 0. PHP syntax: seven changed/new PHP files passed. Pint: exit 0.
- PHPStan: exit 1, **exactly 21 inherited diagnostic objects**, zero new. No baseline/suppression edits.
- Full SQLite: **2,257 passed / 1 failed / 321 skipped / 15,116 assertions**, exit 1; 1 inherited warning(s), no emitted warning details. The failure was `FiscalDocumentPdfTest.php:221` (`a receipt carries the same square QR`: expected one placement, found zero). The complete unchanged PDF test file immediately passed on rerun: **37 cases / 109 assertions**, exit 0. This is not a clean full-suite result. No PDF fix or assertion weakening was made; the intermittent cause is unproven and remains a validation observation for independent re-review.
- Historical broad PostgreSQL results (321 concurrency cases and 1,459 integration cases) were hash-verified, not rerun for this bounded remediation. Current custody code was directly rerun on PostgreSQL as above. The unchanged migration/schema and frozen runtime hashes support using the permitted historical broad evidence.
- Existing 9,719 ESLint errors and four resource-formatting failures are inherited; no JS/resource files changed. Their original logs and unchanged file hashes remain evidence, not newly clean gates.

The first focused run had 82 passes and two setup errors: an overly broad fixture edit added an out-of-scope `$directory` reference to two existing failure cases. Corrected only those accidental setup edits and reran all 84 cases. The first PHPStan run found one new missing iterable-value annotation on the hydration override; added the accurate `array<string, mixed>` annotation and reran to the identical 21 inherited diagnostics. No failing assertion was removed or weakened.

The JSON evidence records exact commands/exits/results, current hashes, pre-remediation report/evidence hashes, frozen hashes, log hashes and temporary-marker cleanup. The full SQLite process completed with the unchanged PDF-path failure recorded above. The focused PDF rerun passed. Its cause was not established; unchanged source hashes support separating it from the custody fixes, but do not erase the failed run. The single warning matches the inherited baseline; no warning details were emitted.

## Original corrected implementation — historical scope and evidence

The following sections describe the original corrected storage implementation. Their validation counts are historical; fresh remediation results are above. Earlier debug-safety assertions were incomplete for Symfony and are superseded by the independent finding and actual dump tests above.

## Schema and files

| File                                                                         | Change                                                                                                                                                                                                                  |
| ---------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `database/migrations/2026_10_09_010739_create_tenant_ai_storage_tables.php`  | Add the five approved tables through the bounded schema helper; guarded rollback.                                                                                                                                       |
| `database/migrations/2026_10_09_011029_seed_tenant_ai_storage_catalogue.php` | Materialize the accepted immutable VAP-only Anthropic profile. Create only a disabled root when a trusted deployment UUID is configured; otherwise leave controls absent/denied. No tenant backfill.                    |
| `app/Fiscal/TenantAiSchema.php`                                              | Typed columns, composite tenant/deployment/profile references, false defaults, bounds, immutable identities, pending/active partial uniqueness, revision/retention guards and PostgreSQL deferred selection validation. |
| `app/Fiscal/TenantAiCatalogue.php`                                           | Fixed materialization of the accepted profile, digest and integer 552,816 micro-USD reservation; no executable catalogue or approval grant.                                                                             |
| `app/Fiscal/TenantAiCapabilities.php`                                        | Closed CM1 column mapping; unknown tools return no mapping. No runtime authorization integration.                                                                                                                       |
| `app/Fiscal/TenantAiContext.php`                                             | Explicit owner/workspace/deployment context with primary-store freshness, original membership, web identity, email/MFA/session/reauthentication/CSRF and impersonation/automation denial.                               |
| `app/Fiscal/TenantAiKeyFile.php`                                             | Dedicated allowlisted KEK version/file lookup with protected parent/file checks, inode checks and no APP_KEY fallback; restricted known-VAP-secret comparison.                                                          |
| `app/Fiscal/TenantAiEnvelope.php`                                            | Explicit AES-256-GCM envelope encryption, dedicated wrapped DEK, authenticated binding, bounded test-only decryption and redacted serialization.                                                                        |
| `app/Fiscal/TenantAiStorage.php`                                             | Transactional owner-scoped pending storage and allowlisted metadata; no general secret getter or lifecycle mutation methods.                                                                                            |
| `app/Models/TenantAiCredential.php`                                          | Read-only presentation model, strict visible/hidden fields, redacted debug output and denied serialization/model mutation.                                                                                              |
| `app/Exceptions/TenantAiStorageUnavailable.php`                              | Fixed non-reporting failure without underlying secret-bearing exception chain.                                                                                                                                          |
| `config/tenant_ai.php`                                                       | Unset deployment/KEK configuration and empty file allowlist; no provider/model enablement.                                                                                                                              |
| `app/Actions/DeleteUserAccount.php`                                          | Narrow AI retention check in preview/deletion selection and root-ordered transactional recheck before destructive work. Earlier integration-retention changes are inherited, not attributed to this phase.              |
| `tests/TenantAiFixtures.php`                                                 | Synthetic owner/session/deployment fixtures.                                                                                                                                                                            |
| `tests/Feature/TenantAiStorageTest.php`                                      | Storage, mapping, secrecy, isolation, failure, tamper, migration and disconnection regression coverage.                                                                                                                 |
| `tests/Unit/PostgresAssistantProviderTest.php`                               | Preserve every prior assertion while rolling back the empty new dependent migrations before the older provider migration, then restore them and assert zero tenant settings.                                            |
| `tests/Unit/PostgresTenantAiStorageTest.php`                                 | PostgreSQL constraints, creator deletion, migration/rollback, independent-process candidate/deletion races and raw insert protections.                                                                                  |

Only the deletion action is changed among pre-existing runtime files. All other application PHP listed above is new. No existing fiscal schema or five-table provider ledger migration is changed. Exact hashes and the bounded pre-task comparison are in the machine-readable evidence.

The new tables are `ai_model_profiles`, `ai_gateway_controls`, `tenant_ai_settings`, `tenant_ai_connections` and `tenant_ai_credentials`. Creation and seed migrations are separate. Existing tenants gain no settings, acknowledgements, grants or credentials. Absence is denied. Stored candidates create disabled settings and a disabled customer-owned connection; selected profile/version remain NULL, caps zero and all flags false.

| Runtime tool                | Exact boolean NOT NULL DEFAULT false column |
| --------------------------- | ------------------------------------------- |
| `searchCustomers`           | `customer_search_enabled`                   |
| `getCustomer`               | `customer_detail_enabled`                   |
| `getFiscalDocumentSummary`  | `documents_enabled`                         |
| `getQualifiedAgtStatus`     | `agt_enabled`                               |
| `getMonthlyRecordedBilling` | `billing_enabled`                           |

No obsolete customer/catalogue alias exists. The schema helper fixes these migration columns literally; the mapping is separately checked against the existing tool registry. Flags cannot grant actor permissions because no consumption path is introduced. Later integration must implement CM1's full original/current authority intersection and independently filter the two customer tools, rather than filtering only their shared permission.

## Encryption, key custody and secret boundary

Each candidate receives a fresh cryptographically random 32-byte DEK. Installed Laravel Encrypter performs AES-256-GCM string encryption, with no PHP object serialization. The dedicated versioned KEK encrypts a separate bounded JSON envelope holding the base64 DEK and context. Both authenticated plaintext envelopes bind schema, deployment, workspace public identity, connection/version, provider family and fixed endpoint policy. The wrapped envelope also authenticates its KEK version so changing to an alias of the same key cannot silently change wrapping metadata. This does not change the approved cryptographic construction.

Independent expected context is reconstructed from fresh ownership records; it is never accepted from ciphertext. Corruption, whole-pair transplantation, unknown versions, wrong schema, substituted context and altered IV/tag/value deny before the test callback receives plaintext. Plaintext never enters a model attribute or database binding. Envelope insertion uses one prepared PDO statement on the existing Laravel transaction connection to avoid emitting ciphertext/wrapped-key bindings through Laravel query listeners. There is no second database transaction engine or connection. The surrounding transaction, foreign keys and mandatory audit remain authoritative.

Only the internal storage service accepts an opaque bounded single-line secret. It encrypts before inserting credential material. Generic persistence does not validate Anthropic prefixes, select SDK models or contact providers. The separate code manifest binds the currently approved family. Known VAP secret equality is rejected inside the restricted key boundary; this cannot prove ownership of an arbitrary unknown key. Payer remains unassigned and execution unavailable pending later account evidence.

The only decrypt consumer is an explicit storage-test harness requiring test application mode, CLI, fresh owner context and exact row/context binding. It invokes a callback and returns no secret value. Production/FPM calls deny. No global container/SDK binding exposes this harness or the store. Sensitive parameters, nonserializable secret/context objects, fixed failures, explicit metadata projection and read-only/redacted Eloquent presentation limit accidental disclosure. PHP memory erasure is not guaranteed.

Dedicated KEK files must be regular non-symlink canonical files with restrictive permissions, protected parents, bounded exact key length and matching path/open-file identity. Unknown/missing keys deny; no fallback key probing or APP_KEY use. Actual production custody, backup/restore, incident controls and retention approvals remain operational gates. Only disposable inert keys were used in tests.

## Transactions, isolation, audit and deletion

Storage authorizes before entry and revalidates after acquiring the deployment root, before material insertion and before commit. Lock order is root → settings → connection. No ledger/window or transport lock is introduced. New connections are workspace/deployment bound with copied creator A1; the nullable current user relationship may disappear without changing the snapshot. Existing connection candidate insertion requires the expected revision and no prior version; lifecycle replacement is deliberately unavailable.

Composite FKs and immutable guards independently bind workspace/deployment/profile family and credential parent. Missing, foreign and guessed IDs collapse to the same fixed refusal. Read methods select only metadata columns. Eloquent save/delete is denied; retained settings/connections/versions cannot be erased by direct ordinary SQL. Pending/active uniqueness and the root fence prevent duplicate authoritative candidates. PostgreSQL selection constraints validate at commit; SQLite performs its supported immediate counterpart. No SQLite test is used to certify PostgreSQL locking.

Phase 3a additionally rejects non-null payer assignment and verification/use metadata because their mandatory account/probe evidence exists only in later stages. These are fail-closed staging guards, not new lifecycle APIs. Future reviewed migrations must replace them only when the corresponding authoritative evidence checks are implemented. The schema includes the approved lifecycle fields without implementing lifecycle services.

`assistant.ai.credential_configured` is required in the same transaction. Its allowlist includes A1, public workspace/connection/version/profile IDs, server operation ID and fixed pending outcome; IP/User-Agent are explicitly null. Neither plaintext nor ciphertext/wrapped DEK is audited. Audit failure rolls back settings, connection and credential together. Fixed errors drop underlying database/encryption exception chains.

Account deletion recognizes retained settings in preview and performs a root-ordered recheck before deleting dependencies. Stored evidence blocks workspace destruction; user deletion in a surviving workspace may null creator relationships while A1 remains. Empty migration roundtrip is supported; populated custody/audit/approval state refuses destructive rollback. No secret export or upstream revocation is implied.

## Requirement-to-implementation/test mapping

| Requirement           | Implementation and evidence                                                                                                                                                 |
| --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| CM1/default denial    | Exact five mapping keys and columns, independent updates, no obsolete aliases, all flags false, unknown key has no mapping, disabled settings and unchanged provider gates. |
| Plaintext at rest     | Raw credential/settings/connection/activity rows inspected with unique synthetic input. No plaintext persisted; same input produces different envelopes.                    |
| Tenant ownership      | Fresh owner context, scoped service reads, composite FK/identity guards; foreign metadata/candidate/decrypt, guessed IDs and raw SQL attacks rejected.                      |
| Authenticated binding | Modified ciphertext/IV/tag/wrapped DEK/KEK version/schema/context and whole stored-pair transplantation rejected with callback non-delivery assertions.                     |
| Serialization         | Actual model array/JSON/resource/debug output excludes plaintext and envelope bytes; object serialization and model writes/deletes deny.                                    |
| Failure redaction     | Validation, key, forced database and audit failures retain fixed messages/no previous exception; captured logs/audit and raw DB state contain no submitted secret.          |
| VAP separation        | Known deployment secret cannot be copied into customer persistence. No VAP key column or secret-reference selection is exposed to tenants.                                  |
| Atomicity/retention   | Audit rollback, duplicate pending attempt, restrictive deletion, A1 survival, empty and populated rollback tests.                                                           |
| PostgreSQL races      | Independent processes/barriers race candidate creation and account deletion; one candidate/audit wins or deletion wins with no orphan/partial custody.                      |
| Frozen inference      | Production code hashes and source assertions show no store reference in gateway/adapter/planner/transport/private SDK bridge or composition root; zero provider sends.      |

## Validation results

Final focused result: **49 SQLite cases / 218 assertions** and **55 PostgreSQL cases / 276 assertions**, exit 0. The PostgreSQL selection includes the same 49 feature cases plus six PostgreSQL-specific cases; do not sum overlapping counts. A last PostgreSQL-only direct-insert case was added after the initial broad suites started; it passes both the final focused execution and the complete concurrency rerun. The earlier SQLite run skips PostgreSQL-only cases.

Full SQLite: **2,223 passed, 320 PostgreSQL-only skipped, 14,894 assertions**, exit 0, 944.502 seconds, one warning with no details emitted. The initial full PostgreSQL concurrency run had **319 passed / one error**, exit 2, 9,443 assertions: the older provider rollback test did not remove the newly dependent empty storage schema first. Corrected its migration order without changing any production FK or removing any prior assertion; the targeted rerun passed (one case / ten assertions). The complete PostgreSQL concurrency rerun passed **321 cases / 9,456 assertions**, exit 0, 762.902 seconds, with the same single warning/no emitted details seen in the accepted 7B.2 baseline. PostgreSQL integration passed **1,459 cases / 10,850 assertions**, exit 0, 1,815.395 seconds. Exact commands, counts, exits and log hashes are recorded in the [machine-readable evidence](phase-7b-3a-encrypted-credential-storage-evidence.json).

Type checking passed. Assistant UI tests: 12 passed. All 17 changed PHP files passed syntax checks. Pint passed after formatting new files. Final PHPStan has exactly the same **21 diagnostic objects** as accepted 7B.2, zero new; exit 1 is inherited, not a clean static-analysis gate. ESLint still reports **9,719 errors / zero warnings**; resource formatting reports the same four unchanged files. Those are inherited failures, not Phase 3a fixes. The implementation does not weaken tests, suppress diagnostics or repair unrelated debt.

Targeted secret-marker scanning of implementation test logs, application logs and report/evidence artifacts found no submitted synthetic secret. It records only counts/hashes, not plaintext. Temporary key fixtures were removed. Exact final scan/preservation counts appear in evidence. The inherited PostgreSQL suite rewrites the historical Phase 6 evidence artifact; its exact pre-task bytes were restored after all test processes finished. All 33 frozen security-sensitive files remain byte-identical to accepted 7B.2.

## Failed attempts and corrections

- Initial focused setup invoked `migrate:fresh` inside the inherited `RefreshDatabase` transaction: 35 setup errors, zero assertions. Removed that redundant migration call; retained meaningful tests. The shell wrapper returned its trailing log command status, so the underlying test exit was not captured; the test artifact explicitly records failure.
- Initial PostgreSQL run exposed surviving trigger functions across `migrate:fresh`, plus an overly narrow expected exception class for a deferred commit failure. Use idempotent trigger-function definitions and recognize PDO's deferred constraint exception. Final PostgreSQL runs pass.
- Initial PHPStan debug run found five new schema-helper diagnostics; corrected optional regex captures and used the project's prepared statement API for trusted DDL. Final diagnostic objects exactly match the inherited baseline. The first non-debug invocation produced no usable diagnostic output and was not treated as evidence of a clean result.
- The broad PostgreSQL suite exposed a new test-integration dependency: the older provider migration roundtrip could not drop its control table while the approved new payer FK existed. Updated only that test to reverse/reapply the newer empty migrations in dependency order, preserve all original assertions and add a default-deny assertion. The schema protection was correct and remains intact.
- Pint corrected new-file formatting. Later verification added KEK-alias binding, retained-row guards and raw insertion tests; no frozen boundary or signed authority was changed.

## Limits and exclusions

No provider calls, real credential provisioning/import, management routes/UI, live verification, rotation/revocation/rewrap execution, enabled customer selection, gateway credential consumption, generalized accounting, new provider/endpoint, Phase 7B.3b or Phase 8. Envelope primitives contain the approved future wrapping metadata, but this phase has no rotation endpoint/service. There is no public credential metadata API.

Schema/crypto tests do not establish production key custody, true customer payer ownership, provider privacy/geography, retention agreements, commercial entitlement or live FPM deployment readiness. External activation gates remain unchanged. This is an implementation handback, not the independent acceptance review.

## Original independent review handoff (historical)

> Independently review Phase 7B.3a corrected encrypted credential storage only. Read the approved supplement/CM1, this report/evidence, original blocked history and frozen Phase 7/7B.1/7B.2 authority. Inspect actual DDL/triggers, envelope construction, key file boundary, fresh owner context, PDO insertion within Laravel transaction, metadata/redaction, audit and account-deletion changes. Attempt cross-tenant/context/ID substitution, full envelope-pair transplantation, malformed/version alias keys, serialization/debug/exception/log leakage, authority loss, direct SQL integrity bypass, rollback and independent-process races. Verify every CM1 column is independent/default false and no stored credential or flag is consumed by the gateway. Check creator SET NULL versus immutable A1, retained-row/down behavior, deployment defaults, exact frozen hashes and inherited-versus-new diagnostic objects. Distinguish broad-run results, later focused additions, initial failures and operational limitations. Do not treat this report as proof. Do not activate providers, use real keys, add UI or begin 3b. Return findings and an independent verdict only.

## Required implementation assertions

- CAPABILITY MAPPING: ONE-TO-ONE
- CAPABILITY DEFAULTS: DENY
- UNKNOWN CAPABILITIES: FAIL CLOSED
- PLAINTEXT AT REST: NOT PRESENT
- CROSS-TENANT CREDENTIAL ACCESS: DENIED
- CREDENTIAL SERIALIZATION: REDACTED
- AUDIT/LOG SECRET LEAKAGE: NOT IDENTIFIED
- TAMPERED ENVELOPES: REJECTED
- VAP-MANAGED SECRETS: NOT STORED PER TENANT
- AI GATEWAY CREDENTIAL CONSUMPTION: NOT IMPLEMENTED
- PROVIDER ACTIVATION: UNCHANGED
- PRODUCTION INFERENCE: DISABLED

These are bounded synthetic-test implementation results, not production custody or independent security approval. Stop for Astra review; do not begin 7B.3b.

## Exact independent remediation re-review handoff

> Independently re-review only the two MEDIUM Phase 7B.3a custody findings and their bounded remediation. Read the supplement/CM1, this report/evidence and retained historical hashes. Inspect the actual model/envelope native `SensitiveParameterValue` handling, installed Symfony/Laravel cloner behavior, and canonical private-root resolver. Reproduce actual Symfony `VarCloner` + `CliDumper` and cached Laravel `dump()` output on a custody-created credential and its associated synthetic envelope; prove exact ciphertext/wrapped DEK/KEK identifier, plaintext, DEK/KEK, IV and tag markers cannot appear, including attributes/originals/changes. Verify normal serialization/log/audit/exception boundaries remain intact. Attack public/effective webroot, published and signed-served storage, configured aliases, noncanonical/absent roots, symlinks in both directions, traversal, sibling prefixes, permissions, size and version rules; confirm a valid explicitly approved private root succeeds. Check that no real keys were touched, no crypto/schema/capability/gateway changes occurred, all 33 frozen files match accepted evidence, and exactly 21 inherited PHPStan diagnostics remain. Distinguish fresh focused/PostgreSQL/SQLite gates from hash-verified historical broad PostgreSQL results. Do not treat this report as proof or approval. Return findings and an independent verdict. Do not activate inference, add lifecycle/rotation/revocation, consume credentials from the gateway, or begin 7B.3b.

- SYMFONY DEBUG ENVELOPE DISCLOSURE: BLOCKED
- KEK WEBROOT STORAGE: REJECTED
- KEK CUSTODY ROOT: CANONICALLY ENFORCED

The twelve preceding implementation assertions remain confirmed by the current custody and frozen-boundary regressions. No 7B.3b, rotation/revocation lifecycle, gateway credential consumption, OpenAI, self-hosted endpoints, provider activation or live inference was implemented. No real credentials or KEKs were provisioned. Independent re-review is the next action.

PHASE 7B.3a REMEDIATION COMPLETE — READY FOR INDEPENDENT RE-REVIEW
