---
paths:
  - 'app/Analytics/Transaction*'
---

# Analytics

## Excel transaction reports preserve fiscal meaning and safe cell types
Scope report IDs to the current workspace and legal entity before iterating. Periods use document_date with the complete final day; drafts are excluded. Billing subtracts NC and excludes RG, while payments come from the document that records them; never add receipts to sales. Keep currencies separate and label balances as current, not period-end balances. Bind customer text, document identifiers and NIFs as explicit Excel strings, never automatic formulas or numeric IDs. Export limits must reject, never silently truncate.
