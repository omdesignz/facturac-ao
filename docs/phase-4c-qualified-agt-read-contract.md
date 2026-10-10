# Phase 4C — versioned external qualified AGT read contract

Design date: 2026-10-07. **Design only.** This executes the exact Phase 4C handoff in [the approved Phase 4B review](phase-4b-trust-boundary-review.md), constrained by the user's expanded design request.

**PHASE 4C DESIGN — APPROVED FOR SOL IMPLEMENTATION**

Approval authorizes only a subsequent bounded implementation turn and Astra review. Nothing is implemented or enabled by this document. Phase 1–4B and SA1 remain authoritative. No fiscal eligibility, evidence interpretation, worker/recovery or identity architecture is changed.

## 1. Basis, inspected implementation and decisions

The full governing reads from the preceding phases are reused: Phase 1 contract; Phase 2 foundation/review; Phase 3 integration contract, Phase 3A/A1 report and review; Phase 4A contract/implementation/review; SA1; and Phase 4B design/implementation/review. Their hashes were verified unchanged against the previous review snapshot; the final Phase 4B review was written/read in the preceding turn. Its approval is present, with no unresolved Phase 4B BLOCKER/IMPORTANT finding.

Re-inspected `CurrentAgtState`, `AgtStatusPresentation`, projection validation/reducer, observation provenance/casts, scoped document capabilities/resource, integration context/credential lifecycle, external boundary, route registration/exception rendering, denial audit metadata, database scope guards, both V1 OpenAPI specifications and relevant projection/API/concurrency tests. The approved evidence remains protected; this design is a new disclosure contract over its qualified projection, not a new reducer.

Decisions:

1. One dedicated **external V2 document AGT-status detail resource**, with ordinary JSON and explicit path versioning. No V2 general document API, list, batch, search, export or session HTTP route.
2. Independent exact scope **`documents:agt-status:read`**, mapped to capability permission **`documents.agt-status.read`**. No implication from/to `documents:read`, `customers:read` or `catalogue:read`.
3. A closed 12-field representation plus the existing four-field context/correlation envelope. No receipt/fiscal eligibility, raw evidence, human free text, recommended action or retryability field.
4. Preserve V1 exactly as deprecated legacy workflow summary. Coexistence is explicit; no redirect, content negotiation or silent substitution.
5. Both bound production and homologation records may be read; Phase 4A's production-only master-data rule remains specific to customers/catalogue.
6. Reads may write only the existing required read/denial audit, monotonic credential-last-used and shared quota metadata. They never contact AGT, poll, refresh, rebuild, reconcile or mutate fiscal/projection/evidence state.

## 2. Resource and version separation

Only these methods/path are authorized for implementation:

```text
GET  /api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status
HEAD /api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status
```

Route name: `integrations.v2.documents.agt-status.show`. GET operation ID: `externalReadQualifiedAgtStatusV2`; HEAD: `externalHeadQualifiedAgtStatusV2`. Successful media type is `application/json`; no vendor media negotiation, version header, V1 alias or redirect. Path version is the representation version; do not expose the reducer version as an API version.

Public path IDs use existing ULIDs, validated case-insensitively and normalized to lower case. Environment is exactly `homologation` or `production`; `unresolved` is not a selectable context. The client must already know a document public ID. Status-only credentials cannot discover IDs through a list. A separately granted V1 document-read capability or the integrating application's existing authorized records may supply an ID; that does not confer status authority.

No query parameters are accepted, including pagination, filters, search, `include`, `fields`, `refresh`, `as_of`, locale or cache overrides: return generic 422, without querying documents. No ordering or pagination contract is needed for a single detail resource. A structurally valid unknown ULID reaches the scoped lookup; do not encode existence in route constraints. Existing empty-body allowance (`''`, `{}`, `[]`) remains; nonempty data, form fields and files fail 422. No write method is added; known-path unsupported methods return 405 with `Allow: GET, HEAD` through the existing sanitized boundary.

The route never queries the other environment, substitutes the workspace browser selection, impersonates the sponsor, or uses a connection's current environment to relabel historical records. Bound production reads do not require or activate production AGT network operations. A mutable draft explicitly bound to the requested environment may return `not_applicable`; unresolved drafts and documents are excluded by the same scoped query and return 404.

## 3. Shared capability and snapshot boundary

Introduce `DocumentCapabilities::readQualifiedAgtStatus(DocumentReadContext $context, DocumentReadCommand $command): array` (a closed DTO is also an acceptable implementation detail). Its permission is `documents.agt-status.read`. The controller validates protocol/path input and invokes this method; its resource only serializes the explicit allowlist. No permission, state inference or scope evaluation lives in the resource.

The capability must:

1. Revalidate the live context on the primary before lookup. A credential is identity, its scope is a grant, the immutable tuple is its context, and the capability permission is the operation authorization. All apply together.
2. Obtain one coherent **primary database statement snapshot** of the scoped document and its same-context submission projection/workflow. Use a bounded left join by document ID plus workspace/entity/environment, with explicit selected columns. Materialize the required internal models/DTO and preloaded submission relation without lazy-loading evidence. Do not combine a document selected before issuance with a submission selected after issuance. No row locks, evidence replay, protected-body decryption or receipt-gate call are needed for this read.
3. Use the existing `CurrentAgtState` qualified facade/validation and `AgtStatusPresentation` explanation vocabulary at one UTC evaluation instant. The external mapping below only minimizes and masks the qualified result; it must never manufacture a more authoritative state. It is not another evidence reducer. Existing internal UI and receipt semantics are unchanged.
4. Capture `as_of` from the primary database clock immediately after materializing that snapshot (existing database-clock helper is reusable). It is the instant used to evaluate freshness, **not** a claim that every commit before that instant is included. Concurrent changes may commit after the statement snapshot. A read returns the coherent pre-commit or post-commit state; it is never an authorization lease or linearizable promise about a later action.
5. Construct and validate the entire closed representation before successful required audit and last-use metadata. Persist required audit before returning data. No cached document/projection/authority instance may be reused across requests. Never rebuild a missing cache during a read.

The joined query must bind all document predicates before selection and all submission tuple predicates in the join. Do not accept a foreign submission or merge observations from multiple aggregates. Existing unique submission identity/indexes suffice; no new fiscal index or column is approved. A missing bound submission yields bounded uncertainty for an existing issued document, not a synthetic submission. Existing primary-authority checks determine the authorization linearization point: withdrawal visible before that check denies; withdrawal committed afterward need not erase an already authorized in-flight read. No new authority locks are introduced.

For internal direct callers, extend `ExecutionContext`'s existing document-view read permission mapping to this named read permission with the same current membership, verification/MFA and read-only support attribution rules. No new human role or session endpoint is granted. Future callers can reuse the capability/context interface; no agent principal, delegation or agent scope is introduced. Existing UI already shares the qualified facade; no UI rewrite is in this handoff.

## 4. Exact external schema and minimization

Successful envelope has exactly `data` and `meta`. `meta` has exactly `request_id`, `workspace_public_id`, `legal_entity_public_id`, `environment`; values come from the trusted resolved context, never reflected caller overrides. `data` has exactly the following **12 required keys**; nullable keys remain present:

