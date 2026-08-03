# ADR 0003: Versioned AGT Gateway and Asynchronous Outbox

- Status: Accepted
- Date: 2026-08-02

## Context

AGT exposes asynchronous fiscal services and the reviewed examples contain schema and endpoint inconsistencies. Coupling domain models directly to one example would make regulatory changes unsafe.

## Decision

Define an internal `AgtGateway` contract with operation-specific immutable DTOs. Implement REST/JSON as the primary adapter because it is documented and fits Laravel's HTTP client. Keep SOAP outside the domain and add an adapter only if certification requires it.

Each supported AGT schema is a separate mapper and validator. The outbox stores an immutable submission UUID and payload version. Queue workers batch within contract limits, sign, dispatch, retain redacted evidence, and poll `obterEstado`. AGT receipt, processing, valid, invalid, cancelled, and contingency are distinct states.

## Consequences

- A schema upgrade can run beside an older version during transition.
- Retries reuse the original submission identity and bytes.
- Callback support can be added behind the gateway without changing the fiscal domain.
- Production activation of a schema requires golden fixtures and homologation evidence.

