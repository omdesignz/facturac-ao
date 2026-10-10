# Phase 3A — bounded external read-integration implementation

> **Status-authority amendment SA1 — 2026-10-07:** Read this historical report together with [SA1](phase-4b-status-authority-amendment.md). Only its AGT evidence/status, receipt-eligibility and inherited v1 status semantics are superseded as listed in SA1’s downstream register. Unrelated decisions, historical findings and test results are unchanged. The qualified evidence gate is fail-closed; an unqualified legacy status is not fiscal authorization.

**PHASE 3 IMPLEMENTATION — READY FOR ASTRA REVIEW**

Implementation date: 2026-10-07. This implements only the corrected Sol handoff, including A1, in the unchanged Phase 3 contract. Phase 1, both Phase 2 reports and the Phase 3 design are byte-identical to the pre-implementation copies. The prior narrow A1 review is retained below as a historical decision record.

This is backend review readiness, not external/production enablement. No live migration, live secret, provisioning interface, dependency change or developer `.env` change was made. `integrations.enabled` defaults to false. All generated credentials were confined to disposable tests.

## Backend capabilities and authority

- Hidden `users.attribution_id`: canonical random UUID v4, globally unique, immutable and independent of integer login/session keys. Laravel's installed pre-insert unique-ID hook generates it even when model events are disabled; supplied values are replaced. It does not use the string-primary-key trait or change authentication/route keys. Registration, Google creation/linking and event-suppressed factories are covered.
- Immutable integration workspace/entity/environment binding and creator snapshot pair; separate nullable sponsor-user/membership relationships. Credential snapshots copy their own issuing human, including a different administrator during rotation. Relationship deletion may set NULL; reassignment and snapshot changes are database-rejected. Revocation is terminal. Deleted/recreated integer IDs cannot reconnect sponsorship. Existing retained-account anonymization preserves attribution.
- Internal `IntegrationManagementContext` and `IntegrationCredentials` creation, rotation, expiry replacement, grant reduction and credential/integration revocation. Fresh verified-email, confirmed-MFA, active Owner/Administrator membership, active work session and no impersonation are required. Secret-producing operations also require password confirmation within five minutes. Revocation remains available without recent confirmation. No caller booleans are authority. Existing-integration transitions lock the integration, revalidate authority, check expected revision and lock credentials in ascending order. Terminal revocation is idempotent. Rotation permits at most two live credentials, narrows scopes and caps overlap at 24 hours. No sponsor transfer or grant expansion action exists.
- CSPRNG 256-bit secrets in `fcr1.<uppercase-ULID>.<base64url-secret>` format; only SHA-256 digests and `sha256-v1` are stored. Default expiry is 30 days; accepted lifetime is one hour to 90 days. Disclosure objects are returned only after the outermost transaction commits, reveal once, reject cloning/serialization and hide secrets from debug/JSON output. Query events/logging are disabled around digest persistence and digest-bearing persistence exceptions are replaced with safe errors. Audit failure rolls back credentials and snapshots before any reveal.
- `DocumentReadContext` is the minimal shared read interface. Existing `ExecutionContext` remains the human/scheduled context, with backward-compatible accessors. Only document read/list entry points accept the interface; recurring approval remains concretely human-context-only. Both adapters use the existing typed commands, document capabilities, scoped query, ten-field resource and current AGT projection. No parallel business engine was introduced.

## Stateless authentication and API

Exactly two external GET/HEAD routes under `/api/integrations/v1/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}`:

1. `/documents`: existing bounded list/filter contract.
2. `/documents/{documentPublicId}`: existing safe detail projection.

The global boundary clears ambient attribution, assigns a fresh server UUID, requires HTTPS, consumes pre-auth quota, parses exactly one bounded Bearer header and verifies the digest/current credential on the primary database. No web/session middleware is attached and cookies do not authenticate. Trusted-proxy resolution precedes TLS/IP evaluation; untrusted forwarding headers cannot change either.

The private machine context derives binding and identity from verified records. The adapter validates/canonicalizes explicit route ULIDs and compares them only with the binding before querying documents. At each capability invocation, a single fresh primary-database joined authorization snapshot intersects credential state, integration state, both exact grants, original membership, sponsor verification/MFA/current role, entity ownership, immutable binding and the `documents.read` capability mapping. A valid secret alone never grants a read. Temporary ineligibility can restore authority if all constraints are restored; deletion or terminal revocation cannot.

