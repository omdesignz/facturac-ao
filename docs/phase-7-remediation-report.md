# Phase 7 bounded remediation report

Date: 2026-10-08. Scope: the two findings from the independent Phase 7 review only. This is an implementation handback, not an independent approval or production-activation decision.

## HIGH: CLI resolution under PHP-FPM

Root cause: the DNS subprocess used `PHP_BINARY` unconditionally. In an FPM worker that identifies the FPM executable, which cannot execute CLI `-r` scripts.

`AssistantProviderTransport::cliExecutable()` now selects only a trusted absolute executable: an explicit protected `assistant.provider.dns_php_cli` deployment setting, otherwise the current binary only when the runtime SAPI is `cli`, otherwise `PHP_BINDIR/php`. It never searches PATH, reads executable-selection environment variables, or accepts request arguments. An invalid explicit setting does not fall back. The canonical file must exist and be executable. A real offline subprocess, using an argument array and `-n`, must return exactly `cli:<running PHP_VERSION_ID>` before that executable can run the existing fixed DNS script. The probe has a 64-byte output bound, rejects stderr, and shares the original one-second DNS deadline. Expired deadlines fail closed; running processes are stopped on exit.

The DNS host/script, IP validation, public-address restrictions, pinned destination, port, TLS checks, redirects/proxy prohibition, one-use permit and pre-transmission authority checks are unchanged. No shell command string is constructed. No user input selects either executable or subprocess arguments.

Deployment compatibility: a standard Linux FPM deployment with a matching CLI at its compiled `PHP_BINDIR/php` works without an override. Relocated or version-suffixed deployments must set the protected absolute CLI path explicitly. This Herd installation reports `PHP_BINDIR=/bin`, so its FPM deployment requires the explicit matching Herd CLI path. CLI and FPM must match the exact PHP version ID; install/update them together. Missing CLI, disabled subprocess support, mismatched versions or an unavailable executable deny inference. Protect the executable and its parent directories as deployment assets. No deployment configuration was enabled or live FPM/provider request performed. Offline tests exercise the real selection/probe subprocess with FPM runtime inputs and relocated CLI paths; they do not override `wire()` to simulate this correction.

## MEDIUM: original-question validation

Root cause: Phase 6 normalized `AssistantInput::question` using `trim()` before Phase 7 received it. Tabs and excessive surrounding whitespace could disappear before the Phase 7 disclosure and length policy ran.

`AssistantInput` now retains the exact parsed request question in a private immutable field. Its existing normalized public `question`, validation and local planner behavior remain unchanged. `providerQuestion()` applies the existing `AssistantDisclosure` policy to the original field and references. `ProviderIntentInput` exclusively uses that validated result. The raw field is not added to the provider DTO, audit schema or database. Valid admitted spaces/newlines are preserved; approved reference replacement remains the only transformation of outbound question text.

The real HTTP pipeline regressions prove that leading tabs, trailing tabs, both tabs, original input exceeding 8 KiB and original input exceeding 2,000 characters are denied before any provider attempt, budget window/reservation, transmission, attempted/received inference audit or tool request. A metadata-only blocked audit remains. A success regression verifies the exact original question in the synthetic outbound SDK body. Three additional regressions prove Phase 6 still normalizes the same inputs while Phase 7 rejects them.

## Reservation accounting

No accounting change was made. P7-PC1 section 6, items 4–6 explicitly requires full-envelope reservation of **552,816 micro-USD**, retained without refund even after errors. Section 7's pre-send authority recheck also explicitly retains reservation when no send occurs; the exact implementation handoff says to retain each reservation through its windows without refund. This includes a pre-transmission infrastructure failure after admission. `not_sent` classification does not authorize a refund. Existing regression `real transport is denied in tests even with synthetic full approvals and a key reference` verifies failed/not_sent classification, no circuit opening and all five retained window reservations. It passed again. Pre-admission disclosure failures have zero reservations, separately verified by the new tests.

## Files changed

- `app/Fiscal/AssistantProviderTransport.php`: trusted CLI selection and offline bounded probe before the unchanged DNS subprocess.
- `config/assistant.php`: null protected CLI-path override; all activation defaults unchanged.
- `app/Fiscal/AssistantInput.php`: private original question and validated provider accessor; Phase 6 normalization retained.
- `app/Fiscal/ProviderIntentInput.php`: use original-question policy result.
- `tests/Feature/AssistantProviderCliTest.php`: 11 new offline subprocess cases covering FPM selection, relocation, runtime verification, missing CLI, invalid overrides and expired deadline.
- `tests/Feature/AssistantProviderExecutionTest.php`: six new HTTP pipeline cases.
- `tests/Feature/ProviderIntentInputTest.php`: three new Phase 6/7 normalization compatibility cases.
- This report and `docs/phase-7-remediation-evidence.json`.

