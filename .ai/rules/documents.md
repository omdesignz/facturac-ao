---
paths:
  - 'app/Fiscal/Documents/**'
---

# Documents

## Printed signature characters require the SAF-T signature
AGT Decreto Executivo 74/19 annex 6(b) specifies characters 1, 11, 21 and 31 of the SAF-T Hash signature. document_payload_sha256 is a checksum and document_jws is the separate electronic API signature; do not silently treat either as the required legacy signature. The current footer deliberately omits the prefix until the signing/SAF-T integration is resolved. Operation availability must come from recorded goods_available_at/location, never an assumed issue timestamp. Source: https://www.ucm.minfin.gov.ao/cs/groups/public/documents/document/aw40/otq5/~edisp/minfin4949834.pdf

## Transport signatures and print provenance versioning
TransportDocumentPresenter now derives positions 1/11/21/31 only from a verified SAF-T RSA signature; the older invoice-footer caveat still applies to FiscalDocument signatures. Layout v6 adds transport-origin references. Do not edit frozen earlier PDF layouts. FiscalDocumentPrintSource must exclude origin_transport_line_id and order_references from pre-v6 child-row fingerprints so additive columns do not invalidate historical printed documents.

## Homologation requires adjustment source-line references
AGT schema-2 homologation on 2026-10-13 rejected the app-generated NC payload without lines[].referenceInfo.referenceItemLineNo. NC and ND portal fixtures passed after setting 1 for their known single-line original FT. Carry the actual original line number for adjustments; never default every line to 1 or infer from its new position. Portal fixture passes are not proof that the app builder emits this field. Evidence: output/agt-certification-1.1.0/results.json.
