# Phase 2 — bounded shared capabilities and read-first API

> **Status-authority amendment SA1 — 2026-10-07:** Read this historical report together with [SA1](phase-4b-status-authority-amendment.md). Only its AGT evidence/status, receipt-eligibility and inherited v1 status semantics are superseded as listed in SA1’s downstream register. Unrelated decisions, historical findings and test results are unchanged. The qualified evidence gate is fail-closed; an unqualified legacy status is not fiscal authorization.

Post-review update: see `phase-2-trust-boundary-review.md` for corrected trust-boundary behavior, final validation and approval. The implementation record below describes the pre-review baseline.

Status: bounded implementation complete; ready for Astra Medium review. Phase 1's signed contract and exact Sol Medium handoff were read in full and followed. The master prompt's model-routing guide was also read. The signed report is unchanged, verified by SHA-256. No governing invariant needed modification or escalation.

## Capabilities and API surface

`DocumentCapabilities` now accepts typed, validated `DocumentReadCommand` and `DocumentListCommand` inputs. The existing `read(context, publicId)` interface and recurring approval method remain compatible. Read/detail and paginated list share one workspace/entity/environment query and whitelist serializer; they delegate AGT semantics to `CurrentAgtState`. Lists eager-load only submission identity/status, avoiding per-document queries and raw payload loading. No fiscal calculations, totals, numbering, signing, issuance or financial aggregate engine was duplicated.

There are exactly two new routes, GET/HEAD only:

- `/api/v1/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents`
- `/api/v1/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}`

The list accepts only `page` (1–10,000), `per_page` (1–50, default 25), `agt_status` (an existing projected status) and `type` (existing fiscal document type). Unknown fields, authority-shaped inputs, arrays where scalars are required and invalid enum values fail validation. The detail accepts no query parameters. Ordering is descending document identity. Pagination counts are restricted to the same resolved scope; no totals combine currencies. Each document retains its own currency and integer minor-unit gross amount.

Both return `data` plus `meta` containing server request ID and explicit public workspace/entity/environment identity. The list adds page, per-page, total and last-page metadata. Fields are limited to public document ID, number, type, environment, revision, currency, gross minor units, persisted fiscal status, current AGT projection and projection provenance (`draft`, `submission`, `legacy`). No customer PII, internal tenant IDs, JWS, credentials, request bodies, signing evidence or raw AGT responses are returned.

`docs/openapi-read-v1.json` documents these two paths, inputs, safe fields, envelopes and existing session authentication. It does not add a documentation endpoint or a credential product. The cookie name shown follows the `.env.example` application-name default; deployments must use their configured `SESSION_COOKIE`/`APP_NAME` cookie.

## Authorization, isolation and audit

The API uses the existing `web` session/cookie, work-session enforcement, impersonation handling, authentication and verification pipeline. The Inertia rendering/asset-version middleware is excluded only from the API route group; the existing Inertia application and view permissions are preserved.

The request validates explicit path identifiers, resolves the legal entity inside the requested workspace, then calls the unchanged `ExecutionContext::resolve(..., readOnly: true)`. Verification, MFA, active membership, server-defined read permission and real/effective actor semantics remain authoritative. Every capability calls `authorize`, which reloads current actor/entity/membership. Neither API resolution nor capability queries use `User.current_workspace_id`. Per-entity user grants were not invented: membership remains workspace-wide, and each operation narrows to one explicit entity/environment as signed in Phase 1.

Supported callers remain current authenticated humans, including read-only support impersonation under existing protections. Machine principals and credentials have no new entry path. Production-environment reads of already known records do not invoke or enable production AGT. `unresolved` records never match an enum-backed environment scope.

Successful detail/list calls write safe capability audit with actor, scope, environment, correlation and capability version. Authenticated forbidden/not-found responses write a separate denial event after the failed read returns; a resolved context is used when available. Requests denied before authoritative context resolution do not manufacture tenant evidence. The trusted request attribute carrying context is for audit attribution, never authorization. No raw query or arbitrary caller fields are logged.

`ReadApiResponse` affects only `/api/v1/*`. It forces JSON negotiation before session/verification middleware, gives each request fresh server correlation, normalizes Portuguese safe error envelopes and sets private/no-store caching. It preserves retry/allowed-method headers, suppresses raw exception details, and represents expired session/impersonation redirects as authentication-required JSON. Existing non-API response behavior is unchanged. Routes are throttled to 60 requests/minute using Laravel's authenticated-user limiter. Read commands require no consequential-command idempotency ledger; that signed future protocol remains reserved for separately approved mutations.

## Phase 2 files and migrations

Changed/new files for this phase only (preceding Phase 1 working-tree changes remain intact):

