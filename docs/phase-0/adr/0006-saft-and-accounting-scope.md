# ADR 0006: SAF-T and Accounting Scope

- Status: Accepted
- Date: 2026-08-02

## Context

Sales data can produce invoicing SAF-T, and stock records can produce inventory data. Accounting SAF-T requires a chart of accounts, journals, periods, balances, and accounting movements that an invoicing ledger alone does not contain.

## Decision

The initial product generates and validates supported invoicing, acquisitions, and inventory exports from its own ledgers. Accounting SAF-T is produced only after importing and reconciling complete ledger data from an accounting system.

A native general ledger is a separately estimated product phase. Direct submission remains disabled until AGT supplies and approves a supported submission API. Until then the product creates a validated export bundle and records external submission evidence.

## Consequences

- Marketing and UI never claim that invoice data alone creates accounting SAF-T.
- Schema files are checksum-pinned, versioned compliance assets.
- Compliance calendars are data with effective dates because AGT can extend deadlines.

