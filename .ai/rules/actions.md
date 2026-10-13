---
paths:
  - app/Actions/SaveCompanyProfile.php
---

# Actions

## Freeze fiscal identity after AGT association
Once an AGT connection exists, the company fiscal profile is immutable even before its first verification. Enforce this both in LegalEntityPolicy and inside SaveCompanyProfile under the entity lock; otherwise a changed NIF silently keeps an unrelated taxpayer key. Bank details remain separately editable. Guided association also locks the entity when creating a connection.