Only `documents:read` exists. Wildcards, aliases, admin/write scopes, duplicates and case/whitespace variants are rejected. Empty grants deny reads. Cookie workspace selection, ambient impersonation, client IDs and act-as headers cannot grant or attribute machine authority. Production-bound reads query existing records without activating outbound AGT.

Safe envelopes preserve 401/403/404/405/422/429/500/503 semantics, no-store, fresh correlation headers, generic Bearer challenge and bounded Retry-After. Unknown/foreign documents inside the binding share 404; all well-formed out-of-binding contexts share 403 without unrelated entity lookup. Invalid client metadata is not stored or echoed. HEAD follows authorization/audit/status and has no body. The external OpenAPI describes separate bearer security, explicit context, these routes only, ten fields, HEAD and safe errors; the session OpenAPI is unchanged.

## Quotas, audit and redaction

Shared fixed-window quotas are IP 120/minute, stable integration 60/minute and workspace 300/minute. Database cache locks and transactional atomic database increments avoid per-process or replica counter reads. Every applicable authenticated bucket is consumed, including later denials; rotation/second credentials do not multiply quota. Anonymous spoofed forwarding headers cannot multiply quota. Unsafe stores, failed locks or cache/database outages fail closed with sanitized 503. Hosted multi-worker/proxy/store configuration remains a release gate.

Machine success/denial audit explicitly uses Integration causer/kinds, credential and bound tenant references, separate sponsor authority, null human executor/impersonation/approval/automation, capability version, operation UUID, server correlation, optional validated client correlation and safe resource/result metadata. Lifecycle events use the actual human causer, immutable creator snapshot, actual actor UUID, before/after revision/grant/rotation expiry data and constrained reason codes. Authorized lifecycle denials are recorded; unverified/now-ineligible management and anonymous read denials use bounded security telemetry without claimed tenant identity. Lifecycle mutations and successful audits commit together; read audit failure releases no data. Last-used updates touch only that field and cannot undo revocation or move backwards.

Ambient user-agent is suppressed for integration events. App logging redacts sensitive field names and bearer-prefix patterns; credential/context objects are redacted rather than dumped. External exception reporting logs only safe correlation information. Proxy/APM/request-capture redaction outside this application still requires deployment verification; no claim of controlling external infrastructure is made.

## Migrations and rollout

- `2026_10_07_123804_add_attribution_identity_to_users.php`: nullable UUID + unique index, null-only primary-key chunks of 200, server v4 backfill preserving committed assignments, final NOT NULL/shape/update guards. PostgreSQL native UUID and validated SQLite representation; SQLite foreign-key integrity checked. Rollback refuses when integration/credential rows or attribution audit evidence exist, independently of table presence.
- `2026_10_07_123805_create_integration_credentials.php`: integration, credential and exact grant tables; ownership/replacement composite FKs, unique selectors/digests, expiry/hash/kind/environment/reason checks, supporting live-credential index, immutable snapshot/binding/relationship guards and terminal revocation guards. Empty rollback is allowed; evidence-bearing rollback refuses.

Installed Laravel Schema lacks the required portable CHECK/trigger declaration API, so the new integration schema uses explicit PostgreSQL/SQLite DDL. This matches the signed intended PostgreSQL production database and retained SQLite test environment; MySQL is not an enabled integration migration target. No application database was migrated. Deployment must pause user creation/updates and remain in maintenance until A1 backfill, final constraints and UUID-producing code are present. Empty rollback/reapply and populated staged retry preserve original user/auth/session/membership/fiscal bytes; no historical audit attribution is guessed.

## Files in this bounded implementation

Modified existing/baseline files:

- `app/Models/User.php`
- `app/Fiscal/ExecutionContext.php`
- `app/Fiscal/DocumentCapabilities.php`
- `bootstrap/app.php`
- `config/logging.php`
- `.github/workflows/tests.yml`
- `tests/Unit/PostgresFiscalConcurrencyTest.php` (empty rollback now spans five final migrations instead of three; prior fiscal assertions preserved)
- `docs/phase-3a-implementation-report.md`

Added:

