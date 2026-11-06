---
paths:
  - 'app/Fiscal/Agt/**'
---

# Agt

## Customer association is not producer homologation
AGT contract 2 api.html assigns Basic Auth to the software producer; gestao.html distinguishes producer-owned software keys from AGT-issued taxpayer keys. Guided association consumes only a server-prepared binding for exact workspace/entity/NIF/homologation plus pinned fingerprints, never browser-selected key references. Manual setup is deployment-gated off by default. Test verification and usable FT series do not establish production readiness; real provisioning and activation need separate authorization. See docs/solo-onboarding-acceptance-contract.md.

## Setup continuity keeps company and environment boundaries
Mobile company/setup reads the shared CompanySetupProgress even before a legal entity exists, checks X-Company, and must not cache readiness. Browser continuation carries only the workspace public id, requires authenticated membership, recomputes the next step on arrival, and changes company only through a CSRF-protected explicit PUT. The checklist describes homologation readiness, never permission to issue in production.
