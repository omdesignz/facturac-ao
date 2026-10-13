---
paths:
  - 'app/Fiscal/Saft/**'
---

# Saft

## Transport signatures and consignment schema conflict
Transport guides use a separately registered RSA-1024/SHA-1 PKCS#1 v1.5 key (DE 74/19), with chained 172-character base64 Hash and actual key version. Do not substitute SHA-256 checksums or electronic API JWS. Legacy issued checksums must not be silently rewritten; export fails closed. GC is listed in the DP 312/18 published annex but excluded by the pinned official SAFTAO1.01_01 XSD (GR/GT/GA/GD only); keep GC issuance blocked until AGT provides an accepted schema or clarification. MovementDate is the issue date, cancelled quantities are excluded from TotalQuantityIssued, incoming values use DebitAmount and outgoing use CreditAmount. Sources: minfin4949834.pdf and minfin951394.pdf on ucm.minfin.gov.ao.

## Billing profile excludes guides; transport signing applicability is unresolved
This supersedes the earlier transport-export and mandatory separate-registration conclusions above. DE 317/20 (published p. 6635) lists MovementOfGoods for integrated profile I, but omits it from billing F. SaftExporter is F: exclude guide queries, XML, standalone guide master records, validation-number fallback and guide readiness checks; preserve invoice OrderReferences. Do not relabel this export I without accounting data. AGT notice of 20 March 2026 exempts General/Simplified VAT taxpayers actually issuing electronically from billing SAF-T submission, not merely software passing homologation; keep export available for archive/review. Current guide issuance restrictions remain pending clarification: an additional RSA-1024 key registration for the current FE setup is unconfirmed and must not block billing exports or FE submissions. Sources: DE 317/20 official gazette (files.lex.ao/ministerio-das-financas/2020/decreto-executivo-n-o-317-20-de-14-de-dezembro/download/decreto-executivo-n-o-317-20-de-14-de-dezembro_ministerio-das-financas_lex-ao.pdf); AGT signed notice (kpmg.com/content/dam/kpmgsites/ao/pdf/Comunicado-Faturacao.pdf.coredownload.inline.pdf).
