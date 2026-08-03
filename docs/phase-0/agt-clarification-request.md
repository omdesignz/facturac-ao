# AGT Technical Clarification Request

**Subject:** Pedido de clarificação técnica — integração SaaS de Facturação Electrónica

**To:** Administração Geral Tributária / Equipa do Portal do Parceiro

**From:** Produtor do software facturac.ao

**Environment requested:** Homologação and production

**Reference material:** Partner Portal documentation consulted on 2026-08-02 and technical specification TA.020 v1.1.

## Purpose

We are implementing a multi-tenant electronic invoicing platform for Angolan taxpayers. Before freezing our homologation contract, we request written confirmation of the points below. The questions isolate differences found across the supplied PDF, live Partner Portal pages, and service examples.

No private key, taxpayer credential, or production taxpayer data is included in this request.

## 1. Contract and schema versioning

1. Which `schemaVersion` is currently authoritative for each homologation and production operation: `registarFactura`, `obterEstado`, `listarFacturas`, `consultarFactura`, `solicitarSerie`, `listarSeries`, and `validarDocumento`?
2. Is version `1.2` production-ready, and until what date will earlier versions remain accepted?
3. Can AGT provide the normative JSON Schema, OpenAPI specification, and SOAP WSDL/XSD files for every active version?
4. For each operation, is the submission identifier named `submissionUUID` or `submissionGUID`?
5. Is `signatureVersion` mandatory? If so, what values and transition rules are accepted?
6. Which property-name casing and unknown-property behaviour are enforced?
7. Are null, empty string, omitted property, empty array, and zero treated differently for optional fields?

## 2. Endpoint and transport manifest

8. Please provide the canonical homologation and production REST URLs for every operation. Some examples include `/sigt/fe/v1`, while related examples include `/sigt/fe/ws/v1`.
9. Which SOAP services remain supported, and is REST/JSON the preferred certification path?
10. Are Basic Authentication credentials issued per producer, per taxpayer, per software certificate, or per environment?
11. What connection timeout, request timeout, polling interval, and maximum retry policy does AGT recommend?
12. What rate limits apply per credential, NIF, IP, operation, and period?
13. Does HTTP 429 include `Retry-After`, and should an unchanged `submissionUUID` be reused after throttling or transport failure?
14. Is the documented 750 KB message size calculated before or after HTTP compression?
15. Is the maximum of 30 documents fixed for all active schema versions?

## 3. JWS and canonical JSON

16. Please provide normative golden input, canonical bytes, and JWS output for `jwsSoftwareSignature`, `jwsDocumentSignature`, and each `jwsSignature` payload.
17. Is object-key ordering lexicographic, schema-defined, insertion-preserving, or otherwise specified?
18. How must decimal numbers be serialised, including trailing zeros, exponent notation, negative zero, and values such as `23.10`?
19. How must Unicode, forward slashes, control characters, and non-ASCII Portuguese characters be escaped?
20. Are null or omitted optional properties included in the signed payload?
21. Is the protected header exactly `{"alg":"RS256","typ":"JWT"}`, or is `typ: JOSE` also accepted as shown by some examples?
22. Are additional protected-header fields such as `kid` permitted or required for key rotation?
23. Does AGT require RSA-2048 or recommend/require RSA-4096 for new producer registrations?

## 4. Key custody and rotation for SaaS

24. May a certified SaaS producer hold and use each taxpayer's AGT-issued private key on the taxpayer's behalf in a managed KMS/HSM?
25. If custodial signing is permitted, which contractual consent, audit, locality, encryption, and operator-access controls are required?
26. If custodial signing is not permitted, does AGT accept a local taxpayer signing agent that returns only the JWS to the SaaS?
27. Are taxpayer key pairs scoped to a NIF, establishment, software product, environment, or combination thereof?
28. How are key version, activation time, revocation time, and historical verification represented to the API?
29. Must a producer software public-key change trigger software recertification or only Portal registration?
30. What is the emergency process and expected propagation time after key compromise/revocation?

## 5. Fiscal document contract

31. Please provide the definitive document-type catalogue, descriptions, applicability, and effective dates.
32. Please provide the definitive `documentStatus` catalogue. Do correction and annulment states differ by document type?
33. What is the exact grammar for `documentNo`, including spaces, separators, AGT series code, year, establishment, and sequential number?
34. Must the AGT-issued series code be embedded unchanged in `documentNo`?
35. May number allocation occur while AGT is temporarily unreachable if a normal authorised series exists, or must a contingency series be used immediately?
36. What are the valid rules for a domestic anonymous/final consumer, including use of `999999999`?
37. Please confirm all allowed tax types, country/region codes, tax codes, exemption codes, and their effective dates.
38. Does the “round upward to the next cent” rule apply only to `taxContribution` or to other tax/document totals?
39. Please provide rounding examples for negative credit-note values, multiple taxes, foreign currency, discounts, and withholding.
40. For notes of credit, which source-document fields and acknowledgement evidence are required in the API?
41. Which fields are signed for each document type? Some examples omit `documentTotals` from the compact JWS payload despite the descriptive table including it.
42. Are debit and credit amount fields required with zero, or must the unused field be omitted?

