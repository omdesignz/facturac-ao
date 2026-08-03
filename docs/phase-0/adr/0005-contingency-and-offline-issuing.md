# ADR 0005: Contingency and Offline Issuing

- Status: Accepted with a planned extension
- Date: 2026-08-02

## Context

An AGT outage while the hosted SaaS is reachable differs from a branch losing all connectivity or power. The latter requires local number allocation and signing and has materially greater key and reconciliation risk.

## Decision

The initial certified product supports AGT-platform contingency from the hosted service using authorised contingency series, mandatory wording, durable queueing, incident evidence, and later regularisation.

Full branch-offline issuing is a separate edge-issuer workstream. It requires AGT architecture approval, pre-provisioned ranges, encrypted local storage, a protected signer, conflict-proof reconciliation, and field certification. Taxpayer private keys will not be placed in ordinary browser storage.

## Consequences

- The first release can remain compliant during an AGT API outage.
- The interface clearly distinguishes AGT contingency from the user's own connectivity problem.
- PWA caching alone is not presented as certified offline fiscal issuing.