| Field                     | Type / closed values                                                                  | Meaning                                                                                                                                                                                                                                                   |
| ------------------------- | ------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `document_public_id`      | lowercase public ULID                                                                 | Document resource identity only.                                                                                                                                                                                                                          |
| `knowledge`               | `not_applicable`, `known`, `unknown`, `insufficient_evidence`, `conflicting_evidence` | Current disclosure confidence according to the mapping below, not fiscal validity.                                                                                                                                                                        |
| `reported_state`          | `valid`, `invalid`, `processing`, `processing_cancelled`, null                        | Qualified supported AGT report only when `knowledge=known`; otherwise null. `processing_cancelled` does not mean fiscal cancellation.                                                                                                                     |
| `synchronization`         | `not_applicable`, `idle`, `pending`, `failed`, `unknown`                              | Qualified synchronization facet, not an instruction or promise that a future job will run.                                                                                                                                                                |
| `freshness`               | `not_applicable`, `recent_observation`, `stale`, `unverified`                         | Age of the **exposed reported state**, never an expiry of acceptance or an age of a workflow label.                                                                                                                                                       |
| `observed_at`             | UTC date-time or null                                                                 | Local observation time supporting the exposed reported state. Null when no current state is exposed, even if internal history retains an observation.                                                                                                     |
| `last_successful_sync_at` | UTC date-time or null                                                                 | Last supported successful AGT-state observation retained in a valid projection; may predate current uncertainty. It does not say that the earlier result was V. Never advanced by a read, rebuild, transport acknowledgement or failed/unsupported query. |
| `as_of`                   | UTC date-time                                                                         | Single freshness evaluation instant described in §3. Always present.                                                                                                                                                                                      |
| `effective_at`            | null only                                                                             | No verified remote/legal effective time is available under this contract. Never copy observation, issue, update or projection time here.                                                                                                                  |
| `provenance`              | `not_applicable`, `agt_observation`, `workflow_only`, `unverified`                    | Source category of the currently disclosed knowledge, not a journal reference.                                                                                                                                                                            |
| `explanation_code`        | closed enum in §5                                                                     | Safe machine-readable category. No upstream string interpolation.                                                                                                                                                                                         |
| `reconciliation_required` | boolean                                                                               | Evidence/state review is required by the rules below. It is informational, not an override or permission. False does **not** mean receipt eligibility, legal validity or no other business action.                                                        |

All objects have `additionalProperties: false`. No document number/type, amount/currency, customer/NIF/contact, fiscal-state copy, delivery details, balances, last-known state, source/attempt IDs, request IDs from AGT, hashes, revisions, operation UUIDs, raw journal/response, credentials, signatures, signing information, endpoints, hostnames or exception text. Server correlation UUID is distinct from the prohibited AGT request identity.

Do not expose human messages, recommended actions or retryability in V2. They are unnecessary for this first machine contract and risk being interpreted as instructions to resubmit or issue. Integrators may localize the documented codes. Internal bounded messages remain internal. `pending` is not a polling schedule or permission to invoke any operation. HTTP 200 says the authorized resource was read; it does not mean AGT success.

**Fiscal eligibility is deliberately deferred.** Neither `receipt_eligible`, `can_issue`, `is_valid`, `authorized`, an eligibility token, nor a derived boolean is present. Even known valid/idle/recent knowledge is not the complete SA1 fiscal/actor/context/balance decision. Future consequential operations must revalidate the authoritative gate and every other check at their own locked execution boundary. This endpoint does not call or approximate that gate.

## 5. Deterministic disclosure mapping, explanations and provenance

Evaluate these rows in order. `Q` means the supported, structurally valid Phase 4B qualified facade result at the same `as_of`; retained historical facets do not automatically become current. A healthy projection may be masked to less information here; it may never be promoted.

| Priority / input                                                                                                                         | External knowledge / reported state                                     | Provenance / explanation / reconciliation                                                                                                                                                                                              |
| ---------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Bound mutable draft                                                                                                                      | `not_applicable` / null                                                 | `not_applicable`; `not_submitted`; false. Synchronization/freshness not_applicable, both observation/success timestamps null.                                                                                                          |
| Missing, malformed, unsupported-version/enum projection, invalid reference/context, or any future-dated exposed observation/success time | `unknown` / null                                                        | `unverified`; `state_unavailable`; true. Synchronization unknown, freshness unverified, both timestamps null. Do not repair.                                                                                                           |
| Q classification conflicting or knowledge conflicting_evidence                                                                           | `conflicting_evidence` / null                                           | `unverified`; `evidence_conflict`; true. Retain only valid synchronization and safe historical successful-sync time.                                                                                                                   |
| Q knowledge legacy_unverified, or partial classification other than never_known workflow/acknowledgement                                 | `insufficient_evidence` / null                                          | `unverified`; `legacy_unverified`; true. No imported legacy V or old fiscal fallback.                                                                                                                                                  |
| Q knowledge known, authoritative classification, and qualified facade operational status is valid/invalid/processing/cancelled           | `known`; map cancelled to `processing_cancelled`, other values directly | `agt_observation`; use the bounded presenter explanation code; false. This explicitly excludes a retained V/I while refresh is pending or synchronization failed. Recognized processing with pending sync may remain known processing. |
| Q never_known with recognized pending/sending/received workflow projection, without conflict or failed sync                              | `unknown` / null                                                        | `workflow_only`; use the bounded presenter code; false. A receipt/acknowledgement of transport is not validation of this document.                                                                                                     |
| Q has an existing pending synchronization, and none of the uncertainty/conflict rows above applies                                       | `unknown` / null                                                        | `unverified`; `refresh_pending`; false. Includes deferred result 7 or retained historical V during a new claim.                                                                                                                        |
| Other supported Q                                                                                                                        | `unknown` / null                                                        | `unverified`; bounded presenter code; true. Unknown/failed state never becomes valid, invalid or merely reassuring pending.                                                                                                            |

For all non-draft supported rows, copy the Q synchronization enum; never derive a job schedule from it. If it cannot be represented by the closed contract, use the state_unavailable row. For all unknown/insufficient/conflicting rows, `observed_at=null`, `freshness=unverified`. A supported safe historical `last_successful_sync_at <= as_of` can remain without exposing its prior reported value. If Q was rejected altogether, do not salvage dates from its invalid JSON. A workflow mismatch that the shared facade classifies legacy_unverified follows insufficient_evidence; do not bypass it with the raw workflow.

Projection-reference integrity here uses the existing bound aggregate and database constraints; the endpoint does not fetch/decrypt/reduce historical evidence to certify a projection. Application receipt eligibility remains a stronger execution-time evidence check. Raw-database administrator tampering is not converted into an API certification promise. Corrupt/unsupported representation detected by the facade or disclosure validator fails to state_unavailable, not a reassuring approximation.

Closed explanation codes are:

```text
not_submitted, delivery_pending, delivery_acknowledged,
processing_reported, validation_reported, invalidity_reported,
processing_cancelled, request_failed, refresh_pending, sync_failed,
stale_observation, unknown_response, evidence_conflict,
legacy_unverified, state_unavailable
```

