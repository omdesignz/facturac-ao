---
paths:
  - 'app/Billing/**'
---

# Billing

## Subscription access and retained fiscal records
SubscriptionAccess is the shared entitlement authority: a new workspace receives one 14-day trial anchored to created_at; pending checkout never restarts it. Active paid periods use plan limits with entitlement_version=1 and explicit feature booleans; legacy paid plans retain access for their paid period. Enforce premium mutations in web middleware and domain actions, scheduled recurring conversion, and external API boundaries. Keep historical document reads/prints, SAF-T, existing report downloads, AGT processing of issued documents and POS shift closure available after expiry. Accepted queued Excel exports may finish after expiry. Catalogue installation is idempotent and must never overwrite staff prices or sold plans. Plans only advertise implemented features: Essencial core invoicing; Negócio adds POS/TV, stock management and guides; Avançado adds recurring invoices, Excel reports and external integrations.
