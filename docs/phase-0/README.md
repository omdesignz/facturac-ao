# Phase 0 — Compliance and Architecture Baseline

Status: implemented on 2026-08-02

This directory is the controlled baseline for the AGT electronic invoicing programme. It records what the team believes the product must do, which points still require written confirmation, and which technical decisions may safely guide implementation.

## Deliverables

- [Source register](source-register.md): controlled inputs, versions, checksums, and confidentiality rules.
- [Compliance matrix](compliance-matrix.md): requirement-to-control-to-test traceability.
- [AGT clarification request](agt-clarification-request.md): a ready-to-send question pack for the Partner Portal team.
- [Certification plan](certification-plan.md): environments, evidence, test suites, and release gates.
- [Prototype coverage](prototype-coverage.md): Phase 0 journeys and Tailwind Plus provenance.
- [Architecture decisions](adr/): accepted and provisional architectural decisions.

## Status vocabulary

| Status | Meaning |
| --- | --- |
| Confirmed | Supported by the reviewed AGT or legal material. |
| Provisional | Safe engineering direction, but dependent on an external confirmation. |
| Open AGT | Written clarification is required before production certification. |
| Planned | Accepted scope for a later delivery phase. |
| Prototype | Represented in the Phase 0 interface, without production persistence. |

## Change control

1. A regulatory or technical source change is first recorded in `source-register.md` with its retrieval date and checksum where possible.
2. Affected matrix rows retain their identifiers and are updated with a short dated note.
3. Material architectural changes require a new ADR; accepted ADRs are not silently rewritten.
4. Every certified release receives a frozen copy of this directory in its release evidence bundle.
5. Private keys, credentials, taxpayer data, and unredacted AGT payloads are never stored in this directory.

## Phase 0 exit assessment

| Gate | Result |
| --- | --- |
| Authoritative source register created | Complete |
| Requirements mapped to planned controls | Complete |
| Documentation inconsistencies isolated | Complete |
| Clarification dossier ready for AGT | Complete; awaiting dispatch and response |
| Architecture decisions recorded | Complete, with explicit provisional decisions |
| Five core journeys prototyped | Complete in the Inertia application |
| Production certification | Not part of Phase 0 |