Existing codes retain the shared presenter's bounded meanings; `state_unavailable` adds only an external uncertainty category when a representation cannot safely be disclosed. `validation_reported` describes a supported observation, not issuance authority. `invalidity_reported` is not a command to correct. `processing_cancelled` is not cancellation of the fiscal document. `request_failed`/`sync_failed` give no upstream reason. `evidence_conflict` requires reviewed reconciliation, never automatic conflict clearing. Unknown/unmapped internal explanation codes use the entire state_unavailable fallback, not a raw string or optimistic default.

## 6. Freshness and forward compatibility

For a known exposed state, require a non-null supported UTC observation time not later than `as_of`. `recent_observation` means `0 <= as_of - observed_at < 900 seconds`; at exactly 900 seconds and beyond it is `stale`. Preserve reported valid/invalid when merely old; do not erase proven acceptance or imply that it expired. Processing may also be stale. Other current uncertainty is represented independently and cannot be reversed by recent timestamps.

Wire timestamps use RFC 3339 UTC with second precision and `+00:00`, matching the existing qualified casts. Null is absence/undisclosed current evidence, never zero/epoch/now. `last_successful_sync_at` may remain historical while current state is unknown, failed or pending; clients must not infer its prior value or reuse an earlier valid response as authorization. `as_of` is evaluation time, not an AGT observation or freshness guarantee. No `updated_at`, projection-write time or client-supplied time replaces these clocks.

V2's enums/keys are frozen. New upstream/AGT states must normalize through the existing evidence rules to uncertainty; no raw remote enum is forwarded. A future internal reducer version or state unsupported by this mapper becomes state_unavailable. Adding a new public state/field or changing null/freshness/authority semantics needs a separately reviewed version, not an undocumented enum extension. Defensive clients treat any unknown field value or malformed combination as unknown/unverified, never success, eligibility or pending by default. No client fallback from a V2 error/unknown to V1 valid as evidence is permitted by this contract.

## 7. Scope, permission, identity and schema decision

`documents:agt-status:read` is independently grantable. Require it on **both** the parent integration ceiling and the credential, plus live current sponsor verification/MFA/active original membership/owner-or-administrator role, active unexpired/unrevoked credential/integration, immutable tuple match and the named capability permission. `documents:read` alone cannot see V2; status scope alone cannot see V1 documents, customers or catalogue. All 16 subsets of the four exact scopes must be tested for independence. No wildcard/admin/write/read-all or caller-provided scope string is accepted.

The new scope is valid in both homologation and production. Replace the existing broad `permission !== documents.read` production-only condition with explicit master-data permission checks; **do not** relax customers/catalogue production requirements. Sponsor revalidation remains a primary joined snapshot at each capability call. Existing identity/attribution, secret generation/hash/one-time disclosure, immutable grant rows, expiry/revocation, rotation subset and parent ceiling rules remain unchanged. Machine execution never becomes a sponsor browser login; cookies cannot substitute for a bearer. Forbidden impersonation/delegation/agent headers still fail 422.

One future **new additive scope-allowlist migration** is authorized, on `integration_scopes` and `integration_credential_scopes` only. Permit exactly the existing three strings plus `documents:agt-status:read`. No other columns, public identifiers, fiscal tables or indexes change. Do not edit applied historical migrations.

Follow the Phase 4A scope migration safeguards: PostgreSQL transactional replacement of CHECK constraints with the complete four-value allowlist; SQLite transactional table replacement preserving every row, FK, composite key and credential-grant immutability trigger, with `foreign_key_check`. Unsupported DB drivers refuse. No grant insertion/backfill or credential issuance occurs in migration. Existing credentials/integrations gain no scope implicitly. Down refuses if the new grant or V2 read/denial audit evidence exists, even if a credential is revoked; otherwise safely restore the three-value checks with all prior guards. Use maintenance/writers-paused deployment discipline; do not run this against an application/production database during implementation.

Extend only the approved backend scope validator and authorization map. Defaults remain `documents:read`; rotation cannot add a scope absent from the retiring credential or parent. An integration without this ceiling needs a new explicitly authorized integration through the existing approved backend lifecycle, not a grant-widening endpoint or SQL workaround. This phase adds no live provisioning, UI or management endpoint.

## 8. Authentication, errors, GET/HEAD and quotas

The shared external boundary must recognize the explicit V1 **and V2** families before routing, including disabled access, HTTPS, IP quota, one bearer header, cookie independence, client-correlation validation, forbidden delegation/body checks, generic exception rendering and no-store. Extend bootstrap rendering/method-mismatch coverage and server-owned denial route metadata as well; adding a route without that boundary is a blocker. Keep all V1 responses unchanged.

Order for GET/HEAD: global disabled guard → HTTPS/IP/authentication and existing envelope checks → authenticated shared quotas → fresh scope/sponsor capability authorization → validate selectors/query and exact context binding → one scoped document/projection read → validate closed DTO → required audit → monotonic last-used → response. The capability revalidates itself even if an adapter performed an earlier authorization check. Duplicate checks do not create new grants or double-count quotas/audits. Do not perform global target existence queries, substitute route model binding or resolve a foreign entity before authorization.

| HTTP/code                     | Exact class of outcome; all bodies are generic                                                                                                                                                                      |
| ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 200                           | Authorized bound document, including explicit unknown/partial/conflicting/not_applicable state. Not an AGT acknowledgement or fiscal authorization.                                                                 |
| 401 / AUTHENTICATION_REQUIRED | Missing, malformed, unknown, expired, revoked credential/integration; same body and `WWW-Authenticate: Bearer`, no selector-specific details.                                                                       |
| 403 / FORBIDDEN               | HTTPS failure, missing scope/current sponsor authority, or any well-formed out-of-binding workspace/entity/environment, regardless of whether that context or document exists. No document query for these denials. |
| 404 / NOT_FOUND               | Globally disabled; unknown route; or authorized correct context with nonexistent, foreign-tenant/entity/environment or unresolved document. Same target-not-found result for all such documents.                    |
| 405 / METHOD_NOT_ALLOWED      | Unsupported method on the known path, with `Allow: GET, HEAD`; routing metadata only, no document existence check.                                                                                                  |
| 422 / VALIDATION_FAILED       | Malformed ULID/environment, any query input, invalid client correlation, forbidden delegation or nonempty body/form/files; independent of target existence. No detailed validation values.                          |
| 429 / RATE_LIMITED            | Any shared quota exhausted; `Retry-After` integer seconds until the fixed window resets (1–60).                                                                                                                     |
| 500 / INTERNAL_ERROR          | Required authenticated denial audit could not persist; no otherwise-forbidden disclosure.                                                                                                                           |
| 503 / SERVICE_UNAVAILABLE     | Primary/cache/dependency failure, successful-read audit failure or unexpected internal failure, sanitized under the existing boundary. No raw exception/log details.                                                |

When multiple faults coexist, use the order above and preserve earlier global protocol checks. Indistinguishability is within these authorization classes: we deliberately retain signed 403 context/authority versus 404 scoped-target semantics, rather than silently changing all denials to 404. A missing-scope or lost-sponsor caller gets the same 403 for every well-formed target and cannot distinguish absent from foreign/existing. No claim of perfectly constant network latency is made.

