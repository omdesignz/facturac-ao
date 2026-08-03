# ADR 0001: Tenant and Legal-Entity Boundaries

- Status: Accepted
- Date: 2026-08-02

## Context

A SaaS workspace may contain users, one or more taxpayer legal entities, and multiple establishments. Keys, series, document numbers, AGT states, exports, and archives are legally scoped to a taxpayer rather than merely to a subscription.

## Decision

Use the hierarchy `workspace → legal entity → establishment`. Production begins with a shared PostgreSQL database and explicit `workspace_id` and `legal_entity_id` ownership on tenant records. Fiscal records additionally carry `establishment_id` where relevant.

Authorization policies, scoped route bindings, query scopes, cache keys, jobs, object-storage paths, exports, and audit context must all carry the same boundary. Composite uniqueness and foreign-key constraints prevent cross-entity relationships. PostgreSQL row-level security is evaluated as defence in depth, not as a replacement for Laravel authorization.

## Consequences

- One subscription can later support several NIFs without merging their fiscal identity.
- Every new tenant model requires an isolation test.
- Cross-entity reporting is an explicitly authorised workspace aggregation over immutable entity data.
- Separate databases remain an enterprise migration option, not an initial dependency.

