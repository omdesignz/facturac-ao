# AGT Homologation and Certification Plan

## Objective

Demonstrate that each supported software release produces authentic, complete, immutable, recoverable, and AGT-compatible fiscal records across normal, invalid, retry, correction, and contingency paths.

## Environments

| Environment | Purpose | Data and key rule |
| --- | --- | --- |
| Local contract simulator | Deterministic development and failure injection | Synthetic identities and generated test keys only |
| CI contract suite | Regression against frozen request/response fixtures | Encrypted CI secrets only for tests explicitly requiring them |
| AGT homologation | End-to-end official verification | Alias the five supplied identities as `HML-T01`…`HML-T05`; vault-only private keys |
| Staging | Production-like infrastructure and release rehearsal | Synthetic or explicitly authorised pilot data; production keys prohibited |
| Production | Certified taxpayer operations | Production vault/KMS, least privilege, monitored signing and evidence retention |

## Test identity allocation

The source workbook's actual NIFs and PEM blocks are not reproduced here.

| Alias | Primary use |
| --- | --- |
| HML-T01 | Normal invoice, receipt, series, query, and confirmation path |
| HML-T02 | Mixed and foreign-currency taxes, exemptions, and withholding |
| HML-T03 | Corrections, credit/debit notes, acknowledgements, and invalid states |
| HML-T04 | Contingency, delayed delivery, retry, and series exhaustion |
| HML-T05 | Key rotation/revocation, multi-establishment, and isolation verification |

## Evidence bundle structure

Every certification scenario receives an ID such as `CERT-AGT-REG-001` and stores:

1. Requirement IDs from `compliance-matrix.md`.
2. Software release and schema adapter version.
3. Environment and anonymised identity alias.
4. Input fixture and canonical signable JSON.
5. SHA-256 hashes of canonical bytes, JWS, request body, response body, and rendered document.
6. Redacted HTTP request/response with timestamps and correlation identifiers.
7. `submissionUUID`, `requestID`, and final per-document result.
8. PDF/QR or SAF-T validation report where applicable.
9. Automated test result and reviewer approval.

Private keys, Basic Authentication values, session cookies, and unredacted personal data are excluded.

## Test suites

### A. Contract and signatures

- Golden canonical JSON and JWS for software, document, and request signatures.
- Unicode Portuguese data, decimal boundaries, null/omitted fields, and array ordering.
- Invalid algorithm, wrong key, revoked key, undersized key, changed byte, and bad Base64URL.
- Exact schema validation for each operation and active version.

### B. Series and numbering

- Request/list normal and contingency series.
- Current/next-year boundary and authorised quantity.
- Atomic concurrent number allocation with no duplicates.
- Unused, active, used, finished, exhausted, and invalid transitions.
- Cross-entity, cross-establishment, and cross-document-type isolation.

### C. Fiscal documents

- One scenario for every certified document type and status.
- Domestic business, final consumer, foreign customer, and self-billing applicability.
- IVA normal/reduced/intermediate/exempt/non-subject, IS, IEC, discounts, and withholding.
- AOA and foreign currency, Portuguese amount in words, and cent rounding.
- Reference documents, partial payments, receipts, credit/debit notes, and buyer acknowledgement.
- Model A4 rendering, copy wording, page count, QR decode, and kiosk URL.

### D. Asynchronous integration

- Accepted registration and later valid result.
- Mixed batch, all invalid, pending, premature/repetitive, cancelled, and penalised outcomes.
- Timeout before response, timeout after AGT receives the request, 429, 5xx, and malformed response.
- Idempotent identical replay and prohibited UUID reuse with different bytes.
- Batch split at 30 documents and configured byte ceiling.
- List/consult reconciliation and received-document confirm/reject.

### E. Contingency and recovery

- AGT outage while SaaS is healthy.
- Contingency declaration, wording, queue durability, and later submission.
- Service recovery during concurrent issuing.
- Queue replay after process crash.
- Series exhaustion during outage.
- Restore from backup without repeating or losing fiscal numbers.

### F. SAF-T and statutory exports

- XSD validation for every supported schema/file class.
- Source-ledger totals and sequence completeness.
- Missing/duplicate records, invalid master reference, and wrong period.
- Inventory snapshot and accounting-import reconciliation.
- Export hash, manifest, download, and submission evidence.

### G. SaaS security and operations

- Tenant and legal-entity isolation at route, policy, query, cache, queue, export, and object-storage layers.
- MFA and step-up controls for fiscal-risk operations.
- Redaction of canary secrets from logs, exceptions, traces, notifications, and backups.
- Key rotation/revocation and access-review exercises.
- Database point-in-time recovery, object restore, and readable archive reconstruction.
- Accessibility, mobile, dark-mode, and performance verification.

## Required release gates

| Gate | Owner | Evidence |
| --- | --- | --- |
| Compliance interpretation approved | Tax/compliance lead | Signed matrix and AGT clarification responses |
| Threat model approved | Security lead | Key custody, tenant isolation, offline, and support-access review |
| Contract suite green | Integration lead | Simulator and homologation reports |
| Fiscal calculation suite green | Domain lead | Tax/rounding/numbering/property-test report |
| SAF-T suite green | Reporting lead | XSD and reconciliation reports |
| Restore drill passed | Operations lead | Measured RPO/RTO and reconstruction evidence |
| Accessibility and UX accepted | Product/QA | WCAG report and pilot observation notes |
| Independent security review passed | External reviewer | Penetration-test closure report |
| AGT approval received | Compliance lead | Certification/homologation reference |
| Pilot sign-off received | Product owner | Results from microbusiness, SME/accountant, and multi-branch pilots |

## Release and recertification control

- Each production build exposes an immutable software version and certification reference.
- Tax policy, schema adapter, signable field set, PDF legal content, QR generation, numbering, key handling, and contingency changes require compliance review.
- The release pipeline blocks deployment if an accepted golden payload, PDF, QR, or SAF-T fixture changes without an approved evidence update.
- A rollback never restores an application version incapable of interpreting already-issued records.
- AGT responses and certificates are attached to the release bundle and cross-referenced from the source register.

## Phase 0 completion versus certification

Phase 0 establishes the test system and evidence model. It does not claim that the software is AGT certified. Certification begins only after the clarification request is answered and the first vertical slice is accepted in homologation.