Error envelope exactly matches the existing external generic form: `{"error":{"code":"…","message":"Não foi possível concluir a consulta."},"meta":{"request_id":"<server UUID>"}}`. No path/target echo or state data. Every response has `X-Request-ID` and `Cache-Control: no-store, private`. Successful/error GET bodies use application/json. HEAD performs the same full validation, revalidation, read, serialization validation, audit and quota work and returns the same status/headers but zero body bytes, including errors. No state-specific headers, privileged HEAD probe, 304, ETag, Last-Modified, cache hit or conditional authorization bypass. Standard content-length behavior may reflect the equivalently authorized GET; it must never probe an unauthorized target.

Reuse the existing process-safe database quota identities: pre-auth IP **120/minute**, integration **60/minute**, workspace **300/minute**, shared across V1/V2, all resources/scopes, GET/HEAD and overlapping rotation credentials. V2 is not a new bucket. All applicable buckets are consumed according to the existing boundary; client correlation/context strings cannot reset identity. Fixed-window boundary bursts remain the signed behavior, not a new sliding-window promise. Cache failures deny safely. No new public rate headers beyond Retry-After are required.

## 9. Required audit and last-use behavior

Success event: `documents.agt-status.read`; denial event for this server-owned route: `documents.agt-status.read.denied`. `capability_version=2`; operation/permission is `documents.agt-status.read`. Extend the named route allowlist/method-mismatch mapping, not a caller-controlled capability dispatcher. Other V1 events/version numbers stay unchanged.

Success records existing trusted integration/credential public/internal attribution, real/effective machine identity, sponsor authority user, immutable workspace/entity/environment, server request UUID, distinct operation UUID, optional separately validated client request ID, outcome, capability version, method GET/HEAD and the scoped document public ID. Human internal invocation uses existing human/support attribution. No state snapshot or raw payload is needed in the audit. No acting-as sponsor, fabricated approval/delegation or agent identity.

Authenticated denials record the trusted identity/binding, safe error code, operation/version/method and correlation; exclude denied target/context selectors, query strings, bearer/authorization/cookies, upstream text, raw state or payload/hash. Pre-auth failures retain existing sanitized security telemetry with no secret-derived details. Required audit failure denies; do not buffer/defer successful reads. Record successful use only after complete DTO validation and durable success audit. HEAD counts as successful use; denials and serializer/audit failures do not. Monotonic last-used update/races and global secret redaction remain unchanged.

## 10. V1 disposition and documentation corrections

V1 remains available only under its existing scope and global gate, with exactly its existing ten document fields, enum values, keys/types, legacy fallback/provenance, filters, GET/HEAD and errors. No automatic redirect or fallback between versions. Do not add qualified fields to V1 or reinterpret `agt_status=valid`. V2 can return insufficient_evidence/unknown for the very same source whose V1 summary is valid; this is deliberate and must be demonstrated in tests and migration documentation.

**Disposition:** coexist with explicit deprecation of V1 `agt_status`/`agt_status_source` as workflow-only, never AGT-qualified evidence. No removal/sunset date is invented. Any future retirement requires a separate reviewed compatibility decision. Before any external enablement, publish the V1-versus-V2 distinction and migration guide, verify client risk/usage, provide explicit opt-in status grants through the authorized lifecycle, complete V2 implementation/Astra review and all operational release gates. Neither design nor implementation approval satisfies enablement. This supplies SA1's proposed versioned disposition; it does not waive its release hold.

There is residual misleading operation/filter prose in both current V1 OpenAPI files (“CurrentAgtState projection” and “current AGT provenance”), despite correctly deprecated component fields. The bounded implementation must correct **descriptions/deprecation documentation only** to legacy-summary language and link the new V2 contract. The existing V1 wire schemas, paths, security and behavior must compare unchanged when description/deprecation annotations are excluded. Update the bearer documentation to describe operation-specific grants without implying that the new scope unlocks V1. Do not edit historical signed reports to erase their earlier claims.

## 11. OpenAPI contract

The complete normative OpenAPI 3.1 design is embedded below. It is not a deployed spec or route. During implementation, publish it as `docs/openapi-external-qualified-agt-v2.json`, incorporating the exact semantic/precedence constraints in this report and testing runtime parity. Bearer security is an HTTP scheme, not OAuth; its security requirement array remains empty and `x-required-scopes` documents the server-side grant. Required scope is independently enforced on both credential and parent.

All parameters, enums, nullability, closed objects, error/status headers and bodyless HEAD responses are normative. No session mirror or list operation is implied. The status/code association in §8 is normative even where reusable error schemas enumerate all safe codes. Unknown internal values must pass through the safe fallback before serialization; arbitrary JSON cannot become valid merely because HTTP succeeded.