## 6. Series and contingency

43. Please confirm the permitted request window for current-year and next-year series and the rule around 15 December.
44. What authorised quantity should a SaaS request, and may capacity be extended without creating a new series?
45. Please confirm all series statuses and legal transitions, including unused, active, used, finished, cancelled, and contingency states.
46. Which service or Portal procedure is used to inform AGT immediately that a taxpayer is operating in contingency?
47. Which outage evidence must be stored, and when must queued contingency documents be submitted?
48. What status and wording apply if AGT receives a document more than 24 hours after issue without an accepted contingency event?
49. Does AGT accept a branch-local offline issuer with pre-provisioned ranges? If so, please provide architecture and reconciliation requirements.

## 7. Asynchronous state, idempotency, and callbacks

50. What polling delay should follow result codes indicating premature/repetitive or in-progress processing?
51. For a mixed batch, may invalid documents be corrected and resubmitted separately while valid documents retain their original evidence?
52. What exact outcome is returned when an identical `submissionUUID` and payload are retried?
53. What outcome is returned when an existing UUID is reused with different bytes?
54. Is callback delivery available in either environment today? If yes, please provide registration, authentication/signature, retry, ordering, and replay rules.
55. How long are `requestID` results and invoice-query records retained online?

## 8. Received documents and validation

56. Which taxpayers and roles may call `listarFacturas`, `consultarFactura`, and `validarDocumento` for received documents?
57. Please confirm the complete received-document status catalogue and transitions.
58. For confirmation, are `deductibleVATPercentage` and `nonDeductibleAmount` mutually exclusive at request, document, tax, or line level?
59. Can a confirmation or rejection be corrected, and what evidence is retained?
60. Does electronic acknowledgement of a credit note use `validarDocumento` or a separate operation?

## 9. Printed document and QR

61. Is the verification base URL different between homologation and production?
62. Should a QR be printed before final AGT validation, and what will the kiosk display while the document is processing?
63. What QR behaviour and text are required for contingency documents pending submission?
64. Is the AGT logo inside the QR mandatory or optional? Please supply the approved asset and checksum.
65. Please confirm wording for original, subsequent copy, cancelled, corrected, invalid, and contingency documents.
66. Are A4 and thermal/POS layouts both certifiable if every mandatory field and QR rule is satisfied?

## 10. SAF-T and statutory reporting

67. Is there a supported producer API for direct submission of invoicing, acquisitions, inventory, or accounting SAF-T files?
68. If yes, please provide endpoint, authentication, schema, size, compression, signature, receipt, validation, and retry specifications.
69. Which SAF-T-AO XSD and namespace versions are currently accepted for each file type?
70. How should real-time electronic invoices be reconciled with periodic SAF-T to avoid duplicate or contradictory reporting?
71. What service satisfies the bi-monthly activity/global-sales communication requirement?
72. Are establishment, installed-software, and used/unused-series communications available through an API or only the Portal?
73. Can deadline and transitional-rule updates be obtained through a machine-readable AGT source?

## 11. Certification and operations

74. Please provide the current producer registration and software homologation checklist, test pack, expected evidence, contacts, and lead times.
75. Which changes require full recertification: tax engine, schema adapter, PDF layout, product version, producer key, infrastructure, or hosting region?
76. Can one certified SaaS product serve multiple taxpayers from shared infrastructure with strict logical isolation?
77. Are there Angolan data-residency or AGT remote-access requirements for payloads, documents, backups, keys, and technical logs?
78. What production pilot constraints and taxpayer approvals apply before general availability?
79. Which rollout dates currently apply to large taxpayers, State suppliers, general IVA, simplified IVA, and exclusion-regime voluntary adopters?
80. What is the formal change-notification channel for future schemas, tax tables, exemption codes, CAE lists, and error catalogues?

## Requested response format

To make the response directly usable as a certification artefact, we request:

- an authoritative endpoint and schema-version table;
- normative schemas and golden signature fixtures;
- a dated enumeration catalogue;
- explicit answers to the SaaS key-custody and offline questions;
- the current homologation/certification test pack; and
- the name or role of the AGT owner who may approve follow-up interpretations.

We are available to demonstrate an end-to-end homologation slice as soon as the contract points above are confirmed.
