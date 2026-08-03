# AGT Compliance Traceability Matrix

Baseline: 2026-08-02

This matrix is an engineering control document, not a legal opinion. `Open AGT` rows block production certification unless AGT accepts the implemented interpretation during homologation.

## Transport, identity, and signatures

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| AGT-AUTH-001 | Protected service calls use credentials issued for the producer/taxpayer context. | SRC-001, SRC-011 | Environment-scoped encrypted credentials; Laravel HTTP client auth middleware; headers always redacted. | Contract test with HTTP fake plus homologation request transcript. | Confirmed |
| AGT-AUTH-002 | Homologation and production endpoints and secrets remain isolated. | SRC-001, SRC-015 | Separate connection profiles, vault paths, queues, storage prefixes, and explicit environment banner. | Configuration test and deployment evidence. | Confirmed |
| AGT-JWS-001 | Software information is signed in `jwsSoftwareSignature`. | SRC-001, SRC-012 | Versioned software-info DTO and producer-key signer. | Golden JWS verified with the registered public key. | Confirmed |
| AGT-JWS-002 | Each fiscal document is signed in `jwsDocumentSignature` with the taxpayer key. | SRC-001, SRC-012 | Immutable signable-document DTO; per-legal-entity key reference. | Golden JWS and mutation-negative tests. | Confirmed |
| AGT-JWS-003 | Operations requiring a request signature use `jwsSignature`. | SRC-001, SRC-012 | Per-operation signable-payload definitions. | Contract fixtures for every service operation. | Confirmed |
| AGT-JWS-004 | JWS uses RS256 and RSA keys of at least 2048 bits. | SRC-012, SRC-013 | Native OpenSSL signer rejects wrong algorithm, key type, and undersized keys. | Unit tests with 1024/2048/4096-bit fixtures. | Confirmed |
| AGT-JWS-005 | Payloads use Base64URL without padding. | SRC-012 | One audited Base64URL codec shared by all signers. | RFC vectors and AGT golden fixtures. | Confirmed |
| AGT-JWS-006 | Signed JSON must be deterministic. | SRC-012 | Internal canonical-JSON value serializer; sign only explicit DTO fields. | Cross-runtime fixture comparison. | Open AGT |
| AGT-KEY-001 | Producer software key pair is generated locally; the private key does not leave the producer environment. | SRC-013 | Generate in KMS/HSM or controlled release ceremony; register only public key. | Key-ceremony record and public-key fingerprint. | Confirmed |
| AGT-KEY-002 | Taxpayer keys are issued or made available by AGT. | SRC-013 | Per-entity key-import ceremony; persist vault reference and fingerprint only. | Import audit trail and signature verification. | Confirmed |
| AGT-KEY-003 | Compromised keys can be revoked and rotated without invalidating historical evidence. | SRC-013 | Effective-dated key versions; historical fingerprint and JWS preserved; new signing blocked on revoked key. | Rotation/revocation scenario test. | Confirmed |
| AGT-KEY-004 | SaaS custody of taxpayer private keys is legally and technically acceptable. | SRC-013 | KMS-wrapped custodial signing when approved; local signer fallback otherwise. | Written AGT response and threat-model sign-off. | Open AGT |

