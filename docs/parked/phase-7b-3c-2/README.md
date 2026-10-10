# Phase 7B.3c-2 — parked work (2026-10-10)

Customer-managed AI keys in the assistant gateway. The implementation run was stopped part-way at the project owner's request because it is not part of the product master plan. Nothing in this folder is loaded, migrated or tested by the application.

## What is here

| Path | Content | State when parked |
| --- | --- | --- |
| `new/database/migrations/`, `new/app/Fiscal/TenantAiInvocationSchema.php`, `new/tests/Unit/AiInvocationSchemaContractTest.php` | The two reviewed `invocation_authority_v1` migrations, their SQL listings and the hash contract test | Complete. With them installed, the three frozen 3c-1 PostgreSQL test files and five other PostgreSQL test files passed in a throwaway database. Those runs used PHP 8.5 and were not repeated under the project's PHP 8.4. |
| `new/app/Fiscal/` (13 other files), `new/app/Exceptions/` | Send seam, routing gateway, tool ceiling, invocation session, permit, policy resolver, admission, secret consumer, recovery | Written, **never executed and untested**. Not reviewed. |
| `existing-files.patch` | The edits this run made to 13 existing files (bridge, transport, invocation, permit, plan, provider input, interaction, legacy gateway, service provider, recovery command, `config/tenant_ai.php`, two tests) | With the patch applied, 524 existing SQLite assistant and tenant AI tests and the two contract tests passed (PHP 8.4). The customer route itself had no tests. |

## To resume

1. `git apply docs/parked/phase-7b-3c-2/existing-files.patch` and move `new/*` back to the repository root.
2. Follow [the design](../../phase-7b-3c-2-gateway-consumption-design.md) §13–§14: none of the 34 acceptance criteria has a test yet.
3. Use Herd's PHP 8.4 binary for every gate, as the 3c-1 checkpoint did.

## Open points found during the run

- Three accepted tests pin the pre-3c-2 wiring: `AiGatewayFacadeTest` expects the container to return `LegacyAssistantAiGateway`, and `TenantAiStorageTest` and `TenantAiLifecycleTest` require that `AppServiceProvider` and six gateway files do not contain the string `TenantAi`. The design's rebinding conflicts with them. The parked code names the router `RoutedAssistantAiGateway`, which keeps the two string scans passing, but they then no longer prove that no gateway binding exists. A reviewer should decide how those tests change.
- The send seam needed two methods beyond the design's interface (`permissions()` and `gate()`) to keep the legacy call order identical.
- `AssistantProviderTransport::wire()` is gated by the legacy egress switch, so the customer route would also need `assistant.provider.egress_enabled`.
- Any unknown-usage outcome trips the tenant's account circuit, and no operator path exists yet to clear it.