No migrations, dependencies, routes, UI, capabilities, ledger, permission, acknowledgement or fiscal behavior changed. Twenty regression cases were added; no existing assertions were removed or weakened.

## Validation actually executed

Exact commands, exit statuses, structured results and log hashes are in the accompanying evidence JSON. Overlapping runs must not be summed as unique tests.

| Gate                                                                       | Fresh result                                                                                                 |
| -------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| Targeted CLI/execution/input suites                                        | Exit 0; 85 passed, 568 assertions                                                                            |
| Focused Phase 7 security/protocol/quarantine/audit plus Phase 6 foundation | Exit 0; 352 passed, 1,941 assertions                                                                         |
| PostgreSQL provider concurrency/migration                                  | Exit 0; 18 passed, 261 assertions                                                                            |
| Assistant UI                                                               | Exit 0; 12 passed                                                                                            |
| `npm run types:check`                                                      | Exit 0; only existing npm configuration warnings                                                             |
| PHPStan                                                                    | Exit 1; exactly the same 21 diagnostic records as the original implementation evidence, zero new diagnostics |
| Pint dirty and explicit seven-file formatting                              | Both exit 0; no unrelated file changed                                                                       |
| PHP syntax checks on all seven changed PHP files                           | All exit 0                                                                                                   |
| New report/evidence formatting                                             | Prettier write/check recorded in evidence                                                                    |

The first PostgreSQL attempt was blocked by sandbox localhost networking (18 setup errors, exit 2). The authorized rerun used only the existing isolated `facturac_test_phase7_concurrency` database on localhost:55439 and passed. No production or local application database was migrated. One evidence-parser attempt expected standard PHPStan JSON; the installed wrapper emits agent JSON. Reading its actual `error_details` confirmed an exact baseline match; no PHPStan suppression or code change was made to accommodate debt.

The repository-wide SQLite run (previously 2,109 passed / 314 skipped), full PostgreSQL integration/concurrency matrix and customer-search plan evidence were **not rerun** for this narrow correction. Their original reports/evidence are preserved historical evidence, not fresh validation. The broader fresh gate here is the 352-case Phase 6/7 run plus the required provider PostgreSQL suite. Repository-wide ESLint and existing resource-format debt were not repaired or rerun: no JavaScript/Vue file changed. Existing 9,719 ESLint errors and four resource-format failures remain attributed to their original evidence, not to this remediation.

## Security invariants and limits

- Tool results and stored-record sentinels remain absent from the single provider request; the deterministic local-facts regression passed. No new inference path or result feedback was introduced.
- Phase 6 local answers and normalization remain authoritative; the full foundation suite passed.
- Versioned user/workspace acknowledgement, withdrawal, fresh membership and tenant approvals remain enforced. Their execution/adversarial tests passed; none of their implementation files changed.
- PostgreSQL independent-process budget races, unique admission, finalization, rollback, restart and migration tests passed. Accounting remains durable, reserve-only and idempotent.
- Default provider enablement and egress remain false, all budgets zero and approval/secret references null. The new CLI-path default is null and grants no authority. Global SDK quarantine remains enforced.
- Audit remains metadata-only. Rejected original questions create no attempted inference or financial reservation; no original text is added to persistence.
- No real credentials were added or read and no live provider request occurred. All provider responses were synthetic; the new native subprocess probes were offline and did not resolve/contact the provider.
- All pre-existing governing documents and prior evidence files are unchanged. A starting repository hash inventory verifies that only the six intended existing files changed, with one new test and two new documentation artifacts.
- Privacy/legal, provider/account, retention, processing geography, notice, credential, operational and live rehearsal approvals remain separate unresolved activation gates. Actual deployment FPM/CLI compatibility still requires operator verification before activation.

## Independent re-review handoff

> Perform the independent Phase 7 remediation re-review only. Read the governing Phase 7 security/privacy documents, original implementation report/evidence, this remediation report and its evidence. Inspect the actual seven changed PHP files. Verify that the real offline executable-selection/probe boundary handles FPM safely, never searches PATH or accepts user-controlled executable/arguments, and preserves the original DNS/SSRF/TLS/deadline restrictions. Verify original request text reaches disclosure validation before normalization can erase forbidden characters or defeat original limits, while Phase 6 behavior remains intact. Challenge the new HTTP/subprocess regressions, retained-reservation rationale, hash preservation and fresh validation claims. Do not mistake historical full-suite evidence for a new run. Do not modify code, activate inference or proceed to another phase. Return an independent security verdict and any remaining findings; implementation completion does not grant production activation.

PHASE 7 REMEDIATION COMPLETE — READY FOR INDEPENDENT RE-REVIEW