## Submission lifecycle and services

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| AGT-API-001 | `registarFactura` receives fiscal documents and returns a `requestID`. | SRC-001, SRC-015 | Versioned REST adapter and transactional outbox. | Homologation registration transcript. | Confirmed |
| AGT-API-002 | Receipt of a `requestID` is not final document validation. | SRC-010, SRC-015 | Separate `received` and `valid/invalid` states; UI copy prohibits premature success. | State-machine and browser tests. | Confirmed |
| AGT-API-003 | Final status is obtained through `obterEstado`. | SRC-001, SRC-010 | Scheduled polling with backoff, jitter, age limits, and manual recovery. | Processing, mixed, invalid, premature, pending, and cancelled fixtures. | Confirmed |
| AGT-API-004 | A registration contains no more than 30 documents. | SRC-015 | Deterministic batch packer with a hard maximum. | Boundary tests for 0, 1, 30, and 31 documents. | Confirmed |
| AGT-API-005 | Message size remains within the documented service limit. | SRC-001 | Measure encoded body before dispatch and split batches below a configurable ceiling. | 750 KB boundary fixtures. | Confirmed |
| AGT-API-006 | Every submission UUID is unique and safe to retry. | SRC-001, SRC-015 | UUIDv4 generated once in the outbox; unique database constraint; immutable retry identity. | Duplicate and replay tests. | Confirmed |
| AGT-API-007 | `numberOfEntries` equals the number of documents sent. | SRC-001, SRC-015 | Derive from the immutable batch collection, never from user input. | Payload-schema test. | Confirmed |
| AGT-API-008 | The client handles validation success, partial success, in-progress, premature/repetitive, and cancellation results. | SRC-001 | Explicit result enum and per-document result records. | Complete response-catalogue dataset. | Confirmed |
| AGT-API-009 | The client handles malformed requests, NIF mismatch, authentication failures, throttling, and service failures. | SRC-001 and live service pages | Error normaliser, retry policy, dead-letter queue, and actionable Portuguese messages. | HTTP 400/401/403/422/429/5xx tests. | Confirmed |
| AGT-API-010 | Invoice lists and details can be reconciled against AGT. | SRC-001 | `listarFacturas` and `consultarFactura` read adapters plus reconciliation job. | Period, pagination, missing-document, and mismatch tests. | Confirmed |
| AGT-API-011 | Series can be requested and listed by taxpayer, year, type, establishment, and contingency class. | SRC-001, SRC-016 | Series aggregate and AGT series adapter; capacity warnings. | Homologation series lifecycle. | Confirmed |
| AGT-API-012 | Received documents can be confirmed or rejected. | SRC-001, SRC-017 | Purchases inbox and maker/checker `validarDocumento` action. | C/R response and VAT allocation tests. | Confirmed |
| AGT-API-013 | Polling interval, rate ceilings, and retry semantics are acceptable to AGT. | SRC-001, SRC-010 | Remotely configurable transport policy with conservative defaults. | Written response and load evidence. | Open AGT |
| AGT-API-014 | Callback delivery is not required by the currently certified contract. | SRC-010 | Polling is active; callback receiver remains feature-flagged and disabled. | Contract-scope approval. | Provisional |
| AGT-API-015 | The canonical homologation and production endpoint list is known. | SRC-001 and live service pages | One endpoint manifest per schema version. | AGT-signed endpoint matrix. | Open AGT |