```json
{
    "openapi": "3.1.0",
    "info": {
        "title": "Facturac external qualified AGT read — Phase 4C design",
        "version": "2.0.0",
        "description": "Design-only contract; external access remains globally disabled. Does not authorize production rollout, fiscal actions or AGT network operations."
    },
    "x-global-access-enabled": false,
    "x-rate-limits": {
        "window": "fixed native-clock minute; boundary bursts permitted",
        "ip": 120,
        "integration": 60,
        "workspace": 300,
        "shared-across": "V1/V2, resources, scopes, GET/HEAD and overlapping rotation credentials"
    },
    "paths": {
        "/api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status": {
            "parameters": [
                {
                    "name": "workspacePublicId",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "pattern": "^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"
                    }
                },
                {
                    "name": "entityPublicId",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "pattern": "^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"
                    }
                },
                {
                    "name": "environment",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "enum": ["homologation", "production"]
                    }
                },
                {
                    "name": "documentPublicId",
                    "in": "path",
                    "required": true,
                    "schema": {
                        "type": "string",
                        "pattern": "^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$"
                    }
                },
                {
                    "name": "X-Client-Request-ID",
                    "in": "header",
                    "required": false,
                    "schema": {
                        "type": "string",
                        "pattern": "^[A-Za-z0-9._-]{1,64}$"
                    },
                    "description": "Optional diagnostic identifier only; it never replaces the fresh server UUID or a quota identity."
                }
            ],
            "get": {
                "operationId": "externalReadQualifiedAgtStatusV2",
                "summary": "Read one qualified AGT observation summary",
                "description": "Dedicated V2 read-only resource. V1 remains a deprecated legacy workflow summary. All query parameters are rejected. A 200, known valid or reconciliation_required=false is never fiscal eligibility or authorization. Both bound environments are readable; unresolved is not. Never polls, refreshes or rebuilds. The Phase 4C contract defines mandatory mapping and error precedence.",
                "security": [
                    {
                        "integrationBearer": []
                    }
                ],
                "x-required-scopes": ["documents:agt-status:read"],
                "x-required-capability": "documents.agt-status.read",
                "x-reject-unknown-query-parameters": true,
                "x-allowed-empty-body-encodings": ["", "{}", "[]"],
                "responses": {
                    "200": {
                        "description": "Closed qualified summary, including uncertainty. No fiscal authority is granted.",
                        "headers": {
                            "X-Request-ID": {
                                "$ref": "#/components/headers/RequestId"
                            },
                            "Cache-Control": {
                                "$ref": "#/components/headers/NoStore"
                            }
                        },
                        "content": {
                            "application/json": {
                                "schema": {
                                    "$ref": "#/components/schemas/QualifiedAgtStatusResponse"
                                },
                                "example": {
                                    "data": {
                                        "document_public_id": "01arz3ndektsv4rrffq69g5fax",
                                        "knowledge": "known",
                                        "reported_state": "valid",
                                        "synchronization": "idle",
                                        "freshness": "stale",
                                        "observed_at": "2026-10-07T10:00:00+00:00",
                                        "last_successful_sync_at": "2026-10-07T10:00:00+00:00",
                                        "as_of": "2026-10-07T11:00:00+00:00",
                                        "effective_at": null,
                                        "provenance": "agt_observation",
                                        "explanation_code": "stale_observation",
                                        "reconciliation_required": false
                                    },
                                    "meta": {
                                        "request_id": "11111111-1111-4111-8111-111111111111",
                                        "workspace_public_id": "01arz3ndektsv4rrffq69g5fav",
                                        "legal_entity_public_id": "01arz3ndektsv4rrffq69g5faw",
                                        "environment": "homologation"
                                    }
                                }
                            }
                        }
                    },
                    "401": {
                        "$ref": "#/components/responses/Error401"
                    },
                    "403": {
                        "$ref": "#/components/responses/Error403"
                    },
                    "404": {
                        "$ref": "#/components/responses/Error404"
                    },
                    "405": {
                        "$ref": "#/components/responses/Error405"
                    },
                    "422": {
                        "$ref": "#/components/responses/Error422"
                    },
                    "429": {
                        "$ref": "#/components/responses/Error429"
                    },
                    "500": {
                        "$ref": "#/components/responses/Error500"
                    },
                    "503": {
                        "$ref": "#/components/responses/Error503"
                    }
                }
            },
            "head": {
                "operationId": "externalHeadQualifiedAgtStatusV2",
                "summary": "Read the same resource with no response body",
                "description": "Same complete validation, authority, scoped read, representation validation, quotas, audit and last-use behavior as GET. All responses are bodyless, including errors. No cheap existence probe, state headers or conditional 304.",
                "security": [
                    {
                        "integrationBearer": []
                    }
                ],
                "x-required-scopes": ["documents:agt-status:read"],
                "x-required-capability": "documents.agt-status.read",
                "x-reject-unknown-query-parameters": true,
                "x-allowed-empty-body-encodings": ["", "{}", "[]"],
                "responses": {
                    "200": {
                        "description": "Identical authorization and status to GET; zero body bytes.",
                        "headers": {
                            "X-Request-ID": {
                                "$ref": "#/components/headers/RequestId"
                            },
                            "Cache-Control": {
                                "$ref": "#/components/headers/NoStore"
                            }
                        }
                    },
                    "401": {
                        "$ref": "#/components/responses/HeadError401"
                    },
                    "403": {
                        "$ref": "#/components/responses/HeadError403"
                    },
                    "404": {
                        "$ref": "#/components/responses/HeadError404"
                    },
                    "405": {
                        "$ref": "#/components/responses/HeadError405"
                    },
                    "422": {
                        "$ref": "#/components/responses/HeadError422"
                    },
                    "429": {
                        "$ref": "#/components/responses/HeadError429"
                    },
                    "500": {
                        "$ref": "#/components/responses/HeadError500"
                    },
                    "503": {
                        "$ref": "#/components/responses/HeadError503"
                    }
                }
            }
        }
    },
    "components": {
        "securitySchemes": {
            "integrationBearer": {
                "type": "http",
                "scheme": "bearer",
                "bearerFormat": "fcr1.<ULID>.<256-bit-base64url-secret>",
                "description": "Exact documents:agt-status:read scope on both credential and integration ceiling, immutable context and fresh sponsor/capability authority. No scope implies another. Cookies and caller scope strings grant nothing."
            }
        },
        "headers": {
            "RequestId": {
                "description": "Fresh server correlation UUID, unrelated to any AGT request identity.",
                "schema": {
                    "type": "string",
                    "format": "uuid"
                }
            },
            "NoStore": {
                "schema": {
                    "type": "string",
                    "const": "no-store, private"
                }
            },
            "BearerChallenge": {
                "schema": {
                    "type": "string",
                    "const": "Bearer"
                }
            },
            "AllowRead": {
                "schema": {
                    "type": "string",
                    "const": "GET, HEAD"
                }
            },
            "RetryAfter": {
                "description": "Seconds until the shared fixed minute window resets.",
                "schema": {
                    "type": "integer",
                    "minimum": 1,
                    "maximum": 60
                }
            }
        },
        "responses": {
            "Error401": {
                "description": "Indistinguishable missing, malformed, invalid, expired or revoked credential/integration.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "WWW-Authenticate": {
                        "$ref": "#/components/headers/BearerChallenge"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "AUTHENTICATION_REQUIRED"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError401": {
                "description": "Indistinguishable missing, malformed, invalid, expired or revoked credential/integration. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "WWW-Authenticate": {
                        "$ref": "#/components/headers/BearerChallenge"
                    }
                }
            },
            "Error403": {
                "description": "HTTPS, current scope/sponsor authority or well-formed immutable context mismatch; no target lookup.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "FORBIDDEN"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError403": {
                "description": "HTTPS, current scope/sponsor authority or well-formed immutable context mismatch; no target lookup. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                }
            },
            "Error404": {
                "description": "Disabled globally, unknown route or absent/out-of-context/unresolved target after authorization.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "NOT_FOUND"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError404": {
                "description": "Disabled globally, unknown route or absent/out-of-context/unresolved target after authorization. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                }
            },
            "Error405": {
                "description": "Unsupported method on known path; no target lookup.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "Allow": {
                        "$ref": "#/components/headers/AllowRead"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "METHOD_NOT_ALLOWED"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError405": {
                "description": "Unsupported method on known path; no target lookup. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "Allow": {
                        "$ref": "#/components/headers/AllowRead"
                    }
                }
            },
            "Error422": {
                "description": "Invalid protocol input, selector, query, nonempty body/form/file or forbidden delegation.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "VALIDATION_FAILED"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError422": {
                "description": "Invalid protocol input, selector, query, nonempty body/form/file or forbidden delegation. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                }
            },
            "Error429": {
                "description": "Shared IP/integration/workspace fixed-window quota exceeded.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "Retry-After": {
                        "$ref": "#/components/headers/RetryAfter"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "RATE_LIMITED"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError429": {
                "description": "Shared IP/integration/workspace fixed-window quota exceeded. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    },
                    "Retry-After": {
                        "$ref": "#/components/headers/RetryAfter"
                    }
                }
            },
            "Error500": {
                "description": "Required authenticated denial audit failed.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "INTERNAL_ERROR"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError500": {
                "description": "Required authenticated denial audit failed. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                }
            },
            "Error503": {
                "description": "Dependency, successful-read audit or unexpected internal failure; no details.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                },
                "content": {
                    "application/json": {
                        "schema": {
                            "allOf": [
                                {
                                    "$ref": "#/components/schemas/ReadError"
                                },
                                {
                                    "properties": {
                                        "error": {
                                            "properties": {
                                                "code": {
                                                    "const": "SERVICE_UNAVAILABLE"
                                                }
                                            }
                                        }
                                    }
                                }
                            ]
                        }
                    }
                }
            },
            "HeadError503": {
                "description": "Dependency, successful-read audit or unexpected internal failure; no details. HEAD has zero body bytes.",
                "headers": {
                    "X-Request-ID": {
                        "$ref": "#/components/headers/RequestId"
                    },
                    "Cache-Control": {
                        "$ref": "#/components/headers/NoStore"
                    }
                }
            }
        },
        "schemas": {
            "PublicId": {
                "type": "string",
                "pattern": "^[0-7][0-9a-hjkmnp-tv-z]{25}$"
            },
            "NullableObservationTime": {
                "type": ["string", "null"],
                "format": "date-time",
                "pattern": "^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$"
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
                        "enum": ["homologation", "production"]
                    }
                }
            },
            "QualifiedAgtStatus": {
                "type": "object",
                "additionalProperties": false,
                "required": [
                    "document_public_id",
                    "knowledge",
                    "reported_state",
                    "synchronization",
                    "freshness",
                    "observed_at",
                    "last_successful_sync_at",
                    "as_of",
                    "effective_at",
                    "provenance",
                    "explanation_code",
                    "reconciliation_required"
                ],
                "properties": {
                    "document_public_id": {
                        "$ref": "#/components/schemas/PublicId"
                    },
                    "knowledge": {
                        "type": "string",
                        "enum": [
                            "not_applicable",
                            "known",
                            "unknown",
                            "insufficient_evidence",
                            "conflicting_evidence"
                        ]
                    },
                    "reported_state": {
                        "type": ["string", "null"],
                        "enum": [
                            "valid",
                            "invalid",
                            "processing",
                            "processing_cancelled",
                            null
                        ]
                    },
                    "synchronization": {
                        "type": "string",
                        "enum": [
                            "not_applicable",
                            "idle",
                            "pending",
                            "failed",
                            "unknown"
                        ]
                    },
                    "freshness": {
                        "type": "string",
                        "enum": [
                            "not_applicable",
                            "recent_observation",
                            "stale",
                            "unverified"
                        ]
                    },
                    "observed_at": {
                        "$ref": "#/components/schemas/NullableObservationTime"
                    },
                    "last_successful_sync_at": {
                        "$ref": "#/components/schemas/NullableObservationTime"
                    },
                    "as_of": {
                        "type": "string",
                        "format": "date-time",
                        "pattern": "^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}\\+00:00$"
                    },
                    "effective_at": {
                        "type": "null"
                    },
                    "provenance": {
                        "type": "string",
                        "enum": [
                            "not_applicable",
                            "agt_observation",
                            "workflow_only",
                            "unverified"
                        ]
                    },
                    "explanation_code": {
                        "type": "string",
                        "enum": [
                            "not_submitted",
                            "delivery_pending",
                            "delivery_acknowledged",
                            "processing_reported",
                            "validation_reported",
                            "invalidity_reported",
                            "processing_cancelled",
                            "request_failed",
                            "refresh_pending",
                            "sync_failed",
                            "stale_observation",
                            "unknown_response",
                            "evidence_conflict",
                            "legacy_unverified",
                            "state_unavailable"
                        ]
                    },
                    "reconciliation_required": {
                        "type": "boolean"
                    }
                },
                "description": "Read-only observation summary, never fiscal or receipt authorization. Sections 3–6 of the Phase 4C contract define mapping, temporal and cross-field invariants. The API version is independent of internal reducer version.",
                "allOf": [
                    {
                        "if": {
                            "properties": {
                                "knowledge": {
                                    "const": "known"
                                }
                            },
                            "required": ["knowledge"]
                        },
                        "then": {
                            "properties": {
                                "reported_state": {
                                    "type": "string"
                                },
                                "observed_at": {
                                    "type": "string"
                                },
                                "freshness": {
                                    "type": "string",
                                    "enum": ["recent_observation", "stale"]
                                },
                                "provenance": {
                                    "const": "agt_observation"
                                },
                                "synchronization": {
                                    "type": "string",
                                    "enum": ["idle", "pending"]
                                },
                                "reconciliation_required": {
                                    "const": false
                                }
                            }
                        },
                        "else": {
                            "properties": {
                                "reported_state": {
                                    "type": "null"
                                },
                                "observed_at": {
                                    "type": "null"
                                },
                                "freshness": {
                                    "type": "string",
                                    "enum": ["unverified", "not_applicable"]
                                }
                            }
                        }
                    },
                    {
                        "if": {
                            "properties": {
                                "knowledge": {
                                    "const": "not_applicable"
                                }
                            },
                            "required": ["knowledge"]
                        },
                        "then": {
                            "properties": {
                                "synchronization": {
                                    "const": "not_applicable"
                                },
                                "freshness": {
                                    "const": "not_applicable"
                                },
                                "last_successful_sync_at": {
                                    "type": "null"
                                },
                                "provenance": {
                                    "const": "not_applicable"
                                },
                                "explanation_code": {
                                    "const": "not_submitted"
                                },
                                "reconciliation_required": {
                                    "const": false
                                }
                            }
                        }
                    },
                    {
                        "if": {
                            "properties": {
                                "knowledge": {
                                    "const": "insufficient_evidence"
                                }
                            },
                            "required": ["knowledge"]
                        },
                        "then": {
                            "properties": {
                                "provenance": {
                                    "const": "unverified"
                                },
                                "explanation_code": {
                                    "const": "legacy_unverified"
                                },
                                "reconciliation_required": {
                                    "const": true
                                }
                            }
                        }
                    },
                    {
                        "if": {
                            "properties": {
                                "knowledge": {
                                    "const": "conflicting_evidence"
                                }
                            },
                            "required": ["knowledge"]
                        },
                        "then": {
                            "properties": {
                                "provenance": {
                                    "const": "unverified"
                                },
                                "explanation_code": {
                                    "const": "evidence_conflict"
                                },
                                "reconciliation_required": {
                                    "const": true
                                }
                            }
                        }
                    },
                    {
                        "if": {
                            "properties": {
                                "explanation_code": {
                                    "const": "state_unavailable"
                                }
                            },
                            "required": ["explanation_code"]
                        },
                        "then": {
                            "properties": {
                                "knowledge": {
                                    "const": "unknown"
                                },
                                "synchronization": {
                                    "const": "unknown"
                                },
                                "freshness": {
                                    "const": "unverified"
                                },
                                "last_successful_sync_at": {
                                    "type": "null"
                                },
                                "provenance": {
                                    "const": "unverified"
                                },
                                "reconciliation_required": {
                                    "const": true
                                }
                            }
                        }
                    },
                    {
                        "if": {
                            "properties": {
                                "reported_state": {
                                    "enum": [
                                        "valid",
                                        "invalid",
                                        "processing_cancelled"
                                    ]
                                }
                            },
                            "required": ["reported_state"]
                        },
                        "then": {
                            "properties": {
                                "synchronization": {
                                    "const": "idle"
                                }
                            }
                        }
                    }
                ]
            },
            "QualifiedAgtStatusResponse": {
                "type": "object",
                "additionalProperties": false,
                "required": ["data", "meta"],
                "properties": {
                    "data": {
                        "$ref": "#/components/schemas/QualifiedAgtStatus"
                    },
                    "meta": {
                        "$ref": "#/components/schemas/Meta"
                    }
                }
            },
            "ReadError": {
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
                                "enum": [
                                    "AUTHENTICATION_REQUIRED",
                                    "FORBIDDEN",
                                    "NOT_FOUND",
                                    "METHOD_NOT_ALLOWED",
                                    "VALIDATION_FAILED",
                                    "RATE_LIMITED",
                                    "INTERNAL_ERROR",
                                    "SERVICE_UNAVAILABLE"
                                ]
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
    }
}
```

