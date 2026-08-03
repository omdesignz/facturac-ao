# ADR 0007: Platform, Storage, and Recovery

- Status: Provisional — residency review required
- Date: 2026-08-02

## Context

The fiscal ledger needs transactional integrity, durable asynchronous work, immutable documents, restore capability, and operational evidence. The starter currently uses SQLite and database queues for local development.

## Decision

Use PostgreSQL for production transactions, Redis for queues/cache/locks, and S3-compatible versioned object storage for documents and evidence. Use managed database point-in-time recovery plus encrypted application backups. Secrets and signing keys use a managed KMS/HSM or approved vault.

Target an initial RPO of 15 minutes and RTO of four hours, validated by restore drills. Final cloud region, replication geography, and support-access model remain provisional until Angolan privacy, residency, and AGT access requirements are confirmed.

## Consequences

- SQLite remains suitable for narrow local tests, not production fiscal concurrency.
- Restore verification is a release and operational requirement.
- Infrastructure selection must preserve key-custody and data-residency options.

