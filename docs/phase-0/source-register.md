# Controlled Source Register

Baseline date: 2026-08-02

The order below is the precedence proposed for implementation. When two sources conflict, the conflict remains open until AGT confirms the intended contract in writing.

## Precedence

1. Angolan legislation published in the Diário da República.
2. Written AGT clarifications and homologation acceptance evidence.
3. Current AGT Partner Portal service documentation.
4. Latest AGT technical specification supplied to the producer.
5. Model documents, test workbooks, examples, and community-maintained schemas.

## Local controlled inputs

| ID | Source | Observed version | SHA-256 | Classification | Use |
| --- | --- | --- | --- | --- | --- |
| SRC-001 | `facturação electrónica - especificação técnica dos serviços.pdf` | TA.020, document version 1.1; metadata updated 2025-11-06 | `f1bad32c67a0f47c53112a34999d9896260d69a8977f7af7235cba0fcdda3054` | Internal compliance source | Service contracts, fields, rules, errors, taxes, CAE, IEC and stamp-duty annexes |
| SRC-002 | `facturação electronica - chaves e nifs de teste.xlsx` | Five homologation identities | `9b49d5104dd284f374d6f5638fb4be9e7751b959e92024db64dffa4c6be7b179` | Secret | Homologation only; never copy keys or NIFs into issues, fixtures, logs, or documentation |
| SRC-003 | `Modelo - FACTURA.png` | Partner Portal model | `d6cfbc3ad5d2a2d3b8068516853b50805bcf0008d045b5d86b888ef41e763563` | Internal reference | Printed invoice content hierarchy and QR placement |
| SRC-004 | `Modelo - RECIBO.png` | Partner Portal model | `c2fd609e7561d7671678f9339f3475c79692cc2e98dcc6969e848db91010b4ae` | Internal reference | Printed receipt content hierarchy and referenced-document table |

## Online sources

| ID | URL | Retrieved | Use | Notes |
| --- | --- | --- | --- | --- |
| SRC-010 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/index.html> | 2026-08-02 | API purpose and asynchronous processing | Callback is described as a future capability. |
| SRC-011 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/api.html> | 2026-08-02 | Transport authentication | Basic Authentication is required for protected calls. |
| SRC-012 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/estrutura.html> | 2026-08-02 | JWS construction | RS256, canonical JSON recommendation, Base64URL without padding. |
| SRC-013 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/gestao.html> | 2026-08-02 | Key generation, distribution, rotation, and revocation | SaaS custody of taxpayer private keys is not expressly resolved. |
| SRC-014 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/qrcode.html> | 2026-08-02 | Printed-document QR | Model 2, version 4, correction M, UTF-8, prescribed verification URL. |
| SRC-015 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/registar.html> | 2026-08-02 | Invoice registration | Live example uses schema `1.2` and a maximum of 30 documents. |
| SRC-016 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/solicitar.html> | 2026-08-02 | Series request | Endpoint and example inconsistencies are included in the clarification request. |
| SRC-017 | <https://portaldoparceiro.minfin.gov.ao/doc-agt/faturacao-electronica/1/servicos/validar.html> | 2026-08-02 | Confirmation or rejection of received documents | Deductible and non-deductible VAT semantics require contract tests. |
| SRC-018 | <https://lex.ao/docs/presidente-da-republica/2025/decreto-presidencial-n-o-71-25-de-20-de-marco/> | 2026-08-02 | Legal requirements | Working legal reference; the release dossier must retain a gazetted copy. |
| SRC-019 | <https://github.com/assoft-portugal/SAF-T-AO> | 2026-08-02 | SAF-T-AO XSD reference | A schema is accepted only after checksum pinning and validation against AGT fixtures. |
| SRC-020 | <https://portaldocontribuinte.minfin.gov.ao/noticia?id=985578> | 2026-08-02 | Accounting SAF-T transitional notice | Demonstrates that deadlines and transitional treatment must be configurable. |
| SRC-021 | <https://portaldocontribuinte.minfin.gov.ao/noticia?id=985557> | 2026-08-02 | Inventory SAF-T deadline notice | Demonstrates that compliance dates can be administratively extended. |
| SRC-022 | <https://tailwindcss.com/plus/ui-blocks/application-ui> | 2026-08-02 | Licensed Phase 0 interface patterns | Vue, Tailwind CSS 4.3, light and dark variants were reviewed under the team's account. |

## Known source conflicts

| Conflict | Sources | Required resolution |
| --- | --- | --- |
| Schema examples show `1.0` and `1.2` | SRC-001, SRC-015 | Obtain the production and homologation schema/version matrix. |
| `submissionUUID` and `submissionGUID` appear in related examples | SRC-001 and live service pages | Confirm the exact field for every operation. |
| Some homologation examples contain `/ws/v1`, while others use `/v1` | SRC-001 and live service pages | Obtain the canonical endpoint list. |
| Document-status and document-type catalogues are not identical across examples | SRC-001 and live service pages | Obtain the authoritative enumeration and effective date. |
| Canonical JSON is recommended but not normatively defined | SRC-012 | Obtain ordering, number, Unicode, null, and escaping rules. |
| Taxpayer key custody by a SaaS producer is not stated | SRC-013 | Obtain written approval or require non-custodial signing. |
| No supported SAF-T upload service appears in the reviewed FE catalogue | SRC-001, SRC-010 | Confirm whether a separate producer API exists. |

## Secret handling for SRC-002

- Assign aliases `HML-T01` through `HML-T05`; do not reproduce NIFs in test names.
- Import private keys directly into a secrets vault using an audited operator procedure.
- Store only vault key references in application configuration.
- Redact PEM blocks and authentication headers before logs or exception reporting.
- Exclude decrypted private keys from backups, analytics, support exports, and fixtures.
- Destroy temporary plaintext copies after verified vault import.