## 12. Acceptance and adversarial criteria

The following are mandatory implementation/review gates, not tests claimed to exist today:

1. **Version separation:** only the one V2 GET/HEAD path is added; unsupported methods/unknown paths sanitize correctly. V1 raw-V fixture remains byte/semantic compatible while V2 reports insufficient evidence and existing receipt action denies. Existing customer/catalogue/session routes and envelopes stay unchanged. No V1 media/header alias or V2 route outside the bearer boundary.
2. **Minimization:** assert exact envelope/meta/data keys and every enum/null type. Poison model attributes, internal DTO additions, error strings, AGT payloads/messages, credentials, markup and exceptions; none may enter response/header/audit. No model-wide serialization. No public internal IDs, hashes, fiscal eligibility, last-known state or undocumented fields. Response from a malformed/unsupported/future projection is closed state_unavailable.
3. **State matrix:** draft, no submission, bare V, partial import, invalid cache, authoritative V/I, recognized processing/cancellation, transport-only acknowledgement, result 7, failed synchronization, V then pending refresh, unknown after V, sticky conflict and old valid observation. Exact priority mapping, especially known processing/pending versus masked historical V/pending. HTTP 200 unknown never means validation.
4. **Time:** null dates, exact 899/900-second boundary, old supported V unchanged as stale, future observed/success time, UTC wire precision, one as-of per snapshot. Rebuild/read cannot refresh observation time. Last-success history may remain while current observed_at is null; client-supplied time is rejected.
5. **Forward safety:** unsupported reducer/internal status/explanation, added model field and malformed combination degrade to state_unavailable without raw value leakage. Older-client unknown-enum guidance and public enum freeze are explicit. No fallback to V1 as evidence.
6. **Authority:** all 16 exact scope subsets; parent-only/credential-only grant fails; status-only cannot read V1 docs/master data. Owner/admin sponsor demotion, membership removal/replacement, MFA/verification loss, credential/integration revocation/expiry and grant reduction deny at the next authorization check. Direct shared capability invocation must revalidate. Browser current-workspace changes/cookies/impersonation/agent headers cannot alter machine identity.
7. **Context/enumeration:** workspace/entity/environment mismatch 403 before document SQL, independently of existence. Correct bound context plus other-context/nonexistent/unresolved document gives indistinguishable 404. Malformed selectors 422 independent of existence after authorization. Both real environments permitted for status; master homologation remains forbidden. Foreign submission cannot contaminate a document snapshot.
8. **GET/HEAD/error parity:** identical authorization, quota consumption, lookup, audit, last-use and status/header behavior; HEAD body always empty. Empty permitted bodies versus nonempty/form/files; all query keys rejected; conditional headers do not bypass work or produce 304. Disabled gate uniformly 404 for V1/V2 and all principal/target states.
9. **Audit:** exact machine/human attribution, correct version/operation/method, separate correlation/client IDs, no denied target echo, no evidence/state/secret leakage. Inject silent logging refusal, exception, buffering and serializer failure; no success/data/last-use advance without required audit. Preserve all previous audit-failure tests.
10. **PostgreSQL snapshot interleavings:** independent worker processes/barriers for read versus poll completion, conflict, refresh claim, rebuild and draft issuance. Response must be a coherent before/after snapshot, never pre-change document plus post-change projection or known V from committed uncertainty. A later commit may make a previously read response obsolete; no read locks/writes/fiscal actions are introduced. Inspect primary query and bounded query count; no evidence-body queries or live gateway calls.
11. **PostgreSQL authority interleavings:** revocation/scope/sponsor change before capability revalidation denies without data; an already authorized read may complete under the signed rule. Reused captured context must deny on the next call. Rotation does not reset identity or add grants. Concurrent HEAD/GET last-used updates are monotonic.
12. **Shared quotas:** independent workers alternate V1 documents/customers/catalogue and V2 status across GET/HEAD and rotation credentials; same integration/workspace limits. Test actual fixed windows, including legitimate boundary bursts. The inherited test's Carbon-only freeze does not freeze native `time()`; new tests must measure/control that boundary instead of misclassifying a permitted burst or weakening assertions.
13. **Scope migration:** populated SQLite/PostgreSQL exact row/guard preservation; all four allowed and wildcard/write/unknown rejected by raw SQL; invalid/FK grant rejected; up grants nothing; interrupted migration rollback/resume; down succeeds only without new grants/audit and refuses with either. No historical scope/projection migration edited or application DB migrated.
14. **OpenAPI:** parse/resolve spec, exact operations/security/scopes/parameters/enums/nullable values, disallowed additional fields, GET/HEAD response differences, status/code/header association and V1 documentation-only diff. Every semantic matrix case conforms to both JSON Schema and normative cross-field constraints; shape validation alone is insufficient.
15. **No side effects:** fake/prevent network, queue and notification dispatch; hash fiscal/projection/attempt/journal records before/after reads. Only approved quota/audit/last-used metadata changes. Scope migration has no grants or AGT work. External configuration remains disabled. No production AGT connection/credential changes.

