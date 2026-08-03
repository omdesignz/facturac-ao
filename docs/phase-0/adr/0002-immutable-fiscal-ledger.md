# ADR 0002: Immutable Fiscal Ledger and Number Allocation

- Status: Accepted
- Date: 2026-08-02

## Context

Issued documents must retain integrity, permit reconstruction, preserve signatures, and remain sequential under concurrency. A mutable CRUD record cannot provide sufficient evidence.

## Decision

Drafts remain editable. Final issue occurs in one database transaction that locks the selected series, validates readiness, allocates the next number, freezes party/line/tax/software snapshots, calculates hashes, creates the signed-payload work item, and records a transactional outbox event.

After issue, business fields are immutable. Subsequent information is appended as status events, transport attempts, payments, acknowledgements, corrections, or linked fiscal documents. Rendered documents and transport bodies are content-addressed and versioned.

Money uses integer minor units for ordinary AOA amounts and explicit decimal value objects where AGT tax/foreign-currency precision exceeds minor units. Floating-point arithmetic is prohibited in the fiscal domain.

## Consequences

- Corrections never overwrite the original evidence.
- Numbering remains local, deterministic, and safe under concurrent issuing.
- Outbox dispatch may fail or retry without rolling back the legal issue event.
- Data-retention and storage growth are planned as core product costs.

