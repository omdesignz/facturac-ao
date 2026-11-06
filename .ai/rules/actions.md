---
paths:
  - app/Actions/SaveCompanyProfile.php
  - app/Actions/UpdateCollectionFollowUp.php
  - app/Actions/PrepareRepeatInvoice.php
---

# Actions

## Freeze fiscal identity after AGT association
Once an AGT connection exists, the company fiscal profile is immutable even before its first verification. Enforce this both in LegalEntityPolicy and inside SaveCompanyProfile under the entity lock; otherwise a changed NIF silently keeps an unrelated taxpayer key. Bank details remain separately editable. Guided association also locks the entity when creating a connection.

## Internal collection follow-ups preserve team edits
Follow-ups are internal customer work, never a payment or a sent reminder. Write under a customer row lock with the expected collection_revision; identical retries are no-ops and stale differing edits are refused. Completion preserves note/dates and removes the item from the queue; settled customers remain until explicitly completed. Scope mobile writes to the pinned company/entity. Keep notes out of generic model serialization and activity properties. Dates and due-today use Africa/Luanda; web/iOS keep unsaved text on conflict until explicit discard/reload.

## Repeat invoices prepare current data without copying fiscal identity
Repeating FT/FR is a read-only seed for a fresh FT. Historical lines have no catalogue FK: resolve codes within the current entity, require unchanged units and active customer/articles, and recompute current customer prices and taxes. Never copy numbers, payments, references, discounts or withholding. Reject unavailable lines instead of dropping them. Native quantities must be whole and duplicate articles merged; web retains exact decimals. Keep repeat recovery keys separate and never overwrite pending mobile work.

## Decimal capable mobile repeat clients opt in
Supersedes the whole-quantity-only native restriction: quantity_format=decimal returns exact decimal strings and combines duplicate articles using checked scaled integers. The default response remains whole JSON integers and refuses fractional sources for older apps. Web keeps separate exact lines. Native invoice/quote composition and recovery use exact decimal strings, decode legacy integer recovery without changing the pending client key or intent, and never restore by automatically submitting.
