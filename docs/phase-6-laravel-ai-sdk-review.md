# Phase 6 — Laravel AI SDK compatibility and architecture review

Review date: 2026-10-08. Scope: architecture, compatibility, failure attribution and a bounded corrective handoff. Runtime implementation, dependencies, provider configuration and migrations are not changed by this review. The Phase 6 foundation is not independently approved by this SDK decision.

## 1. Executive verdict and exact decision

**B — KEEP BUT INACTIVE.** Retain the installed, lockfile-pinned Laravel AI SDK as an inactive infrastructure dependency, subject to the explicit quarantine below. Do not connect it to the five tools, replace the deterministic executor, or activate any provider. There is a viable future SDK-backed planner adapter; there is no approved SDK tool-result/model loop.

**SDK APPROVED BUT PROVIDER INTEGRATION DEFERRED**

This is the narrow **SDK1 amendment** to the Phase 6 SDK exclusion: installation of `laravel/ai` v0.11.2 at reference `ee2c5162838d440c4e2e629ea93c8c87e838eaed` and its four identified new dependencies is permitted; execution of SDK providers, SDK tools, conversation storage, streaming, retries/failover, background agents and model inference remains prohibited. Every other Phase 6 requirement and Phase 1–5B/A1/SA1 decision remains authoritative. SDK1 does not supersede identity amendment A1 or status amendment SA1. Historical reports remain historical; their original dependency-exclusion finding was correct under the authority then in force.

Retention is **not** a finding that the current installation is technically inactive. The package provider is currently discovered and provider objects resolve successfully outside the assistant flag. The bounded next implementation must establish the fail-closed quarantine, refresh validation and return to Astra. Neither test success nor SDK adoption authorizes an inference pilot.

## 2. Evidence and repository/dependency findings

Read the complete governing Phase 6 contract, implementation report and machine-readable evidence; reused unchanged governing-contract reads from implementation and inspected the actual assistant context, request boundary, planner/plan/input validators, literal tools, executor, quota/lease/replay guard, SQL search, audit enrichment, unavailable binding, UI and regressions. The evidence JSON was parsed completely, including its 1,038-file baseline, all retained plans/validation records and 32 implementation/report hashes. All 32 hashes match the current files. The report is a handoff, not correctness proof.