## Fiscal document content and calculation

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| FIS-DOC-001 | Documents have sequential, chronological numbers by type, year, and identified series. | SRC-018 art. 10 | Atomic series row lock; number assigned only during final issue transaction. | Concurrency/property tests and sequence-gap report. | Confirmed |
| FIS-DOC-002 | Issued fiscal records cannot be altered or deleted. | SRC-018 arts. 19, 22, 26–27 | Append-only issued snapshot; corrections are linked fiscal events/documents. | Database permissions, mutation-negative tests, and audit evidence. | Confirmed |
| FIS-DOC-003 | Supplier and applicable buyer name, NIF, and address appear on the document. | SRC-018 art. 10; SRC-001 | Mandatory party snapshot captured at issue time. | Document-type validation datasets and PDF assertion. | Confirmed |
| FIS-DOC-004 | Goods/services, quantities or reference units, unit price, and total are shown. | SRC-018 art. 10; SRC-001 | Immutable line snapshots with code, description, unit, quantity, unit price, and amount. | Calculation and PDF tests. | Confirmed |
| FIS-DOC-005 | Prices and totals are expressed in AOA and the total is written in words where required. | SRC-018 art. 10 | AOA display plus tested Portuguese number-to-words service; foreign-trade exception policy. | Language golden fixtures through high-value boundaries. | Confirmed |
| FIS-DOC-006 | Applicable tax rate and amount are identified. | SRC-018 art. 10; SRC-001 | Effective-dated tax profiles and line-level tax snapshots. | IVA, IS, IEC, and non-subject datasets. | Confirmed |
| FIS-DOC-007 | Non-liquidation includes the exemption reason and legal basis. | SRC-018 art. 10; SRC-001 annexes | Exemption code registry with effective dates; mandatory when ISE/NS applies. | Required/invalid exemption tests and PDF assertion. | Confirmed |
| FIS-DOC-008 | Goods/services under different rates are separately described. | SRC-018 art. 10 | Each line owns its tax profile; totals aggregate by tax type/code/rate. | Mixed-rate document fixture. | Confirmed |
| FIS-DOC-009 | Transaction date, time, and place plus issue date are retained where applicable. | SRC-018 art. 10 | Luanda-aware timestamps, UTC transport timestamps, establishment snapshot. | Time-zone, DST-independent, and PDF tests. | Confirmed |
| FIS-DOC-010 | Fiscal documents are written in Portuguese. | SRC-018 art. 10 | `pt-AO` is the legal document locale; translations do not alter archived PDFs. | Copy review and PDF fixture. | Confirmed |
| FIS-DOC-011 | Validated software identification, certification number, version/hash information are present. | SRC-018 art. 10; SRC-001 | Effective-dated software-release record copied into every issue snapshot and payload. | Release fixture and PDF assertion. | Confirmed |
| FIS-DOC-012 | Document status and document type use AGT-authorised codes. | SRC-001 and live service pages | Versioned registries loaded from the approved contract manifest. | One fixture per code and rejection of unknown codes. | Open AGT |
| FIS-DOC-013 | Domestic unknown-consumer treatment follows the authorised NIF rule. | SRC-001 | Policy-owned placeholder, available only for allowed document/customer contexts. | Eligibility and rejection tests. | Confirmed |
| FIS-DOC-014 | Debit and credit amount fields obey their mutual-exclusion rule. | SRC-001 | Signable DTO validation rejects both populated or both invalid for the document type. | Dataset covering debit/credit combinations. | Confirmed |
| FIS-DOC-015 | Credit notes reference the corrected source document and reason. | SRC-001; SRC-018 art. 8 | Mandatory immutable source reference and correction reason. | Credit-note feature test and payload fixture. | Confirmed |
| FIS-DOC-016 | The buyer's acknowledgement of a correction/annulment is preserved. | SRC-018 arts. 8 and 21 | Acknowledgement workflow, channel, timestamp, actor, and evidence attachment. | Electronic and offline-evidence scenarios. | Confirmed |
| FIS-DOC-017 | Receipts are issued for full or partial payment unless the source type incorporates the receipt. | SRC-018 art. 6; SRC-001 | Payment allocations generate the appropriate receipt/source references. | Partial, full, overpayment, and invoice-receipt datasets. | Confirmed |
| FIS-DOC-018 | Foreign currency amount and exchange rate reconcile to document totals. | SRC-001 | Store entered foreign values, effective rate, and AOA fiscal totals as immutable decimals. | FX rounding and mismatch tests. | Confirmed |
| FIS-DOC-019 | Withholding types and amounts follow the authorised catalogue. | SRC-001 | Effective-dated withholding registry and calculated snapshot. | IRT, II, IS, IVA, IP, IAC, other-code fixtures. | Confirmed |
| FIS-DOC-020 | Tax contribution uses AGT's prescribed cent-rounding behaviour. | SRC-001 | Dedicated decimal rounding policy, distinct from presentation rounding. | Published examples plus boundary/property tests. | Confirmed |