- `app/Fiscal/DocumentReadContext.php`
- `app/Fiscal/IntegrationCredentials.php`
- `app/Fiscal/IntegrationManagementContext.php`
- `app/Fiscal/IntegrationReadContext.php`
- `app/Fiscal/IntegrationRateLimiter.php`
- `app/Fiscal/IntegrationSchemaGuards.php`
- `app/Fiscal/IntegrationLogRedactor.php`
- `app/Fiscal/IssuedIntegrationCredential.php`
- `app/Fiscal/SensitiveCredentialQuery.php`
- `app/Models/Integration.php`
- `app/Models/IntegrationCredential.php`
- `database/factories/IntegrationFactory.php`
- `database/factories/IntegrationCredentialFactory.php`
- Both migrations listed above
- `app/Http/Controllers/Api/V1/ExternalDocumentReadController.php`
- `app/Http/Middleware/ExternalIntegrationBoundary.php`
- `config/integrations.php`
- `routes/integrations.php`
- `tests/Feature/AttributionIdentityTest.php`
- `tests/Feature/ExternalIntegrationTest.php`
- `tests/Unit/IntegrationLifecycleTest.php`
- `tests/Unit/PostgresIntegrationConcurrencyTest.php`
- `docs/openapi-external-read-v1.json`

The larger pre-existing Phase 1/2 working tree remains intact; this manifest is only the bounded Phase 3 change, not every dirty file in the repository.

## Regression matrix and final validation

**67 new fast test cases and 8 PostgreSQL-only cases** (75 total, including datasets) were added. New test files cover generated/hidden/immutable attribution, existing authentication paths, populated/partial/retry/rollback upgrades and original fiscal/session bytes; issuer/sponsor snapshot separation, rename/removal/anonymization/deletion and integer-ID reuse; secret parsing/storage/reveal/clone/serialization, scope ceiling and aliases, stale revisions, lifecycle audit rollback and current management authority; cookie/ambient-state isolation, scope/sponsor rechecks, expiry/revocation/restoration, cross-tenant/entity/environment enumeration, production read isolation, safe ten-field bounded constant-query reads, HEAD/method errors, failed audit, client metadata, log redaction, trusted proxy behavior and unavailable/shared quotas.

Eight new PostgreSQL independent-process/constraint cases prove single-winner rotation, rotation/revocation serialization, revocation-before-final-authorization denial, already-authorized document read completion after revocation, monotonic last-used/revocation races, shared integration and workspace limits, immutable updates and composite insert rejection. Original fiscal concurrency tests and assertions are retained.

| Gate                                                                   | Final result                                                                                                                                                                        |
| ---------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Full SQLite suite                                                      | **930 passed / 5,196 assertions**, 20 PostgreSQL-only skips, 1 inherited PHP warning; zero failures/errors. Prior 863 / 4,681 preserved; 67 new fast test cases / 515 assertions.   |
| PostgreSQL 18.6 fiscal + integration process/constraint gate           | **20 passed / 259 assertions**, 1 inherited PHP warning. Contains all 12 prior fiscal tests / 186 assertions and 8 new integration cases / 73 assertions.                           |
| PostgreSQL session API + new attribution/external API/lifecycle matrix | **106 passed / 845 assertions**, zero failures/errors. Prior session API 39 / 330 preserved; new 67 / 515.                                                                          |
| PHPStan                                                                | **21 existing diagnostics, zero new**; exact baseline file/line/identifier comparison passes. Analyzer still exits 1 for these pre-existing errors; no baseline/suppression change. |
| Type checking                                                          | `npm run types:check` passes; no frontend type change.                                                                                                                              |
| Pint                                                                   | `vendor/bin/pint --dirty --format agent` passes after formatting; no PHP suppression added.                                                                                         |
| Changed-file ESLint                                                    | All six previously changed Vue files pass; no Phase 3 frontend implementation.                                                                                                      |
| Changed-file Prettier                                                  | Updated implementation report, external OpenAPI, workflow and six previously changed Vue files pass. Governing reports untouched.                                                   |
| Integrity                                                              | Tracked/untracked diff whitespace checks, external OpenAPI regression assertions, and all four governing report SHA-256 comparisons pass.                                           |

The disposable PostgreSQL 18.6 cluster used only `facturac_test_phase1` at localhost port 55439; tests guard testing environment/database identity. Full SQLite uses `:memory:`. OpenSSL's temporary random-state path was redirected to a permitted temporary path after a sandbox-only write error; no test or assertion was weakened. The inherited PHP warning is the no-effect `use DomainException` in `PhaseFiveSubscriptionBillingTest.php:20`; it remains outside this slice. The previously observed PDF QR flake did not recur in the final full runs. npm reported inherited unknown `user`/`unsafe-perm` settings without gate failures. Hosted CI is configured but was not executed here.

## Deviations, assumptions and release gates

No signed normative decision was changed. No additional Astra architecture decision was required. A1 and E1 are implemented as authorized; prior governing report hashes are unchanged. Tests passing do not approve external enablement.