Inspected the installed SDK source, package manifest, generated autoload/discovery and configuration. Version-specific local source controls this assessment. The current [official Laravel AI documentation](https://laravel.com/framework/docs/13.x/ai-sdk) was consulted for the public API; it is evolving and does not override inspected v0.11.2 behavior. The package supports provider integration, tools, structured output and optional conversation persistence, but those facilities do not supply Facturac authorization.

### Exact package delta

`git show HEAD:composer.lock` hashes to the recorded pre-Phase-6 lockfile hash, so HEAD is a verified dependency baseline here despite the much larger dirty application tree. A parsed comparison of all production/dev package records establishes:

| Added package            | Locked version | Role                                                                                     |
| ------------------------ | -------------- | ---------------------------------------------------------------------------------------- |
| `laravel/ai`             | v0.11.2        | Direct SDK dependency, root constraint `^0.11.2`                                         |
| `aws/aws-sdk-php`        | 3.399.2        | SDK requirement, notably Bedrock/STS transport/credential machinery                      |
| `aws/aws-crt-php`        | v1.2.7         | AWS runtime dependency; native extension is suggested, not installed by this PHP package |
| `mtdowling/jmespath.php` | 2.9.2          | AWS JSON expression support                                                              |
| `symfony/filesystem`     | v8.1.6         | AWS filesystem requirement                                                               |

**Zero existing package records changed; zero removed.** Laravel remains 13.24.0, Inertia Laravel 3.3.1, Pest 5.0.4 and Activitylog 5.0.0. Existing Guzzle, PSR HTTP and JSON-schema facilities are reused. No Prism/OpenAI/Anthropic vendor SDK was separately added. SDK development-only requirements are not application installations; do not infer that a new MCP server/client was installed from `require-dev` or `suggest`.

The root composer change is the new SDK requirement. Generated Composer PSR-4/classmap/files include `Laravel\\Ai\\`, SDK `functions.php`, AWS/JMESPath and Symfony additions. Root `post-autoload-dump` already runs Laravel package discovery; root `post-update-cmd` already runs asset publishing and Boost update. Those scripts predate this installation.

### Beyond dependency metadata

- `bootstrap/cache/packages.php` and `services.php` now discover/register `Laravel\\Ai\\AiServiceProvider`. `bootstrap/providers.php` remains unchanged. The SDK registers `AiManager`, a database conversation-store binding, configuration, generator commands, publishing registrations and collection/string macros. Inspection of `AiServiceProvider::register/boot` finds no automatic inference request or schema migration.
- `config/ai.php` now exists and is byte-identical to the installed package's published default. It was absent at the implementation stop inspection. It includes live provider names/URLs, environment lookups, OpenAI/Azure `store=true`, Ollama localhost and Bedrock default AWS credential discovery. Publishing configuration is a real additional application file, not merely lock metadata. No secret values are embedded. The exact publishing command/operator is not established by these artifacts.
- `boost.json` adds `ai-sdk-development`; matching skill material exists under `.agents`, `.claude` and `.junie`. These are development guidance, not runtime agents. No requirement in that skill authorizes bypassing the Phase 6 contract.
- No SDK conversation migration has been copied into `database/migrations`; no `app`/`routes`/`tests` SDK integration or agent class was found. Existing `AssistantPlanner` still resolves to `UnavailableAssistantPlanner`. The only Phase 6 schema change remains the approved customer index.
- `.env.example`, `tests/Pest.php`, existing integration config and explicit provider registration match the pre-Phase-6 inventory. The command flag visible in the Git diff is inherited Phase 5A/B work. `phpunit.xml` remains the existing testing setup; no AI bootstrap or test-environment override was added. No nonempty SDK-referenced variables were found in the local `.env` or current process environment; only presence/names were inspected, never secret values reported. Absence of those values is not an execution control.
- Composer validation and platform requirements pass on PHP 8.4.25. The new resolved Symfony package requires PHP >=8.4.1; the installed target satisfies it. A deployment must validate the complete lockfile, not merely the SDK's `php:^8.3` declaration.
- No production database was queried/migrated by this review. The SDK's vendor migration is only publishable, not automatically loaded/applied by its service provider. Its potential tables are discussed in §11; there is no evidence from the application migration inventory that they were introduced.

The deliberate Phase 6 changes are the 32 hashed implementation/report files and generated route bindings already inventoried. The five added packages, discovery/autoload, published AI config and generated SDK skill guidance are installation-associated changes. The many older fiscal/API/command/UI modifications and lint debt in the dirty Git tree are pre-existing, not SDK changes.

## 3. Broad-gate failure root cause

The retained final implementation logs establish **925/925 SQLite failures and 955/955 PostgreSQL integration failures with the exact same message**: `Class "Laravel\\Ai\\AiServiceProvider" not found`. Failures originate at Laravel's `ProviderRepository::createProvider` (line 205, `new $provider($this->app)`) or subsequent application provider creation. They occur during boot, before the failed test's business assertion. The original separate SQLite attempt's 26 OpenSSL random-state failures are environmental and have a different cause; the writable `RANDFILE` rerun must not be conflated with them.

A controlled, memory-only reproduction loaded the existing Composer mappings with the new SDK namespace/class entries omitted, then asked Laravel's real provider repository to instantiate the newly discovered provider. It produced the exact recorded error. Restoring the current loader in the same probe constructed `Laravel\\Ai\\AiServiceProvider` successfully. No SDK register/boot, provider request, business query or filesystem mutation was performed by that probe. Log: `/private/tmp/phase6-sdk-review-autoload-probe.log`.

This demonstrates the mixed-state mechanism: a long-running test process can hold a pre-install Composer loader while later Laravel application boots read newly generated package discovery. The original observation also caught the provider file unavailable before it appeared. We cannot retrospectively distinguish incomplete extraction from a stale in-memory loader in every failed process; both are installation-in-progress inconsistencies. We do not pretend to have retained the original process's autoloader object.

| Question                                              | Evidence-based answer                                                                                                                                                                    |
| ----------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Did the SDK cause the failures?                       | Its new discovered provider was the class boot could not resolve. The evidence identifies an incomplete/inconsistent installation view, not a demonstrated defect in SDK provider logic. |
| Did a transitive dependency fail?                     | No retained failure names AWS, JMESPath, Symfony or a missing transitive symbol. They were not implicated by these errors.                                                               |
| Did Composer change another existing package/version? | No. The parsed lockfile comparison proves all existing package records identical.                                                                                                        |
| Environmental/timing-related?                         | Yes: running tests while dependency/autoload/discovery files changed. The memory-only reproduction reproduces that mechanism.                                                            |
| Genuine Phase 6 regression?                           | These boot failures provide no evidence of one. They also cannot certify Phase 6 correctness; stable full reruns are required.                                                           |
| Unrelated inherited failure?                          | The provider-class errors were new installation-state failures, not inherited repository debt. The earlier OpenSSL path issue and the retained warnings are separate.                    |

The complete SQLite suite and exact previously failing 24-file PostgreSQL integration selection were rerun without runtime fixes or dependency changes. The missing-provider error disappeared from both. SQLite passed completely; PostgreSQL exposed one different environment failure, isolated below. These results do not establish that a future SDK agent/tool integration is safe. Results are recorded in §15. No dependency change is reverted, installed, upgraded or repaired during this review.

### Separate PostgreSQL encoding finding

The stable PostgreSQL selection reached **1,151 passed / 9,011 assertions of 1,152 tests**, with one failure at `tests/Feature/AssistantFoundationTest.php:473`: inserting `str_repeat('😀', 255)` into the existing customer `varchar(255)`. This is a valid 255-code-point fixture. The old disposable cluster/database reports `server_encoding=SQL_ASCII`, `client_encoding=SQL_ASCII`, and `char_length(repeat('😀',255))=1020`, equal to its byte length. The database cannot apply the intended Unicode character semantics.

A separate disposable database `facturac_test_phase6_sdk_review_utf8`, created from `template0` with UTF8 encoding, ran the exact unchanged failing test: **one passed / six assertions / 2,194 ms**, exit 0. No fixture or runtime code was altered. The new database reports `UTF8|UTF8|255|1020` for server/client encoding and character/byte length (`/private/tmp/phase6-sdk-review-utf8-encoding.log`, SHA-256 `0328e5ae158569788e08492f4be5520f2a7e82a39a660a42320a4de7a79dcbb2`). This isolates the cause to the old test database encoding, not laravel/ai, a transitive package or an overlength Phase 6 input. Do not shorten the emoji fixture, widen the business column, skip the PostgreSQL assertion or label it an SDK regression.

The Phase 6 regression exposed a previously unnoticed local PostgreSQL environment assumption. Previous green PostgreSQL gates are not proof of Unicode fidelity on SQL_ASCII. The narrow remedy is a mandatory UTF8 PostgreSQL test/deployment prerequisite and a fresh complete integration rerun in that environment. This review proves the formerly failing case on UTF8; it does **not** relabel the full SQL_ASCII selection as green. The complete UTF8 group/integration runs remain required after the inactive-SDK correction. No production database encoding or existing data is altered by this review.

## 4. Compatibility and intended architecture

The SDK is compatible as **optional future generic provider infrastructure underneath the application-owned planner port**:

```text
first-party request -> Facturac admission/context/quotas
                    -> AssistantPlanner (currently unavailable)
                       [future reviewed SDK adapter: plan only]
                    -> Facturac validates entire closed plan
                    -> fresh context + five fixed read adapters
                    -> existing domain/query capabilities
                    -> validated, audited deterministic browser cards
```

There is no edge from tool results back to the planner. The SDK does not become the authority or a second business engine. A future SDK adapter could translate the existing planner DTO into one structured planning request and return original bounded output for Facturac validation. It would have **no registered SDK business tools**, history, attachments, provider tools, MCP, subagents or tool loop under this contract. This future adapter still needs a separate provider/security handoff.

The SDK's ordinary `HasTools` loop is not a compatible drop-in executor. `Gateway/TextGenerationLoop.php:106–158` invokes a provider, executes returned calls, appends `ToolResultMessage` to subsequent input and continues. `approvalAwareToolResults` executes resolved handlers; the SDK does not enforce Facturac's all-plan validation, admitted-reference list, immutable context, original membership/permission ceiling or four-read distribution. `MaxSteps` limits model steps, not the number/cost/authority of all tools in those steps. SDK approval support does not imply Facturac fiscal approval.

A general thin SDK tool adapter is technically possible, but approving it for actual provider orchestration would require a new tool-result disclosure contract. Calling `AssistantTools::read` directly from an SDK tool would also bypass outer application admission/limits/audit/final-disclosure sequencing. That is not an approved shortcut.

## 5. Five-tool compatibility matrix

For all rows, the application input/output schema, permission checks, current scope, mandatory audit, quota/replay and result buffering remain authoritative and unchanged. No SDK interface grants scope or authority.

| Tool                        | Retained contract/source                                                                                                           | Thin adapter assessment                                                                                                           | Model disclosure under ordinary SDK loop                                                               |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `searchCustomers`           | Shared bounded customer search, exact q/status, 10,000+1 candidates, ten matches, four customer fields                             | No business rewrite. A future wrapper must preserve whole-interaction admission and SQL bound; current SDK registration deferred. | Names, public IDs, country/active and match existence would leave Facturac; prohibited now.            |
| `getCustomer`               | Explicit admitted customer reference, scoped existing read, four fields                                                            | No schema or authorization change needed; neither knowledge of ULID nor SDK request object is authority. Deferred wrapper only.   | Complete minimized customer result still contains business/personal data; prohibited.                  |
| `getFiscalDocumentSummary`  | Six-field subset of existing document capability; no legacy AGT/amounts                                                            | Preserve exact projection and workflow-versus-validity distinction; deferred wrapper.                                             | Document number/ID/type/revision/workflow/environment reveal fiscal/business information; prohibited.  |
| `getQualifiedAgtStatus`     | Existing twelve-field V2 qualified projection, SA1 uncertainty and observation semantics                                           | Preserve independent bound read; never poll/rebuild or check receipt eligibility. Deferred wrapper.                               | Identifier, status, timestamps and reconciliation state disclose operational fiscal facts; prohibited. |
| `getMonthlyRecordedBilling` | Existing eight-currency monthly recorded-billing capability, exact integer money, snapshot/cap/timeout and separate analytics role | Preserve real `ExecutionContext` and complete capability invocation, not raw query access. Deferred wrapper.                      | Monetary totals/counts/month/currency and business performance would leave Facturac; prohibited.       |

An SDK adapter can mechanically serialize data to its `Tool::handle` string result, but mechanical compatibility is not authorization to do so. No input/output field change, audit replacement or quota change is justified by adopting the SDK. No generic Facturac service object/container/callback is exposed.

## 6. KEEP / ADAPT / REPLACE / DEFER assessment

| Current/proposed component                                                                                   | Decision                        | Reason                                                                                                                                                                  |
| ------------------------------------------------------------------------------------------------------------ | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `AssistantInteractionContext`, existing `ExecutionContext` and capabilities                                  | KEEP                            | Facturac identity, tenant/entity/environment, membership freshness and permission semantics.                                                                            |
| `AssistantInput`, `AssistantJson`, `AssistantPlan`                                                           | KEEP                            | Closed JSON, duplicate keys, admitted references, full-plan validation and exact bounded call distribution are security controls. SDK schema mode is not a replacement. |
| `AssistantTools`, output validators, `CustomerSearchCommand/Query`, approved index                           | KEEP                            | Domain delegation, minimization and database resource bound. SDK tool naming/dispatch does not supply these.                                                            |
| `AssistantInteraction`, response buffering, final revalidation                                               | KEEP                            | Application security transaction/sequencing boundary, not generic chat orchestration.                                                                                   |
| `AssistantBudget`, nonce marker, user lease, `AssistantExecutionGuard`                                       | KEEP                            | Deterministic per-human/tenant security/cost limits and primary DB/timeouts.                                                                                            |
| `AssistantAudit`, required capability audits, redaction middleware/provider enrichment                       | KEEP                            | Attributable, synchronous, minimized audit; SDK events carry content and are not durable audit guarantees.                                                              |
| `AssistantPlanner`                                                                                           | ADAPT later                     | Preserve the small application-owned seam. A future SDK-backed implementation can sit behind it; do not replace the interface with an SDK Agent throughout the app.     |
| `UnavailableAssistantPlanner`                                                                                | KEEP now                        | Correct production default until separately approved transport exists.                                                                                                  |
| Static advertised planner schemas                                                                            | ADAPT later, if verified useful | A serializer may express them through SDK schema types; Facturac's validators and original byte/duplicate-key checks still control acceptance.                          |
| Provider client/request/response mapping                                                                     | DEFER; SDK preferred later      | No custom live transport exists to remove. This is the genuine generic capability the SDK may supply.                                                                   |
| SDK-native tools, agent abstraction, conversation/history, streaming, queueing, broadcasting, retry/failover | DEFER                           | Not needed by the deterministic foundation; several contradict current boundaries.                                                                                      |
| Existing production component to replace now                                                                 | **REPLACE: none**               | Similar naming is not equivalence. There is no substantial custom provider/chat/history/streaming infrastructure currently duplicated.                                  |

`StructuredAgentResponse::__toString()/toJson` re-encodes parsed structured data. A future adapter must not use re-encoding to erase duplicate-key/overlength evidence before `AssistantJson` validation. The SDK retains text/raw fields, but exact provider-specific original-output behavior and transport byte bounds must be tested before adoption.

## 7. Model-data boundary, minimization and injection

**Now: no data is authorized to any inference provider.** Questions, references and results all remain local. This review made no inference request and added no keys.

After a separate provider gate, the existing planner design potentially permits only the bounded human question, explicitly supplied kind/public-ID references, Luanda month, static schemas and initially allowed tool names. The question may itself contain sensitive data; consent, notice, processing terms and filtering policies must be reviewed. User-supplied reference ULIDs are already part of the signed planner DTO, but that does not authorize transmitting database-derived identifiers, other tenants' IDs, internal sequential IDs, attribution IDs or arbitrary identifiers. Replacing references with opaque per-request tokens would be a future explicit amendment, not a silent implementation choice.

No complete tool result is required for planning. Therefore the current permitted tool-result disclosure is **zero**, including public IDs, country/active flags, customer names, document numbers/workflow, qualified AGT state, timestamps, monthly money and counts. These are not safe merely because the browser representation is minimized.

Never send customer NIF/tax identifiers, contact names/email/phone/addresses, bank/payment details, negotiated pricing or other excluded stored attributes. Never send document lines, customer links, XML/PDF, raw AGT payloads/history/messages, credentials/signatures/keys or internal IDs. Monetary/business information is also excluded; the fact that monthly totals are aggregated does not authorize external processing.

Stored malicious names currently cannot become model instructions because they never enter planner context, and deterministic cards escape text and make controls visible. Natural-language question injection can still affect a proposed plan, but cannot expand the literal registry, reference admission, permission ceiling or execution limits. If a future tool loop sends stored text to a model, escaping HTML, role labels, delimiters, prompting and output redaction cannot guarantee that it will ignore instructions. That would require a separately designed disclosure policy and adversarial evaluation; no such trust boundary is approved here.

SDK `ToolInvoked`, `AgentPrompted` and related events contain arguments/results/prompts/responses. Generic logging/serialization/APM listeners could retain the contents. Published OpenAI/Azure configuration defaults `store=true`; `store=false` can affect request storage behavior but is not evidence of provider retention/training/residency terms or zero data retention. All provider-side logs, subprocess/credential metadata and telemetry must be assessed at the live gate.

## 8. Required inactive-SDK mechanism

The current package is installed and booted, though unused by assistant code. A no-network inspection resolved both `AiManager` and `OpenAiProvider` while all three application flags were false. Thus **a false assistant flag, an empty provider list or no API key alone is insufficient**. `AiManager::getInstanceConfig` can fall back to a named driver; Ollama can be keyless, and Bedrock may use AWS's default credential chain. Laravel HTTP fakes alone do not cover AWS transport.

SDK1 requires the following exact quarantine in the next bounded implementation:

1. Keep the locked package versions/references. Add only `laravel/ai` to Composer's `extra.laravel.dont-discover`, preserving other settings. Do not manually register `AiServiceProvider`. Rebuild package/service discovery through Composer/Laravel's supported local workflow; never commit generated cache contents or edit vendor code. Confirm configuration-cache and long-lived-worker restart requirements for eventual deployment.
2. In the existing application provider, bind `Laravel\\Ai\\AiManager` to an unconditional throwing factory using a dedicated fixed-message `App\Exceptions\AiExecutionDisabled` application exception. Exclude that payload-free local denial from automatic exception reporting; do not serialize SDK call arguments or chained exceptions. Register this denial for HTTP, console and worker application boot. Do not add an environment switch that enables it. Do not return the normal manager or a success-producing fake in production. No resolved SDK facade instance may survive/reintroduce an earlier manager at application bootstrap; test the normal facade/container resolution paths in fresh applications.
3. Replace the newly published live defaults in `config/ai.php` with an explicitly inactive configuration: null defaults for each modality, empty providers, embedding cache disabled, conversation title generation disabled. No provider URL, key/environment credential binding, ambient AWS credential option or provider-choice UI. This is defense in depth; the manager denial is the authoritative application guard. Do not delete/change any unrelated AWS/application credentials or disable unrelated approved HTTP integrations.
4. Preserve `AssistantPlanner -> UnavailableAssistantPlanner` and all existing false flags. No SDK Agent/HasTools adapter, SDK call, provider configuration or package conversation-store binding is introduced. With discovery suppressed, SDK macros and generator bindings do not enter normal application bootstrap. Composer's namespaced helper functions remain autoloaded but normal execution resolves the denied manager.
5. Add an application architecture regression excluding SDK imports/functions/direct constructors from application runtime outside the one denial binding and its exception handling. Explicitly reject direct `new AiManager`, provider/gateway construction, manual provider registration, SDK helpers and arbitrary SDK dispatch in app/routes/resources/runtime scripts. Test exception and binding classes are the narrow allowlist. This guards accidental code bypass; it is not a PHP sandbox against someone authorized to replace application code.
6. Negative tests must show ordinary facade/container and representative text/stream/non-text/file/store/queue-worker entry paths cannot resolve/execute a provider, even with synthetic populated provider config. No real key, endpoint or live request. Keep a separate HTTP/queue spy as defense in depth and verify the denial precedes SDK/Bedrock client or credential discovery. Do not weaken unrelated application HTTP behavior globally.

Deployment egress controls remain a separate operational defense. A hostile PHP program can bypass any application service abstraction; no claim of process-level network isolation follows from Composer exclusion or this guard. Code approval plus deployment egress policy must prevent unauthorized direct clients. This review does not configure firewall rules or enable outbound AI access.

## 9. Quotas, replay, retries and failover

The installed SDK does not implement Facturac's per-user six/minute and 120/day, workspace thirty/minute, actor/nonce TTL, owner-token lease, initial authority ceiling or explicit tenant binding. Its upstream rate-limit exception mapping is not an application quota system. Keep every existing quota, replay and lock boundary, including independent monthly-billing limits.

SDK `Promptable::withModelFailover` may restart a provider/model attempt. The text loop can perform multiple provider steps; validation failures can be converted to model-readable errors for correction (`InvokesTools.php:37`), and `RepairToolCalls` enables another repair behavior. Per-request network timeouts/default 60 seconds are not the signed whole-planner ten-second budget. AWS also has its own client/credential/retry paths. None may multiply Facturac's execution budget.

For the retained inactive dependency there are zero provider attempts and zero SDK tool executions. A future planner-only adapter must have exactly one approved provider attempt, no fallback provider array, no repair, no tools, no title call and no background continuation. Provider options cannot be caller-controlled. Absolute cancellation/timeouts, raw byte limits and retry policy must be certified at the next provider gate; `MaxSteps=1`/SDK timeout attributes alone are not sufficient proof. Facturac nonce replay remains a conflict/new-read protocol, not SDK conversation memory or a cached answer.

## 10. Audit and observability

Facturac's synchronous minimized audit remains authoritative. SDK events are process instrumentation, not guaranteed durable audit, authority checks or a transaction with business reads. Do not forward entire SDK event objects, exception chains, prompt/response DTOs, arguments or raw provider IDs into Activitylog, APM or logs.

| Fact to distinguish                       | Authoritative interpretation / requirement                                                                                                                                                                                                                                     |
| ----------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Request received                          | Existing admitted `assistant.interaction.started`; pre-admission failures use the required failed event with only verified attribution. Do not invent a tenant from unvalidated URL input or record the body.                                                                  |
| Tool requested and authorized             | Current `assistant.tool.requested` is written after fresh permission revalidation and before the capability call. It means both requested and authorized at that checkpoint; it is not proof of later success. No extra event is required solely to duplicate that checkpoint. |
| Tool rejected                             | `assistant.tool.denied`, or whole-plan rejection before any tool exists. Only names from a completely validated closed plan may enter audit. An invented model tool name is not an audit label.                                                                                |
| Tool executed                             | Existing successful capability audit plus wrapper `assistant.tool.succeeded`/server result UUID. No result content or monetary values.                                                                                                                                         |
| Quota rejected                            | Must be identifiable as a fixed server category, without question/nonce/result content. Current outer failure audit only says `failed`; this is a narrow observability gap, not a quota bypass.                                                                                |
| Deterministic answer produced             | Completed audit before disclosure, followed by final fresh authority checks. A subsequent failure means completed alone is not a network-delivery receipt.                                                                                                                     |
| SDK invocation blocked                    | Fixed `provider_disabled` category, never `provider_invoked`. Suppress payload-bearing exception reporting; a missing audit cannot become successful output.                                                                                                                   |
| Future actual provider invocation/failure | Deferred. A later contract must add minimized intent/completion/failure with server interaction ID, platform-approved provider/model configuration ID, bounded usage/duration/status, required audit semantics and no raw prompt/response.                                     |

For closure, clarify existing failure audit categories without broad audit redesign: server-owned fixed categories `quota_rejected`, `authorization_rejected`, `interaction_conflict`, `invalid_input`, `provider_disabled`, and `unavailable`. Apply these to existing failed/denied `outcome` values; use validated status/stage or the dedicated local exception, never parse error text or accept client categories. Keep all public error envelopes/statuses unchanged. Audit fresh per-tool authorization rejection with the existing denied event and a new server tool-call UUID if a validated call is available, without performing the read; preserve the meaning of requested-after-authorization. No fabricated provider event is emitted when the unavailable planner/manager refuses execution. This narrow clarification uses the contract's already-approved fixed outcome-category metadata and preserves A1/required-audit semantics.

Ordinary telemetry may count fixed failure/latency buckets. It must not contain tenants' names, prompts, arbitrary tool names, credentials, session headers or high-cardinality provider payloads. Operational audit access/retention and infrastructure redaction remain release requirements.

## 11. Conversation persistence

Persistence is optional. `RemembersConversations`/`RememberConversation` and `DatabaseConversationStore` implement it; the service provider merely binds the store and exposes a publishable migration. A stateless planner need not implement `Conversational`, remember history or create any table.

The vendor schema has conversation ID, optional polymorphic participant, title and timestamps; messages store content, attachments, tool calls/results, usage, meta and approval state. It has no Facturac workspace/legal-entity/environment or sponsor/membership binding. `getLatestConversationMessages` queries by conversation ID; `continue(id, as)` does not establish Facturac tenant permission. These facilities must not be exposed as tenant-authorized history endpoints without an application ownership model. This is a mismatch with our needs, not a claim that the SDK promises tenant isolation.

`RememberConversation::generateTitle` defaults to an additional provider request and can fall back to storing prompt text. This conflicts with one-call/no-transcript boundaries even if the main response is constrained. Do not publish/apply the SDK migration, bind a working store, use remember traits, store titles or enable history now.

Any future persistence requires explicit user plus immutable workspace/entity/environment ownership, fresh access on every read, non-enumerating public IDs, retention/deletion and backup policy, sensitive-field/model-response policy, encryption/access controls and a rule for membership/account deletion. Fiscal evidence must never be rewritten through chat deletion, and chat history must never become fiscal evidence or authority. That design is deferred.

## 12. Provider portability

The SDK offers a useful common surface and multiple provider implementations. Facturac can preserve its small planner port while changing a reviewed transport implementation; business services need no provider dependencies. Portability does not make response schemas, storage defaults, authentication, cancellation, hosted tools or data-processing terms equivalent.

Provider choice is **deferred now**. At a future gate it should be platform-controlled and deployment-configured from an explicit approved provider/model/destination allowlist, not chosen by request fields, tenant input, arbitrary URLs, fallback chains or an SDK convenience method. Each candidate requires its own privacy, security, latency, byte/token, structured-output and cancellation evidence. No tenant-configurable provider or bring-your-own key is approved. No design to the weakest common security model.

## 13. Testing architecture

Keep deterministic `AssistantPlanner` fakes for all authorization, context, schemas, limits, audit, read-only/minimization and UI security cases. Those assertions must not depend on an LLM sentence or SDK agent behavior. The existing exact parser and malicious-text tests remain essential even if a future SDK offers JSON-schema generation.

For SDK1 add negative quarantine tests: package discovery excluded; manager and facade resolution denied; named/on-demand/provider-array and modality paths cannot obtain a usable provider; synthetic keys/URLs and both assistant flag values cannot enable it; no SDK macro/store/migration or normal SDK runtime import; no HTTP, AWS credential resolution or provider job execution. Exercise cached-config/fresh application and worker boots. Fakes are not the production guard, and tests must not disable that guard globally merely to make SDK fakes work.

At the future live-adapter gate, SDK fakes with explicit responses and `preventStrayPrompts` can test DTO translation and proposed plans in isolated adapter tests. Default fake generated data is not an adequate security oracle. Separately capture transport requests using a no-network test handler to verify exact bytes, one attempt, no tools/results/history, destination restrictions, error redaction and cancellation. SDK fakes bypass transport and do not prove timeout/egress/privacy behavior. Laravel HTTP fakes do not intercept the AWS client; either an approved AWS-specific transport interception or exclusion is required before any Bedrock test adapter.

Preserve real primary PostgreSQL query/concurrency tests; SDK mocks cannot certify snapshots, leases, quota races or SQL resource bounds. Keep exact old assertions, diagnostic baselines and disabled external switches.

## 14. Completion criteria and explicit non-goals

The SDK discrepancy is resolved architecturally by SDK1, conditional on inactive installation controls. Returning Phase 6 for independent final review requires stable dependency/autoload/discovery state, UTF8 PostgreSQL test prerequisites, implemented/tested quarantine and audit category clarification, preserved five-tool foundation, full SQLite and PostgreSQL concurrency/integration gates, targeted/UI gates, exact inherited diagnostics comparison and updated implementation/evidence artifacts. This review does not itself approve the entire foundation.

Production deployment still needs index rollout, PostgreSQL/version/data plans, cache/audit capacity/retention, proxy/APM redaction and process restart evidence. No AGT assumptions or homologation evidence change. A separate Astra provider/security contract is required before external inference; it must address vendor/model, processing terms, consent, destinations, storage/logging, credentials, bytes/tokens, cancellation and errors.

Hard exclusions: no live/fake production provider adapter; no SDK-native execution of the five tools; no tool result in model context; no keys/endpoints/tenant provider selector; no new dependency upgrades; no SDK conversation migrations/storage; no chat history/title generation; no streaming/queueing/broadcasting/SDK repairs/failover; no MCP/subagents/web-search tools; no write/fiscal/receipt/AGT operation, overdue/new analytics, BYOW/webhooks/autonomy, external enablement or later roadmap work. No inherited repository-wide debt repair.

## 15. Review validation and preservation

Fresh review results so far: full SQLite **1,916 passed / 13,054 assertions**, 286 explicit PostgreSQL skips, one inherited warning, 577,579 ms, exit 0 (2,202 total). The full SQLite run includes all 131 assistant cases. The previous provider boot error does not recur. PHPStan remains exactly **21 inherited diagnostic identities**, complete details equal to the pre-Phase-6 capture, exit 1; no new diagnostic. Actual Vue renderer/UI: **eight passed**, 747.054 ms, exit 0. Composer metadata and platform checks pass, exit 0. The earlier 281 PostgreSQL skips increased to 286 because the final implementation added five actual PostgreSQL cases; none is claimed executed by SQLite.

| Additional fresh gate                                                  | Result                                                                                                                                 |
| ---------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Exact 24-file PostgreSQL integration, original SQL_ASCII test database | Exit 2; 1,151 passed, 9,011 assertions, one encoding failure, 1,009,088 ms; zero missing-provider failures.                            |
| Same failed extraction-cap test, separate UTF8 database                | Exit 0; one passed, six assertions, 2,194 ms; unchanged test/runtime.                                                                  |
| Type checking                                                          | Exit 0; `vue-tsc --noEmit`.                                                                                                            |
| Stale/current autoloader reproduction                                  | Exit 0; exact historical error reproduced with stale mappings, current provider constructs. No inference.                              |
| Current configuration/resolution inspection                            | Exit 0; unavailable planner, all access flags false, SDK discovered and OpenAI provider object resolvable. No provider method invoked. |

No runtime fixes were applied to achieve any result. Full PostgreSQL concurrency is required after the corrective implementation; the historical passing 281-case group and expanded 22-case assistant gate remain prior evidence, not silently relabelled as fresh review runs.

Exact substantive commands (all test data local/disposable):

```sh
RANDFILE=/private/tmp/phase6-sdk-review-rand php artisan test --compact -d memory_limit=1G
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --debug
node --test tests/assistant-ui.test.mjs
npm run types:check
composer validate --no-check-publish
composer check-platform-reqs
```

The PostgreSQL selection used `RANDFILE=/private/tmp/phase6-sdk-review-rand APP_ENV=testing FISCAL_PG_GATE=1 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55439 DB_DATABASE=facturac_test_phase1 DB_USERNAME=Oz DB_PASSWORD='' DB_URL='' php artisan test --compact -d memory_limit=1G`, followed by the **exact unchanged twenty-four paths** in `phase-6-implementation-evidence.json` → `validation.postgres_integration_final.command`. Only RANDFILE changed from that retained command. The UTF8 reproduction used the same prefix with `DB_DATABASE=facturac_test_phase6_sdk_review_utf8`, followed by `tests/Feature/AssistantFoundationTest.php --filter='combined extraction cap'`.

Encoding was inspected with `SELECT current_setting('server_encoding'), current_setting('client_encoding'), char_length(repeat('😀',255)), octet_length(repeat('😀',255));`. The separate database was created with `createdb -h 127.0.0.1 -p 55439 -U Oz --template=template0 --encoding=UTF8 --locale=C facturac_test_phase6_sdk_review_utf8`. This was a test-environment operation, not a production migration. Two PHP-stdin, memory-only probes performed the loader reproduction and non-invoking provider-resolution check described above; they did not patch files or call inference.

Retained local evidence:

| Gate                     | Log under `/private/tmp/`                    | SHA-256                                                            |
| ------------------------ | -------------------------------------------- | ------------------------------------------------------------------ |
| Full SQLite              | `phase6-sdk-review-sqlite.log`               | `5ed8758e07fabdec477964d028c82b77ed3c5a96981c83029cc00340fdf85a40` |
| PostgreSQL selection     | `phase6-sdk-review-postgres-integration.log` | `335da1028603667b60016dbbce440b53b0fcfe082dc6c186148176b212d1036c` |
| UTF8 extraction case     | `phase6-sdk-review-utf8-extraction.log`      | `d33fcce413a0e2a3a2325c64fa25faf2a848cb46038a01668fc50c0043de2cbc` |
| Autoloader probe         | `phase6-sdk-review-autoload-probe.log`       | `1278299a0862e84ce9bc0064f1af5f8d8567c6f7e13fb477716f9a9695bbfdd0` |
| Current resolution probe | `phase6-sdk-review-resolution.log`           | `5ee58b3c2523d893cdaa454b00a0b09a3c76bbc1146f9b15d77b7cb6453d5038` |
| PHPStan                  | `phase6-sdk-review-phpstan.log`              | `2b11e997591349ea5b612efa7f8fd696164f499c2fb9a640b96a305750412f2a` |
| UI                       | `phase6-sdk-review-ui.log`                   | `789959213787b8f9edb630848eef5f43967e458a3c4d87c1678a2173b17f13a1` |
| Types                    | `phase6-sdk-review-types.log`                | `76a0d417f4df143c6bdf0bc46a6ee1bf52c19ef20bed5b5c0f1c052ed05f1596` |
| Composer validation      | `phase6-sdk-review-composer-validate.log`    | `43add299dc97ecbd90ec2dc456ef5a0bd824bb9abdae2a3d7cdb520e21f8e86d` |
| Platform requirements    | `phase6-sdk-review-platform.log`             | `6e6c7622e59ab4c104149a823a529b7ee3d05e332a75baf59e2f2528777ea48e` |

The pre-review 1,062-file SHA-256 inventory is `/private/tmp/phase6-sdk-review-before.json`. No pre-existing inventoried file changed during this review; all historical reports/evidence, the 32 Phase 6 implementation/report hashes, application/tests/dependency files and signed contract remain identical. Only this new report is authored. Documentation formatting and `git diff --check` are the final artifact checks; Pint was not run in fix mode because no PHP was edited. Previous inherited 9,719 ESLint errors/four resource-format failures remain recorded debt, not fresh green global gates or SDK failures. The inherited warning count remains one in full SQLite; no warning removal/suppression was made. Prior full PostgreSQL concurrency is deliberately not presented as rerun here.

Reviewed stable dependency fingerprints: `composer.json` `fc825cf83ccee1ecc96dafcb213331a8068926ecc8b881914c7d381c9baab0c4`; `composer.lock` `fae82be0dff9f2099f35e694a19a2ab7f3fa686cab476667de48467200606bc6`; published `config/ai.php` `70e649c3622ee82678927897667001249070f3d14a8c3b61d34713a94042526c`. The unchanged governing contract hashes to `0d3654fa2e4ec18304dd799391e7a630893ff681e72d82c1535af96eadae5ea0`.

**Remaining closure findings:** SDK execution quarantine and fixed audit failure categories require the bounded implementation below; complete PostgreSQL gates require UTF8 and must be rerun after that change. Provider activation, data-processing approval and conversation/tool-loop design remain deferred. No unresolved SDK compatibility decision prevents the next bounded inactive-SDK implementation, but the Phase 6 foundation remains not ready for final approval until its implementation gates close.

## 16. Exact bounded Sol Medium implementation handoff

> Resume only Phase 6 inactive-SDK compatibility closure. Read the governing Phase 6 contract, historical implementation report/evidence and this complete SDK review. SDK1 permits only retaining locked `laravel/ai` v0.11.2 and its four identified transitive additions while preventing SDK execution. Every other signed boundary remains authoritative. Do not create a provider adapter or register business tools with the SDK.
>
> Implement exactly the six quarantine requirements in §8: exclude only laravel/ai auto-discovery, preserve lockfile package versions/references and Composer consistency, remove the published live defaults in favor of the specified inactive configuration, bind AiManager to an unconditional fixed-message AiExecutionDisabled exception in the existing application provider, prohibit runtime SDK bypass through a narrow architecture regression, and test normal HTTP/console/worker resolution with synthetic configuration and no network. Do not add an enable switch, manually register the vendor provider, alter vendor code, globally disable unrelated HTTP integrations, or load AWS credentials. Preserve AssistantPlanner's unavailable production binding and all false access flags.
>
> Apply only the fixed audit category clarification in §10 to existing failed/denied events. Keep public statuses/error envelopes and required-audit withholding unchanged. Cover per-tool freshness rejection, quota refusal and disabled-provider refusal without logging raw arguments/results/headers. Preserve the existing requested-after-authorization meaning, server attribution/correlation and immutable context. No new audit table or content persistence.
>
> Keep the five tools, their shared capabilities and schemas, full-plan/reference validation, customer SQL/index, billing/SA1/V1/V2 semantics, budgets/leases/replay, extraction/deadline checks and deterministic UI unchanged except a demonstrated narrowly scoped regression fix. Do not replace application security machinery with SDK features. If the specified guard cannot cover a public SDK path before client/credential/network execution, stop with the precise source-level blocker for Astra; do not silently broaden scope or implement a live fallback.
>
> Require `server_encoding=UTF8` in the disposable PostgreSQL gate preflight and use a UTF8 test database with the intended deployment collation. Preserve the emoji extraction-cap assertion exactly; no column change, fixture shortening or skip. Rerun the complete selection on UTF8; do not combine the old SQL_ASCII run and one UTF8 pass into a claimed full green run. Do not change production database encoding or data.
>
> Establish a stable dependency snapshot before tests; do not run Composer mutations concurrently with suites or reuse processes holding the old autoloader. Re-run the entire existing SQLite suite with writable test-local RANDFILE and suitable memory, full PostgreSQL group, exact preserved 24-file integration selection plus new quarantine tests, final assistant PostgreSQL/UI cases, PHPStan exact 21-diagnostic comparison, types, required Pint, changed-file lint/format and diff checks. Add new tests to applicable CI selections without deleting or weakening prior cases. No inference request may be made; fakes/spies must fail stray calls. Record all commands, statuses/counts, failures/flakes, dependency hashes and preservation evidence.
>
> Add an explicit SDK1 notice to the Phase 6 contract pointing to this review; preserve historical exclusion language as history rather than silently rewriting it. Update the implementation report and evidence with the inactive dependency controls, fresh final gates, classified inherited debt, limitations and exact Astra final-review handoff. SDK adoption is not provider approval. Conclude PHASE 6 FOUNDATION — READY FOR ASTRA REVIEW only if all bounded implementation gates are satisfied; otherwise PHASE 6 FOUNDATION — NOT READY with concrete blockers. Stop for Astra's independent final review. Do not begin another increment, provider selection, live inference or a subsequent phase.