Run the complete SQLite suite; mandatory disposable PostgreSQL fiscal/integration/concurrency group and the entire prior session/external API/identity/lifecycle/master-data/AGT/receipt selection plus new V2 tests; PHPStan file/message/identifier baseline comparison with zero new diagnostics; types; Pint; changed-file lint/format/syntax/whitespace. Add the new feature/concurrency files to CI's existing gates. Never remove/weaken tests or reinterpret SA1 to pass. Report every skip/failure/flake and test-clock issue. The Phase 4B reference is SQLite 1,193 passed/7,195 assertions (52 PG skips), PG concurrency 52/627, PG API 429/3,160 and PHPStan 21 inherited diagnostic instances; counts alone are not approval evidence.

## 13. Blockers, operational gates and design validation

No unresolved architectural blocker remains for this bounded implementation. New decisions are only V2 disclosure/version/scope and the additive allowlist migration needed to enforce that scope. Both are expressly decided here; all prior identity, receipt, evidence and production boundaries remain intact. If implementation finds a contradiction rather than a routine implementation choice, stop for Astra with the exact conflicting clauses. Do not invent a new reducer or lifecycle/grant-widening flow.

Production backfill rehearsal, uncertainty inventory, backup/restore, frozen-hash preservation, migration downtime/locks/WAL, single-version rollout, hosted primary/database/quota/audit/proxy/redaction validation and genuine AGT homologation remain separate gates. Status-only queries must not be used to bypass those holds. Existing external access stays false; no live provisioning or production operation is authorized. The inherited PDF helper and quota-test clock issues remain disclosed by Phase 4B and are not permission to change unrelated runtime behavior.