## Contingency, integrity, and retention

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| OPS-CON-001 | When AGT is unreachable but the SaaS works, authorised contingency issuing remains possible. | SRC-018 art. 18; SRC-001 | Pre-authorised contingency series, outage declaration, durable queue, and later regularisation. | Fault-injection scenario and homologation evidence. | Confirmed |
| OPS-CON-002 | Contingency documents show the required pending-authorisation wording. | SRC-018 art. 18 | Template assertion tied to the contingency state. | PDF visual/text test. | Confirmed |
| OPS-CON-003 | AGT is immediately informed when the taxpayer operates in contingency. | SRC-018 art. 18 | Incident workflow and evidence capture; automate only when AGT supplies an interface. | Written notification procedure and test drill. | Open AGT |
| OPS-CON-004 | Full customer-internet outage is addressed without exposing keys or corrupting sequences. | SRC-018 art. 18 | Separately certified branch edge issuer with pre-provisioned range and encrypted local queue. | AGT architecture approval and field pilot. | Planned |
| OPS-INT-001 | Authenticity, integrity, legibility, and non-repudiation persist throughout retention. | SRC-018 arts. 19, 26–27 | Signed payload, content hash, immutable object version, readable PDF/XML, and verification metadata. | Tamper and long-term retrieval tests. | Confirmed |
| OPS-INT-002 | Access control and unauthorised-change detection cover fiscal and accounting data. | SRC-018 art. 22 | Tenant policies, least privilege, maker/checker, audit events, and integrity alerts. | Authorization matrix and tamper simulation. | Confirmed |
| OPS-INT-003 | The system preserves enough evidence to reconstruct fiscal processing. | SRC-018 art. 22 | Append-only events, payload versions, calculation inputs, software version, and transport history. | Reconstruction exercise from archived evidence. | Confirmed |
| OPS-INT-004 | Technical documentation, lifecycle, and data dictionary are available per software version. | SRC-018 art. 22 | Versioned docs, migrations, schema dictionary, ADRs, release notes, and certification bundle. | Release-gate checklist. | Confirmed |
| OPS-INT-005 | AGT can receive readable, normalised, exact copies of archived data. | SRC-018 arts. 22–23 | Scoped export jobs with hashes, manifests, access audit, and legal approval. | Inspection-response exercise. | Confirmed |
| OPS-RET-001 | Fiscal records and processing evidence are archived for the legally required period. | SRC-018 arts. 26–27 | Retention policy configured by record class; legal hold; deletion blocked before expiry. | Retention policy audit. | Confirmed |
| OPS-RET-002 | Backup copies are immediately retrievable and incidents are recoverable. | SRC-018 arts. 22, 26–27 | Encrypted backups, database PITR, object versioning, restore drills, and documented RPO/RTO. | Quarterly restore evidence. | Confirmed |
| OPS-RET-003 | The system blocks unsafe operation when integrity is compromised. | SRC-018 art. 22 | Fiscal circuit breaker per entity/series and controlled technical-release procedure. | Compromise simulation and recovery report. | Confirmed |
| OPS-COM-001 | Activity and global sales are communicated on the legally required cadence. | SRC-018 art. 17 | Compliance scheduler and reconciliation export/service adapter. | Period-boundary fixtures and submission evidence. | Open AGT |
| OPS-COM-002 | New issuing is blocked after communication failure exceeds the legal threshold. | SRC-018 art. 17 | Per-entity last-success clock, escalating warnings, and hard fiscal gate after 60 days. | Time-travel feature test. | Confirmed |