Astra must review the actual authentication/capability boundary, lifecycle locking/disclosure/audit and migration evidence before this feature can be enabled. Production database version parity/backup/maintenance rollout, hosted CI, primary-database configuration, HTTPS/trusted-proxy configuration, shared limiter under hosted workers, protected audit retention/access and proxy/APM/exception redaction are still release gates. Client secure storage and the incident response process remain operational obligations. A copied bearer acts as its integration until expiry/revocation; no sender-constraint guarantee is claimed.

Existing AGT homologation assumptions remain open: real AGT envelope/signature acceptance, series/environment behavior, fiscal numbering/status and receipt/correction semantics require their existing homologation evidence. This read-only phase performs no production AGT operation and certifies none of those assumptions.

Deliberately absent: management UI/API/CLI, general live credential provisioning/recovery, customer/catalogue/analytics APIs, mutations, issuance/corrections/receipts, recurring expansion, AGT configuration/credentials/production operations, exports/delivery, agent tools/identities/delegation, BYOW, autonomy and outbound webhooks. No next phase was started.

## Exact recommended next action

Use **Astra Medium** for a Phase 3 trust-boundary review of this implementation against the unchanged Phase 3 contract including A1, the prior signed reports, new migrations, capability/authentication/lifecycle code, OpenAPI and regression results. Verify the four-way authority intersection, live sponsor checks, revocation linearization, rotation concurrency, one-time secret disclosure, immutable attribution, error/log/audit isolation and shared quotas. Approve or return bounded fixes; do not enable external production access, add provisioning, broaden capabilities or begin the next roadmap phase in that review.

**PHASE 3 IMPLEMENTATION — READY FOR ASTRA REVIEW**. Implementation stops here for review.

---

## Historical A1 review record

The following records the earlier narrow Astra decision, before backend implementation. Its statements about no implementation apply to that earlier review, not the implementation report above.

# Phase 3A — identity-contract review and implementation status

**PHASE 3A IDENTITY CONTRACT — RESOLVED**

P3A-01 is resolved by amendment A1 in `phase-3-external-read-integration-contract.md`. The narrow Astra prompt from the original blocker report has been executed. The backend remains unimplemented; this is permission to resume the corrected bounded Sol handoff, not implementation or production sign-off.

## Evidence and decision

The original implementation preflight correctly found that `User`, its creation/authentication flows, factory and users-table migrations provide only integer IDs, while the design required immutable creator public-ID snapshots. No placeholder identity was implemented.

Reviewed the existing model, all users-table migrations, Fortify `CreateNewUser`, Google account creation/linking, `DeleteUserAccount`, `AccountController`, factory and identity tests in `PhaseOneAuthenticationTest`, `SocialAuthenticationTest`, `AccountAndDataTest` and the existing capability/API regressions. Account deletion may hard-delete the user or retain an anonymized row; workspace memberships are removed in both paths. Existing authentication and audit use integer keys.

**Selected:** one hidden, immutable `users.attribution_id`, canonical random UUID v4, globally unique within the installation and independent of workspace. Generate once before creating a new user; safely backfill existing rows. Copy it into immutable `creator_principal_kind=user` / `creator_attribution_id` snapshot columns on integrations and credentials. Snapshots have no FK to users; nullable live creator/sponsor relationships remain separate and continue enforcing current authority. The identifier is attribution-only and does not enter the general public User contract.

The prior report's recommended internal-integer snapshot option is **not adopted**. It would be safe in protected storage, but ties historical evidence to the database's integer namespace and leaves future portable attribution unresolved. A single hidden UUID field addresses that without replacing authentication, adding an identity registry or exposing a User API. The full option comparison, exact fields, migration/rollback constraints, disclosure rules, account lifecycle and additional acceptance criteria are normative in A1.

The sole migration-boundary amendment permits adding/backfilling/finalizing `users.attribution_id` and its constraints/update guard before the approved integration tables. Public lookup, user resource expansion, scope expansion, delegation and machine authority changes remain excluded. Phase 1/2 reports are unchanged; all other Phase 3 decisions are preserved.

## Relationship versus snapshot

The integration's sponsor relationship points to the initial creator because transfer remains forbidden; each credential's `created_by_user_id` points to its issuing human. Those nullable FKs answer who exists now. Snapshot UUID/kind fields answer who created that particular record and remain unchanged after rename, email change, workspace removal, anonymization or deletion. A different administrator rotating a credential gets their own new credential snapshot without rewriting the integration creator. Neither snapshot nor knowledge of a UUID is current authority.