Design validation and changed-file verification are recorded at the end of this document. This turn creates only this report, including its embedded design specification. No endpoint, resource, runtime, migration, test, existing OpenAPI file or signed governing report is changed.

## 14. Exact bounded Sol Medium implementation handoff

> Begin Phase 4C using Sol Medium. Read in full the governing Phase 1–4B contracts, amendments, implementation reports and reviews, especially SA1 and the approved Phase 4B trust-boundary review, then `docs/phase-4c-qualified-agt-read-contract.md` including its complete embedded OpenAPI design. Preserve the existing working tree. Execute only this handoff. This is the dedicated external qualified AGT read implementation, not production enablement or the next roadmap phase.
>
> Implement exactly one external GET/HEAD detail resource at `/api/integrations/v2/workspaces/{workspacePublicId}/legal-entities/{entityPublicId}/environments/{environment}/documents/{documentPublicId}/agt-status`, with route name `integrations.v2.documents.agt-status.show`. No list/batch/search/filter/session route or general V2 document API. Reuse the existing DocumentCapabilities/DocumentReadContext architecture, explicit typed document command, primary scoped document/projection query, CurrentAgtState facade and bounded presentation vocabulary. Produce a coherent single-statement snapshot and the exact 12-field minimized representation, metadata, priority mapping, timestamps/freshness, provenance and safe fallback specified here. Do not expose eligibility, human messages/actions/retryability, raw evidence, internal IDs or historical state. No read may replay/decrypt evidence, rebuild, poll, refresh, contact AGT or mutate fiscal/projection records.
>
> Add only independent `documents:agt-status:read` mapped to `documents.agt-status.read`, requiring both credential and parent grant plus live sponsor/capability revalidation and immutable workspace/entity/environment binding. Extend the existing internal document-view read mapping without new human roles, UI or session routes. Permit status reads in both approved environments; retain production-only customers/catalogue. No default grants, wildcard authority, grant widening, new management endpoints, live provisioning or credential architecture changes. Existing rotation/subset/expiry/revocation/one-time-secret rules remain unchanged.
>
> Add one new scope-allowlist migration only, preserving PostgreSQL constraints and SQLite rows/FKs/composite keys/immutability triggers under the exact safeguards in §7. No grant backfill, fiscal columns/indexes or edits to historical migrations. Refuse populated downgrade when the new grant or V2 audit history exists. Test only on guarded disposable databases; no application/production migration.
>
> Extend the existing external boundary, bootstrap exception/routing coverage and explicit denial-audit route/version metadata to cover V2 before any lookup, including disabled access, HTTPS, single bearer, shared quotas, sponsor/scope checks, forbidden body/delegation input, generic errors and no-store. Preserve every V1 behavior. Implement GET/HEAD parity, 401/403/404/405/422/429/500/503 semantics, required audit before success/last-use, correct integration attribution/correlation and secret redaction. V2 must share existing integration/workspace/IP buckets across versions/resources/credentials and never become an existence oracle or cache bypass.
>
> Publish `docs/openapi-external-qualified-agt-v2.json` from the embedded OpenAPI design and all its normative semantic rules. Correct only residual legacy-status descriptions/deprecation annotations in both V1 specs and document the versioned opt-in disposition; preserve existing V1 paths/wire schemas/filters/errors/scopes and raw-summary values. Do not inject the internal qualified DTO into V1 or replace its status field. Existing credentials acquire no new scope/data automatically.
>
> Add every state/disclosure/authority/enumeration/error/audit/time/unknown-enum/migration acceptance case in §12 and independent-process PostgreSQL snapshot/authority/quota races. Preserve SA1 receipt, evidence, recovery, reconstruction and V1 regressions. Include tests in CI. Run full SQLite, all prior mandatory PG concurrency and API/AGT gates plus new tests, PHPStan diagnostic comparison with zero new, types, Pint and changed-file lint/format/syntax/diff checks. Disclose inherited warnings/flakes; do not weaken/delete tests or pretend Carbon freezes native quota time. No live AGT call or production enablement.
>
> Produce `docs/phase-4c-implementation-report.md` with exact capabilities/routes/schema/scope/migration, authorization/snapshot/time/audit behavior, changed files, tests and complete validation results, V1 preservation, deviations/blockers and deliberately deferred functionality. If a signed architectural decision must change, stop and identify the precise Astra decision; Sol must not redesign it. Conclude **PHASE 4C IMPLEMENTATION — READY FOR ASTRA REVIEW** or **PHASE 4C IMPLEMENTATION — NOT READY**. Do not implement fiscal/mutation APIs, receipts/corrections, reconciliation writes, analytics APIs, webhooks, agents/BYOW/autonomy, credential UI, production AGT/configuration or external enablement. Stop for Astra Medium review.

## Design validation record

The embedded OpenAPI JSON parses, all local references resolve, the path contains only GET/HEAD, the data schema has exactly 12 required properties, every HEAD response lacks content, and the example keys match the closed schema. These are documentation integrity checks, not a claim that a V2 runtime or a complete OpenAPI conformance validator was executed. The semantic priority table was checked against the current qualified facade and presenter, including retained V during pending work, partial/unknown history, sticky conflict, future time and stale proven acceptance.

Ran the unchanged SQLite baseline selection with a writable temporary OpenSSL RANDFILE: `AgtObservationProjectionTest.php`, `AgtProjectionReviewTest.php`, `ReadFirstDocumentApiTest.php` and `ExternalIntegrationReviewTest.php`: **122 passed / 747 assertions**, **5.977 seconds**, no failures/skips/warnings. This verifies preserved underlying behavior; no new V2 implementation or tests exist yet.

Prettier and `git diff --check` passed. Repository content hashes confirm that only `docs/phase-4c-qualified-agt-read-contract.md` changed from the design-start snapshot; all runtime, migrations, tests, existing specifications and governing reports were preserved. npm emitted the inherited `user` / `unsafe-perm` configuration warnings. No full SQLite, PostgreSQL, PHPStan, type, Pint or runtime lint rerun is claimed for this documentation-only turn; the mandatory implementation gates remain in §12. No database service was started, migration applied, credential provisioned or AGT call performed. External access was not enabled.

No implementation approval is inferred from test counts. Design approval rests on the explicit bounded disclosure/authorization decisions above and remains subject to Sol implementation followed by Astra trust-boundary review.

**PHASE 4C DESIGN — APPROVED FOR SOL IMPLEMENTATION. Stop after this design and handoff. External access remains disabled.**
