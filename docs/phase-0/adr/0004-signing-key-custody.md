# ADR 0004: Signing-Key Custody

- Status: Provisional — Open AGT
- Date: 2026-08-02

## Context

The software-producer private key must remain in the producer environment. Taxpayer keys are supplied through AGT, but the reviewed material does not expressly state whether a SaaS producer may custody and use a taxpayer private key.

## Decision

The signing boundary supports two implementations behind the same interface:

1. Custodial signing in a managed KMS/HSM using a per-legal-entity encrypted key reference, if AGT approves this model.
2. A non-custodial local signing agent that receives canonical bytes and returns only the JWS, if AGT requires the taxpayer to retain the key.

Application tables store fingerprints, versions, vault references, activation/revocation times, and audit metadata—never plaintext PEM material. Homologation keys are imported through a controlled ceremony and remain environment-restricted.

## Consequences

- Production key implementation cannot be finalised before AGT responds.
- Domain and AGT gateway work may proceed against a signer interface.
- Support tooling cannot display or export private key material.
- Rotation and revocation are first-class workflows rather than configuration replacement.

