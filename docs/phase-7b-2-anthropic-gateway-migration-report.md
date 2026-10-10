# Phase 7B.2 — Anthropic gateway migration

Date: 2026-10-09 (Africa/Luanda); execution began 2026-10-08. Bounded implementation handback; independent review pending. Authority: the approved [Phase 7B contract](phase-7b-multi-provider-ai-gateway-contract.md), accepted Phase 7B.1 review in the conversation and the user's explicit Phase 7B.2 migration request. No activation authority is inferred.

## Result and exact scope

The enabled application planner now resolves through the accepted provider-independent gateway. Disabled configuration still resolves `UnavailableAssistantPlanner`. Existing admission independently requires both provider enablement and egress approval plus all other prerequisites; changing a binding grants none of them.

Old enabled path:

`AssistantInteraction → AssistantPlanner → AnthropicIntentPlanner → AssistantIntentGateway → AssistantProviderTransport`.

New enabled path:

`AssistantInteraction → AssistantPlanner → GatewayAssistantPlanner → VapAiGateway (LegacyAssistantAiGateway) → AnthropicIntentPlanner → AssistantIntentGateway → AssistantProviderTransport`.

**No previously accepted Phase 7A security invariant changed.** The only existing runtime change replaces the Anthropic planner import/resolution with `GatewayAssistantPlanner` in `AppServiceProvider`. No transport, admission, secret resolver, ledger, permit, SDK bridge, profile, audit, capability, controller or configuration was edited. The seven frozen Phase 7B.1 application types are unchanged.

The existing `LegacyAssistantAiGateway` already implements the required Anthropic adapter: it accepts the closed application request, delegates to the reviewed planner and returns an untrusted bounded plan or payload-free failure enum. Reusing it avoids a duplicate adapter or unnecessary responsibility extraction. Its provider-specific dependency is inside the adapter boundary. Ordinary interaction/planner code and the composition root no longer name `AnthropicIntentPlanner`; there is no new caller selecting providers.

The sole legacy selection remains the code-bound adapter and immutable `AiModelIdentity::LegacyAnthropicIntent`. The existing permit binds the invocation, canonical body, original permission subset and attempt; ledger/profile checks require the exact accepted profile and revalidate before transmission/disclosure. No mutable selection tuple or tenant selection facility is introduced. Multi-provider selection persistence and generalized approval/accounting remain later work.

## Files changed