No sequential human IDs are newly exposed externally. The new UUID is also hidden from ordinary serialization, Inertia user data, document responses and errors. Future disclosure requires a separately reviewed purpose and scope because even an opaque global identifier can correlate users across tenants. Agents/workflows need their own typed identities and cannot inherit authority from a creator UUID.

## Review validation and implementation accounting

Only `docs/phase-3-external-read-integration-contract.md` and this report were changed in this review. Formatting and diff integrity were checked; prior Phase 1/2 report hashes remain unchanged. No application code, migration, test, credential, route or OpenAPI surface was implemented or changed. No runtime test results are newly claimed.

The subsequent implementation must run all preserved gates plus A1 tests: populated/backfill/retry/rollback, generation across existing auth paths, copied attribution through rotation and deletion, raw/bulk immutability, FK nulling and absence from public serialization. Inherited baselines remain SQLite 863 / 4,681; PostgreSQL fiscal concurrency 12 / 186; PostgreSQL session API 39 / 330; PHPStan exactly 21 existing diagnostics. Types, Pint, lint/format and new integration/concurrency gates remain required. Existing operational/homologation gates remain unchanged.

P3A-01 has no remaining identity-design blocker. The implementation is pending Sol work and later Astra trust-boundary review. Management UI/live provisioning and all other excluded roadmap features remain deferred.

## Exact Sol Medium resume instructions

The following is the corrected canonical implementation handoff, copied from the amended design. Execute only on a subsequent user instruction to resume implementation; this review stops here.

> Use Sol Medium for Phase 3A: the external read-integration backend foundation. Read `docs/phase-1-capability-contract.md`, `docs/phase-2-read-first-foundation.md`, `docs/phase-2-trust-boundary-review.md`, and `docs/phase-3-external-read-integration-contract.md` in full, including E1, amendment A1 resolving P3A-01, the four authority boundaries, lifecycle rules and acceptance criteria. Preserve the signed Phase 1 invariants and approved session API. If any normative decision must change, stop and return it for Astra review.
>
> First implement A1 exactly: hidden immutable users.attribution_id UUID v4 with the bounded populated backfill, uniqueness/update guards and deletion-safe creator snapshot pairs; preserve integer authentication/relationship keys and default User disclosure. Add the A1 lifecycle, rollback and serialization regression tests. This is the sole expansion of the earlier integration-only migration boundary. Then implement the proposed additive integration/credential/grant schema, internal verified-human lifecycle actions and private machine verifier/resolver/read context; the minimal shared read-context extension to existing DocumentCapabilities; safe explicit machine audit/redaction and last-used behavior; and the two stateless external GET/HEAD document adapters under `/api/integrations/v1`, disabled by default. Reuse the existing typed commands, scoped query, serializer and CurrentAgtState. Implement the specified effective-authority intersection, exact documents:read allowlist, expiry/revocation/rotation concurrency, error/enumeration behavior and shared rate limits. No new dependency without approval. Verify installed Laravel APIs and inspect schema before migration work.
>
> Lifecycle actions may generate test credentials in disposable tests only. Do not expose a management UI, management API, provisioning CLI or recovery/reveal endpoint; do not issue live secrets or enable the external feature. Treat trusted human-management context/recent confirmation checks as independently tested application boundaries, not caller booleans. Do not call human ExecutionContext::resolve with a sponsor to impersonate a machine. Keep recurring approval strictly human-context-only and all fiscal operations unchanged.
>
> Add adversarial tests for every acceptance item, including PostgreSQL independent-process credential lifecycle, revocation boundary and shared-limit races. Run the full SQLite suite, existing and new PostgreSQL integration/concurrency suites, PHPStan baseline comparison, types, Pint, changed-file lint/format and diff checks. Never run destructive tests or migrations against an application/production database. Retain every preceding test and report any intermittent failure honestly.
>
> Update a Phase 3A implementation report and the separate external OpenAPI description without changing the session API contract. Report exact files/migrations, scope/principal mapping, lifecycle/audit behavior, every test result and remaining operational gates. Exclude customer/catalogue/analytics APIs, draft mutations, fiscal issuance/corrections/receipts, recurring expansion, AGT operations/configuration/credentials, exports/delivery, agents, BYOW, autonomy and webhooks. Do not expose generic scope wildcards or token-controlled authority. Finish with readiness for Astra trust-boundary review, not production approval. Stop after this bounded implementation and wait for review.