## Series, QR, SAF-T, and statutory communications

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| REP-SER-001 | Used and unused series are associated with the correct entity and establishment. | SRC-018 art. 24; SRC-001 | Series registry keyed by entity, establishment, year, document type, and contingency class. | Isolation and listing tests. | Confirmed |
| REP-SER-002 | Series requests respect allowed years, quantities, and status transitions. | SRC-001 | Versioned series rules and explicit A/U/F lifecycle. | Boundary and homologation tests. | Confirmed |
| REP-QR-001 | QR is Model 2, version 4, 33×33, correction M, Byte mode, UTF-8. | SRC-014 | Deterministic QR renderer configuration. | Automated QR metadata/decode test. | Confirmed |
| REP-QR-002 | QR encodes the prescribed verification URL with emitter and document number. | SRC-014 | URI builder with `%20` space encoding and environment-specific base URL. | Exact URL golden fixtures. | Confirmed |
| REP-QR-003 | Printed QR is PNG 350×350 and any AGT mark occupies less than 20%. | SRC-014 | Controlled rendering asset and print-template dimensions. | Image inspection and decode at print resolutions. | Confirmed |
| REP-SAFT-001 | General and simplified IVA taxpayers communicate covered documents in SAF-T. | SRC-018 art. 25 | Periodic exporter reconciled to the immutable fiscal ledger. | XSD, business-rule, total, and completeness tests. | Confirmed |
| REP-SAFT-002 | SAF-T exports validate against the approved Angolan XSD version. | SRC-019 and AGT notices | Checksum-pinned schema registry and no-network validation. | XSD validation report in every export bundle. | Provisional |
| REP-SAFT-003 | Inventory at the statutory reference date can be exported by the applicable deadline. | SRC-018 art. 24; SRC-021 | Inventory snapshot/export and remotely configurable calendar. | Inventory completeness and deadline tests. | Confirmed |
| REP-SAFT-004 | Accounting SAF-T contains header, master files, and accounting movements. | SRC-020 | Import accounting-ledger data in v1; native general ledger is separate scope. | XSD and trial-balance reconciliation. | Confirmed |
| REP-SAFT-005 | Direct SAF-T submission from the SaaS uses a supported AGT contract. | SRC-001, SRC-010 | Export and evidence workflow now; submission adapter remains disabled until contracted. | Written API contract and homologation suite. | Open AGT |
| REP-CAL-001 | Establishments, installed software, and series changes are communicated before affected issuing. | SRC-018 art. 24 | Readiness gate and compliance task/event register. | Onboarding and change-management feature tests. | Confirmed |
| REP-CAL-002 | Compliance dates and transitional rules can change without a software release. | SRC-020, SRC-021 | Signed, audited policy records with effective dates and tenant applicability. | Policy activation/rollback tests. | Confirmed |

## SaaS security and product controls

| ID | Requirement | Source | Planned control | Verification evidence | Status |
| --- | --- | --- | --- | --- | --- |
| SEC-TEN-001 | One tenant cannot read, modify, export, or sign another tenant's data. | Derived from legal integrity obligations | Explicit workspace/entity keys, policies, scoped bindings, database constraints, and isolation tests. | Cross-tenant authorization suite and penetration test. | Confirmed |
| SEC-IAM-001 | Privileged users use MFA and step-up authentication for fiscal-risk actions. | Risk control for SRC-013 and SRC-018 art. 22 | Fortify TOTP/recovery, enforced privileged roles, recent-password/MFA challenge. | Authentication and privileged-action tests. | Planned |
| SEC-AUD-001 | Sensitive actions produce an immutable, attributable audit event. | SRC-018 art. 22 | Append-only event with actor, tenant, IP/device, correlation ID, and object hashes. | Activity-log and tamper tests. | Planned |
| SEC-BKP-001 | Secrets are never present in plaintext logs, fixtures, exports, or backups. | SRC-002, SRC-013 | Vault references, hidden/encrypted casts, structured redaction, and secret-scanning CI. | Canary-secret tests and backup inspection. | Planned |
| UX-STATE-001 | The interface distinguishes draft, received, processing, valid, invalid, and contingency states. | SRC-010, SRC-001 | Shared status vocabulary, icon, text, colour, and timestamp. | Accessibility and browser assertions. | Prototype |
| UX-STATE-002 | Dark mode and tenant themes do not alter legal print output or state meaning. | Product requirement | Independent print template; status always includes text/icon; contrast tokens tested. | Visual regression and WCAG audit. | Prototype |

