# Phase 7B.1 — AI gateway façade implementation

Date: 2026-10-08. Implementation only, awaiting independent review. Authority: the approved [Phase 7B architecture](phase-7b-multi-provider-ai-gateway-contract.md), its bounded 7B.1 handoff, and the user's Phase 7B.1 request. The accepted Phase 6/7 security, P7-PC1 and remediation contracts remain unchanged.

## Scope and integration decision

Added a provider-independent application seam around the accepted Anthropic planner. **The production `AssistantPlanner` binding is unchanged.** It still resolves to `UnavailableAssistantPlanner` by default and to the existing `AnthropicIntentPlanner` only under its existing configuration. No controller, route, transport, SDK bridge, profile, disclosure, ledger or production configuration was migrated.

The architecture's legacy delegation is available through a separately bound `VapAiGateway`; `GatewayAssistantPlanner` adapts that interface to the existing planner port. Only tests explicitly substitute it into the HTTP pipeline. This honors the user's explicit preservation instruction and leaves production migration for 7B.2. Container resolution is not transmission authorization: the existing planner performs all original context, approval, question, secret, reservation, permit and transport checks.

Laravel AI SDK still supplies protocol integration solely through the unchanged accepted private `AssistantIntentGateway`. No new SDK imports or independent protocol client were introduced. The façade is not another AI SDK and exposes no provider options or network utility.

## Files and contracts