- `bootstrap/app.php`
- `routes/api.php`
- `app/Fiscal/DocumentCapabilities.php`
- `app/Fiscal/DocumentReadCommand.php`
- `app/Fiscal/DocumentListCommand.php`
- `app/Http/Controllers/Api/V1/FiscalDocumentReadController.php`
- `app/Http/Requests/ReadDocumentApiRequest.php`
- `app/Http/Resources/FiscalDocumentReadResource.php`
- `app/Http/Middleware/ReadApiResponse.php`
- `tests/Feature/ReadFirstDocumentApiTest.php`
- `docs/openapi-read-v1.json`
- `docs/phase-2-read-first-foundation.md`

**No new migrations or dependencies.** The API requires the signed Phase 1 schema, including durable environment columns. No production/application database migration was performed. The PostgreSQL cluster used here was the same guarded disposable 18.6 database, and was stopped after validation.

## Tests and complete validation

29 new API/capability regression cases cover authentication, verification/MFA, inactive/revoked membership, all five roles, browser workspace switching, foreign and sibling entities, workspace/entity mismatch, environment separation, unresolved quarantine, status projection/provenance and filter agreement, pagination/currency, unknown/invalid/authority-shaped input, direct typed-command enforcement, work-session expiry, real/effective actor audit, Inertia-header independence, method restrictions, rate-limit/retry behavior, fresh IDs for unknown paths, safe schema fields and OpenAPI route parity. Existing Phase 1 tests were retained without weakening.

| Gate                                       | Phase 1 baseline              | Phase 2 result                                                                                           |
| ------------------------------------------ | ----------------------------- | -------------------------------------------------------------------------------------------------------- |
| Full SQLite suite                          | 824 passed / 4,351 assertions | **853 passed / 4,619 assertions**, 49.963s; 12 PostgreSQL-only cases skipped in this tier                |
| PostgreSQL 18.6 integrity/concurrency gate | 12 passed / 186 assertions    | **12 passed / 186 assertions**, 8.122s; unchanged tests, actual forked contention                        |
| New API suite on PostgreSQL 18.6           | None                          | **29 passed / 268 assertions**, 2.526s                                                                   |
| PHPStan                                    | 21 pre-existing diagnostics   | **21 identical diagnostics, zero new**, diagnostic table compared to Phase 1                             |
| Types                                      | Passing                       | `npm run types:check` passed                                                                             |
| PHP formatting                             | Passing                       | `vendor/bin/pint --dirty --format agent` passed                                                          |
| Changed-file frontend lint/format          | Passing                       | Targeted ESLint and Prettier passed for all previously changed Vue files; Phase 2 adds no frontend files |
| Documentation/workflow formatting          | Passing baseline              | Prettier passed for the new OpenAPI/report and existing workflow                                         |
| Diff integrity                             | Passing                       | `git diff --check` passed                                                                                |

The compact full-suite and PostgreSQL gate runners still report the pre-existing warning without details. Repository-wide ESLint/Prettier debt recorded in Phase 1 remains outside modified frontend files; no unrelated fixes, test deletions, baseline suppressions or dependency changes were made. Overall `composer ci:check` is not claimed globally green. Hosted CI execution and production database parity remain unverified Category C gates.

## Deviations, assumptions and unresolved work

**No deviation from the signed Phase 1 contract.** This is the conservative document-read slice of its exact bounded handoff. Customer/catalogue APIs, analytics APIs, exports and standalone AGT configuration/readiness surfaces were not implied additions to this slice. AGT status is provided only through the existing document projection.

No fiscal issuance/corrections/receipts, autonomous recurrence expansion, external delivery, production AGT operations/configuration/credentials, integrations, API tokens, agents, BYOW, autonomy, webhooks or consequential commands were introduced. There is no public approval route for the existing internal recurring approval capability.

The existing Phase 1 B/C/D gates remain: homologation assumptions for affected fiscal capabilities; actual production PostgreSQL version, hosted CI and deployment/backup inventory; later explicit credential/delegation contracts, idempotency ledger and verified analytics queries. New production deployment still requires the signed migration/operational procedure. This session read surface is not external integration authentication.

## Recommended next model and phase

**Astra Medium — review the completed bounded Phase 2 implementation** against the signed contract. Review the actual scope resolution, session pipeline, JSON error/cache behavior, safe outputs, audit attribution and all test evidence. Clear any blocking trust-boundary finding before authorizing a further bounded slice. An external credential/scopes design belongs to Astra; routine additional approved reads can subsequently be handed to Sol. Do not start Phase 3, mutations, fiscal exposure or any other excluded capability automatically.