| File                                                                   | Change and justification                                                                                                                                                                                                                                                    |
| ---------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app/Providers/AppServiceProvider.php`                                 | **Security-critical composition root:** replace one import and one enabled-branch resolution. Necessary to route actual application requests through the gateway. Disabled branch, transient gateway binding, SDK quarantine and all boot/audit code remain unchanged.      |
| `tests/Feature/AiGatewayFacadeTest.php`                                | Rename and update the enabled-binding expectation to the newly authorized gateway route. Preserve all 18 cases, disabled expectation and old-path differential comparisons. This updates an intentionally superseded routing assertion; no security assertion was weakened. |
| `tests/Feature/AnthropicGatewayMigrationTest.php`                      | 19 migration cases: production composition/dependency/default-denial check, 17 real-HTTP differential scenarios and gateway replay denial.                                                                                                                                  |
| This report and `phase-7b-2-anthropic-gateway-migration-evidence.json` | Exact validation, scope, hashes, limitations and independent-review handoff.                                                                                                                                                                                                |

No migrations, packages, keys, settings, routes, frontend changes or additional adapter classes. The working tree already contained earlier-phase changes; the report/evidence use a pre-task file inventory rather than attributing all Git changes to this phase.

## Policy, SDK and network ownership

Facturac retains original-question validation, explicit tenant/entity/production context, original-membership freshness, permission ceiling, disclosure/acknowledgement, fixed profile approval, secret access, budgets, durable reservations, one-use permits, audit, destination restrictions and final disclosure. These execute in the existing accepted services. The façade neither authorizes based on its DTO nor delegates authorization to the SDK.

Laravel AI SDK v0.11.2 remains confined to the existing private `AssistantIntentGateway` bridge, supplying the single-step Anthropic request/schema protocol mechanics. Its isolated request handler reaches `AssistantProviderTransport::exchange`; the unchanged production `wire()` performs the final cURL network request, with the existing trusted CLI probe/DNS resolver, validated pinned address, fixed destination, TLS, deadline, byte, redirect/proxy and prerequisite checks. The SDK's generic HTTP client does not replace that transport. Global `AiManager` remains denied and no SDK provider object or exception is returned from the application gateway.

`ProviderIntentInput` remains the sole model-visible projection. Request-local invocation authority is separate from model content. Tool results and deterministic answers stay local. The existing original-question policy runs before provider reservation/transmission; no generic context, metadata, record serialization, history or second inference path was added.

## Equivalence and regression mapping

The new differential test captures the actual registered production planner factory, then compares the old factory with that factory through the HTTP route. A delegating test-only `VapAiGateway` spy counts crossings; it does not replace policy or provider execution. Synthetic transport overrides only wire delivery, retaining permit/admission/buffer checks and zero live provider traffic.

| Requirement                          | Evidence                                                                                                                                                                                                                                                                                                               |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Actual routing and adapter contract  | Gateway spy is invoked once on eligible migrated HTTP requests and zero times on the old path; disabled/anonymous/invalid-context requests cannot reach it. Ordinary composition/interaction/planner source has no Anthropic dependency.                                                                               |
| Valid intent and deterministic facts | Approved customer-read plan returns the stored local name in both paths; model-provided factual text is never used. Unsupported intent remains identical.                                                                                                                                                              |
| Context minimization                 | Actual SDK request bodies are byte-identical. Stored private customer text, real selected public IDs, actor attribution ID, workspace selector and arbitrary context sentinel are absent; stored text appears only in authorized local facts.                                                                          |
| Original input                       | Leading tab, zero-width format control, original character overflow and original byte overflow compare old/new denial, zero attempts/windows/attempted audit/send/tools. Existing full original-question regressions also run through the new default route.                                                           |
| Admission                            | Missing acknowledgement, disabled provider, disabled egress, anonymous caller, extra context bag and insufficient quota preserve status/audit/no-send behavior. Existing authority and cross-context suites retain the fuller matrix.                                                                                  |
| Accounting                           | Both paths retain exactly **552,816 micro-USD per attempt**, allocated to the same five windows. Audit admission rollback leaves zero attempts/reserves. Replay cannot cross the gateway or reserve/send a second time. Existing PostgreSQL races, rollback, recovery and finalization tests remain unchanged.         |
| Failure handling                     | Malformed envelope, malformed plan, forbidden fiscal action and transport exception preserve generic status, no sensitive sentinel, no tool request and appropriate retained liability. Existing deadline/timeout/TLS/CLI/permit suites exercise their original boundaries through the migrated path where applicable. |
| Audit and final disclosure           | Ordered event lists, bounded attempt state/outcome/usage, local fact payload and reservation delta match. Existing received-audit rollback and final-disclosure withdrawal tests execute through production gateway resolution.                                                                                        |
| Frozen invariants                    | All seven façade types and all existing security-critical policy/transport/ledger files have unchanged hashes. No generalized price, identity, endpoint or SDK facility.                                                                                                                                               |

The 17 differential scenarios are read, unsupported, tab, format control, character overflow, byte overflow, missing acknowledgement, disabled provider, disabled egress, anonymous user, extra context, quota, attempted-audit rollback, malicious plan, malformed plan, malformed envelope and transport failure. These are not 17 different providers or fabricated live tests. The final replay case uses one nonce twice and requires one gateway call/attempt/send/reservation only.

## Validation actually executed

| Gate           | Exit   | Actual result                                                    |
| -------------- | ------ | ---------------------------------------------------------------- |
| sqlite         | 0      | 2174 passed; 315 skipped; 0 failed; 1 warnings; 14676 assertions |
| pg-concurrency | 0      | 315 passed; 0 skipped; 0 failed; 1 warnings; 9397 assertions     |
| pg-integration | 0      | 1410 passed; 0 skipped; 0 failed; 0 warnings; 10632 assertions   |
| types          | 0      | Passed                                                           |
| ui             | 0      | 12 passed                                                        |
| pint           | 0      | Passed                                                           |
| eslint         | 1      | 9,719 inherited errors; zero warnings                            |
| format         | 1      | Four inherited resource-format failures                          |
| focused        | 0      | 37 passed; 0 skipped; 0 failed; 0 warnings; 673 assertions       |
| phpstan        | 1      | Exact same 21 inherited diagnostics; zero new                    |
| PHP syntax     | 0 each | All three changed PHP files passed                               |

Counts from overlapping suites must not be summed as distinct cases. Exact commands, exits, parsed summaries and log hashes appear in the evidence artifact. Full-run failures, if any, remain failures even when targeted reruns pass. No production database or provider API was contacted. PostgreSQL gates use isolated UTF8 `facturac_test_phase7b2_*` databases on the existing local service.

### Inherited diagnostics and anomalies

All three fresh full regression runs passed: SQLite, PostgreSQL concurrency and broad PostgreSQL integration. Neither previously classified PostgreSQL flake recurred; no targeted failure rerun was needed. The PostgreSQL integration run completed 1,410 cases with 10,632 assertions in 1,571,002 ms.

PHPStan exited 1 with the exact same 21 diagnostic objects as the accepted Phase 7B.1 baseline, including locations/messages; zero new diagnostics. ESLint exited 1 with 9,719 inherited errors and zero warnings. Resource formatting exited 1 for the same four unchanged files: `resources/js/pages/Establishments/Index.vue`, `resources/promo/coming-soon.html`, `resources/promo/render.mjs`, and `resources/promo/switch.html`. No frontend file changed. These are reported failures, not clean gates, and were not repaired in this bounded migration.

SQLite and PostgreSQL concurrency each reported one warning; the unchanged `tests/Feature/PhaseFiveSubscriptionBillingTest.php:20` contains the ineffective non-compound `DomainException` import reproduced by PHP syntax checking. Broad PostgreSQL integration reported no warning. No new migration warning or diagnostic was observed. Types, UI tests, changed PHP syntax and Pint passed.

No production deadline, concurrency behavior, rate limiter or inherited test assertion was changed to stabilize the two previously reviewed PostgreSQL flakes. The advisory-barrier/250-ms lock-timeout and native-clock quota-window classifications are established prior independent-review evidence, not blanket permission to dismiss new failures.

## Preservation and implementation attempts

SHA-256 comparison covers 1,136 pre-existing files: only the composition root and the intentionally updated routing test changed. All 1,134 other baseline files are unchanged, including the seven frozen façade types, Phase 7A security-critical implementation, governing contracts, activation decision, existing evidence, dependencies and frontend. The full baseline manifest and exact two-file patches are embedded in the evidence. The PostgreSQL suite's generated refresh of `docs/phase-6-implementation-evidence.json` was restored from the captured pre-test backup and hash-verified; no historical evidence was rewritten.

The initial focused implementation run passed all 37 cases (19 new plus 18 retained). No runtime correction, failed implementation attempt, dependency change, contract amendment or architectural workaround was required. Known inherited full-run anomalies, if observed, are documented above rather than erased by reruns.

Boost's version-specific `search-docs` capability was unavailable. Installed Laravel 13.24.0 and AI SDK v0.11.2 sources and existing container/testing conventions were used; no package API was guessed or upgraded. Laravel/AI SDK/Pest guidance was applied. `.ai/rules` is absent.

## Limits and exclusions

Production provider enablement and egress defaults remain false, budgets zero, secret and approval references unset. The [activation decision](phase-7-production-activation-decision.md) remains unchanged and blocking. Container routing is not account approval, legal/privacy acceptance, funding, rehearsal authorization or production readiness.

No live inference, real credentials, key creation/import, provider account action, network activation, OpenAI, BYOK, tenant configuration, self-hosted endpoint, catalogue/settings UI, generalized provider accounting, automatic fallback or Phase 8 work. External integration access remains disabled. No 7B.3 preparation code was introduced.

Synthetic fixtures prove deterministic application behavior, not real provider availability, geography, retention, invoice charges or live FPM deployment compatibility. Those remain external/operator gates. Detailed upstream failure categories remain intentionally collapsed by the accepted adapter; this migration does not add telemetry or disclose raw errors.

## Exact independent review handoff

> Perform the independent Phase 7B.2 Anthropic gateway migration review only. Read the approved Phase 7B contract, accepted 7B.1 report/evidence and review, Phase 7/P7-PC1/remediation/activation documents, this report and its machine-readable evidence. Inspect the actual pre-task patch, production binding, unchanged gateway/adapter and accepted security/transport path. Do not treat implementation assertions as proof.
>
> Verify enabled HTTP planning crosses `GatewayAssistantPlanner → VapAiGateway → LegacyAssistantAiGateway → AnthropicIntentPlanner`, while disabled defaults still deny. Challenge direct adapter access, provider-object/exception isolation, original-question validation, zero retrieved model context, fresh authority, acknowledgement, exact 552,816 micro-USD liability, atomic audit and replay/rollback/concurrency semantics. Confirm final network delivery still uses the unchanged reviewed transport and SDK quarantine remains closed.
>
> Independently assess actual production-factory differential tests, exact request bytes, local facts, audit ordering, attempt outcomes and new gateway invocation counts. Review every changed security-critical file and verify all protected hashes; distinguish fresh gates, historical evidence, full-run failures and targeted reruns. Do not assume every PostgreSQL anomaly is one of the two inherited flakes. Confirm no invariant, activation setting, credential, dependency or later-stage facility changed.
>
> Report concrete findings with severity/location/evidence and a review verdict. Do not modify code/tests/docs, activate a provider, make live calls or begin 7B.3. Technical review approval grants no production activation authority.

PHASE 7B.2 COMPLETE — READY FOR INDEPENDENT REVIEW