| File                                                              | Change                                                                                                                                                                                            |
| ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Fiscal/VapAiGateway.php`                                     | Application interface: one typed inference request to one typed inference result.                                                                                                                 |
| `app/Fiscal/AiInferenceRequest.php`                               | Immutable wrapper around the existing explicit invocation. No question/context bag, provider/model/endpoint/key selector. Nonserializable; debug representation empty.                            |
| `app/Fiscal/AiInferenceResult.php`                                | Bounded UTF8/JSON proposal or payload-free failure; marked untrusted, never factual output. No automatic serialization or string rendering. The unchanged local plan validator remains mandatory. |
| `app/Fiscal/AiModelIdentity.php`                                  | Closed output identity for the sole accepted legacy provider/model/profile; not an input-selection API.                                                                                           |
| `app/Fiscal/AiGatewayFailure.php`                                 | Closed classifications preserving known 401/403/404/429/503 outcomes.                                                                                                                             |
| `app/Fiscal/LegacyAssistantAiGateway.php`                         | Delegates to accepted planner; returns closed result and discards exception payload/chains. No security responsibilities move into the SDK.                                                       |
| `app/Fiscal/GatewayAssistantPlanner.php`                          | Opt-in caller depending on `VapAiGateway`; maps fixed failure status and quota Retry-After to the existing application convention.                                                                |
| `app/Providers/AppServiceProvider.php`                            | Exactly two imports and one transient gateway binding; existing planner/quarantine bindings unchanged.                                                                                            |
| `tests/Feature/AiGatewayFacadeTest.php`                           | 18 focused cases, including differential real application pipeline with synthetic bounded transport.                                                                                              |
| This report and `phase-7b-1-gateway-implementation-evidence.json` | Scope, validation, hashes and independent review handoff.                                                                                                                                         |

No migrations, dependency changes, provider configuration, credentials, public routes or UI were added. Temporary test files contained only inert sentinel keys and were removed by test cleanup.

## Requirement mapping and security ownership

- **Stable seam / DI:** `VapAiGateway` has one typed operation, bound to a concrete legacy implementation. New caller depends on the interface; test substitution is explicit and no singleton carries request state.
- **No arbitrary selection:** request accepts only `AssistantProviderInvocation`. No provider/model URL/header/credential/options input exists; the sole output identity references unchanged accepted constants. Wrong configured profile continues to fail through the existing admission checks.
- **Authority:** the invocation is local context, not a self-authorizing permit. Existing primary authority, acknowledgement, original-input policy, secret resolver, immutable permission ceiling, reserve and one-use transport checks all execute inside the accepted planner. Revoked membership denies direct gateway use before reservation/send.
- **Model context:** no new outbound DTO or generic metadata bag. Existing `ProviderIntentInput` remains the only model-visible projection. The compatibility planner's inherited array parameter is not forwarded as model context; authoritative invocation input follows the accepted path.
- **Untrusted output:** result JSON validity/size does not authorize tools. `AssistantPlan::fromJson` still validates the complete plan before execution. A substituted proposal containing `issueInvoice` is rejected. The façade has no factual renderer or business engine.
- **Failures:** preserve application-known statuses as Unauthenticated, Forbidden, NotFound, QuotaExceeded and Unavailable. The legacy adapter intentionally collapses upstream timeout/protocol/refusal details to 503; this stage does not fabricate distinctions or modify it to recover those details. Quota preserves fixed Retry-After 60. No raw upstream error or previous exception is retained in the result or reconstructed exception.
- **Preservation:** current production binding remains byte-for-byte unchanged. Request/response transport, profile expiry, 552,816 micro-USD reserve, no-refund rules, SDK quarantine, original-text remediation and FPM/CLI selection are unchanged. No duplicate audit/reservation layer was added.

## Tests added

The 18 cases verify container bindings; interface-only dependency and input signatures; no SDK/network/config calls in the new seam; explicit fake substitution with local rejection of a write proposal; request/result serialization refusal and debug redaction; five fixed failure classifications; absent invocation/admission denial; original-membership revocation; and seven differential HTTP scenarios.

Differential scenarios are unsupported intent, authorized customer read, malformed provider envelope, interrupted transport, quota exhaustion, withdrawn acknowledgement and prohibited original question. The old and opt-in façade paths must produce identical actual SDK request body bytes, local facts/outcomes, ordered audit event lists, attempt states/usage and reservation deltas. Successful/ambiguous sends retain five allocations of 552,816 micro-USD; pre-admission refusals retain none. No second send or provider fallback is introduced.

The synthetic transport runs the accepted admission/permit/buffer path and checks committed attempted audit/reservation before wire execution. It overrides only the wire fixture and does not certify real provider availability or privacy. Existing provider protocol/CLI/quarantine and PostgreSQL tests supply broader preserved coverage.

## Validation actually executed

| Gate                                                    | Exit   | Actual result                                                                      |
| ------------------------------------------------------- | ------ | ---------------------------------------------------------------------------------- |
| Gateway focused                                         | 0      | 18 passed; 234 assertions                                                          |
| Complete SQLite suite                                   | 0      | 2,155 passed; 315 skipped; 1 warning; 14,237 assertions                            |
| Complete PostgreSQL concurrency group                   | 1      | 314/315 passed; 9,393 assertions; 1 warning; rollback snapshot-barrier case failed |
| Unchanged PostgreSQL snapshot cases rerun               | 0      | All 5 variants passed; 67 assertions                                               |
| PostgreSQL read/command/assistant integration selection | 1      | 1,390/1,391 passed; 10,190 assertions; inherited shared-limiter case failed        |
| Unchanged PostgreSQL quota case rerun                   | 0      | 1 passed; 64 assertions                                                            |
| PHPStan before / after                                  | 1 / 1  | Exact same 21 inherited diagnostics; zero new                                      |
| Type checking                                           | 0      | Passed                                                                             |
| Assistant UI Node tests                                 | 0      | 12 passed                                                                          |
| Pint dirty files                                        | 0      | Passed; only intended files formatted                                              |
| PHP syntax                                              | 0 each | All 9 changed PHP files passed                                                     |
| Full ESLint                                             | 1      | Same 9,719 inherited errors; zero warnings                                         |
| Resource format check                                   | 1      | Same four inherited failures                                                       |
| Changed PHP whitespace                                  | 0      | Existing-file diff check and all new-file newline/trailing-space checks passed     |

**Neither full PostgreSQL run was a clean pass.** Each failed case passed on unchanged targeted rerun; all 18 new gateway cases passed in the PostgreSQL integration selection. This establishes executed case coverage, not a clean single-run certification. Independent review must assess these timing-sensitive validation caveats.

These runs overlap and must not be summed as unique tests. Full suite counts distinguish skips and warnings. Exact commands, exit statuses, parsed summaries and log hashes are in the evidence artifact. No production database or provider endpoint was contacted. PostgreSQL gates use isolated disposable databases; no application migration was introduced.

### Inherited debt

PHPStan diagnostic objects (file, line, message, identifier and tips) are identical before/after: 21, with zero new diagnostics. Full ESLint reproduces 9,719 errors; every pre-existing frontend file is hash-identical. The four resource-format failures are unchanged: `resources/js/pages/Establishments/Index.vue`, `resources/promo/coming-soon.html`, `resources/promo/render.mjs`, `resources/promo/switch.html`. The existing ineffective `DomainException` import warning remains inherited. No debt was suppressed, weakened or repaired.

There are no changed JS/TS/Vue files, so a changed-file ESLint gate has an empty target set; full lint was nevertheless rerun. Npm's existing `user`/`unsafe-perm` configuration warnings are unrelated. This implementation does not certify repository-wide quality beyond the recorded gates.

## Implementation attempts and limitations

The first focused run had two test assertion failures: Pest interpreted an exception interface as a message expectation. Replaced those assertions with the actual `HttpException` base class. No runtime behavior was changed to make them pass; the corrected focused run passed all 18 cases.

Initial sandbox PostgreSQL readiness returned no response; a local start attempt failed because the existing server's shared-memory block was already active. An approved local-network readiness check established that the server was running; it was not restarted or altered. Gates then used two newly created disposable databases. No production service/account was affected.

The inherited PostgreSQL/assistant tests refreshed `docs/phase-6-implementation-evidence.json` as a generated test artifact. After all gates it was restored from the pre-test backup, and its SHA-256 was verified against the pre-task inventory. No historical evidence was replaced.

The full PostgreSQL concurrency run failed the existing billing rollback snapshot case: the writer did not observe the reader holding the SQL advisory barrier before the fixed deadline. The unchanged five snapshot variants then passed. The test, billing query and fixtures are hash-identical to the pre-task baseline and do not use the gateway. Timing sensitivity is observed; contention as the root cause is not proven. Neither the two-second statement limit nor assertions were relaxed.

The full PostgreSQL integration selection failed the existing shared-limiter test (expected 429, received 200). Its unchanged targeted rerun passed. This exact test family has a documented native-time/Carbon minute-window flake in [Phase 4D review](phase-4d-trust-boundary-review.md), under inherited test behavior. The test and limiter remain unchanged. The failure is consistent with that known issue, but this run did not instrument the precise rollover instant. It remains visible in the evidence rather than being relabeled a pass.

All mandatory assistant/security, focused, type/syntax/format and no-new-static-diagnostics checks passed; broader PostgreSQL checks retain the qualifications above. Historical live provider/FPM/production activation evidence was not rerun. No real provider traffic or activation rehearsal is claimed.

This stage does not provide policy-backed provider switching, timeout-specific error detail, new SDK adapters, secrets persistence, BYOK, endpoint approval, tenant UI or production routing through the façade. Those are deliberately later-stage work. Existing account/privacy/funding/geography/operational gates remain blocked or pending exactly as recorded; no live quality, retention, entitlement or billing test was performed.

## Preservation and exclusions

Compared SHA-256 for all 1,125 pre-existing files in the captured inventory. Exactly one changed: `app/Providers/AppServiceProvider.php`; subtracting its two imports and one gateway binding reproduces the pre-task file byte-for-byte. All other 1,124 inventoried files, including governing documents, existing tests, accepted Phase 6/7 implementation, frontend, dependencies and configuration, are unchanged. The evidence embeds the exact patch, baseline manifest and changed-file hashes. New scope is seven application types, one test file and the two handback artifacts.

No real credentials, provider accounts/settings, live inference, provider enablement, OpenAI runtime integration, BYOK, self-hosted URL support, migration, fiscal capability, new tool, automatic failover, Phase 7B.2 or Phase 8 work was introduced. The governing architecture was not edited. This is an implementation handback, not independent self-approval.

## Exact independent Astra review handoff

> Perform the independent Phase 7B.1 gateway façade review only. Read the approved Phase 7B contract, the user's stage-specific preservation instructions, this report/evidence and governing Phase 6/7/P7-PC1/remediation documents. Inspect the seven new application types, the exact three-line AppServiceProvider change and the new tests. Verify the production AssistantPlanner binding remains unchanged and that only explicit test substitution routes the existing application through the new seam.
>
> Challenge request typing, selection non-exposure, SDK isolation, original invocation authority, failure classification/redaction, untrusted result semantics, serialization refusal and container lifetime. Trace the façade through the real accepted planner and prove all existing context/privacy/question/secret/budget/permit/transport/final-disclosure checks remain authoritative. Review differential wire/fact/audit/reservation assertions and the exact validation/preservation evidence rather than accepting this report as proof. Assess both failed full PostgreSQL runs and their unchanged targeted reruns explicitly; do not treat reruns as erasing failures. Distinguish inherited debt from new diagnostics. No provider calls, credentials, configuration changes or activation are authorized.
>
> Return an independent verdict and concrete findings with evidence. Do not implement fixes, migrate production wiring, begin 7B.2 or proceed to Phase 8. Passing this review grants no provider activation authority.

PHASE 7B.1 COMPLETE — READY FOR INDEPENDENT REVIEW
