# Phase 7B.3c-1 — concrete PostgreSQL migration review package

Date: 2026-10-09. **Design/review only. No migration, reconciliation, runtime, test or provider operation executed.**

Authority: [VC1 and exact handoff §13.16](phase-7b-3-tenant-ai-control-plane-supplement.md), accepted 3a/3b evidence and [3c design evidence](phase-7b-3c-verification-authority-design-evidence.json). This package is a proposal for independent review, not approval to apply it. All SQL below is inert Markdown. `database/migrations` is unchanged.

## 1. Decision, scope and evidence limits

The original concrete-DDL review failed with one MEDIUM reconciliation-coverage finding. Remediation R1 below addresses that finding and awaits independent re-review. It is **not approved for execution**. Reviewable means that the schema, guards, source dependencies, reconciliation and reversal are explicit below; it does not mean PostgreSQL has compiled or exercised the proposed SQL. No runtime safety claim depends on an unrun test passing.

This package preserves VC1 rather than changing it. Database checks establish structural integrity. The future verifier's private, request/process/fiber-local identity registry, authenticated receipt and fresh promotion transaction establish authority. Neither a `verified` flag nor a correctly shaped attempt row supplies that authority. No DDL function decrypts, signs, performs HTTP, creates a permit, selects an inference provider or enables inference.

Only this report and [its evidence artifact](phase-7b-3c-1-migration-review-evidence.json) are new repository artifacts. All proposed PHP filenames and SQL are review listings. No auto-discovered migration, runtime source, test, grant, profile or credential was created. The operational procedure and all proposed tests are **NOT RUN**.

The inspected live schema is a **disposable local test database**, `facturac_test_phase7b3a_storage`, PostgreSQL 18.6, UTF8, on loopback port 55439. Catalog inspection used read-only transactions and a five-second statement timeout. This is not evidence about production contents, account approval, live provider behavior or deployment readiness. The JSON artifact includes the complete inspected catalog definitions, not encrypted credential contents.

### Actual baseline

- The installed chronology is `2026_10_08_183139_create_assistant_provider_metadata_tables.php`, `2026_10_09_010739_create_tenant_ai_storage_tables.php`, then `2026_10_09_011029_seed_tenant_ai_storage_catalogue.php`.
- Catalog inspection found 164 columns, 218 constraints (including PostgreSQL 18 NOT NULL constraint entries), 42 indexes and 109 non-internal triggers with their function definitions across the ten existing tables. The allocation table does not exist.
- Local credential census: one pending/unverified and one revoked/unverified; zero active and zero replaced. No secret columns were selected for this census.
- More importantly, legitimate active customer rows cannot be produced at this installed boundary: `tai_197f2f5deff189f741f7` rejects all verification/test/use metadata, while the active-state CHECK requires verified metadata. The state enum reserves `active`/`replaced`, but the conjunction of installed guards prevents their legitimate creation. A database whose guards were disabled is not an accepted baseline; preflight refuses anomalous rows rather than manufacturing verification.
- `tenant_ai_credentials_one_pending` and `tenant_ai_credentials_one_active` already exist as partial unique indexes on **connection_id**, respectively `state='pending'` and `state='active'`.
- Existing connection/provider/workspace/deployment identity, immutable profile rows, envelope destruction, terminal monotonicity, creator A1 snapshots and deferred selected-version integrity remain authoritative. No existing customer profile is seeded: `TenantAiCatalogue` materializes only the VAP profile with `allows_customer=false`.
- Legacy estimated tokens are physically NOT NULL. Legacy admission/finalization and acknowledgement timestamps currently have precision zero. New verification times need microseconds; widening those existing timestamp columns preserves existing instants.

The approved active scope is a stable connection, not a global `(workspace, provider)` index. A connection has immutable workspace/deployment/provider/family/endpoint/profile binding. Multiple connections may retain unselected active history; settings select at most one credential for use. Changing the index to a workspace/provider index would be a redesign and would incorrectly forbid legitimate retained connection histories.

## 2. Concrete schema diff and ordering

Proposed filenames, **not created**:

| Order | Proposed file                                                                  | Purpose                                                                                  | Preconditions                                                                                                    |
| ----- | ------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| M1    | `database/migrations/2026_10_09_020000_extend_ai_verification_metadata.php`    | Add metadata, allocation table, FK/index scaffolding and temporary branch-closed checks  | Independent review; admission closed; restored backup and baseline catalog verification                          |
| M2    | `database/migrations/2026_10_09_020100_install_ai_verification_integrity.php`  | Install complete versioned checks, immutable/deferred guards and replace only `no_probe` | M1; compatible version-scoped legacy code staged; old workers drained; no inference enablement                   |
| M3    | `database/migrations/2026_10_09_020200_validate_ai_verification_integrity.php` | Validate every new NOT VALID FK/CHECK                                                    | M2; populated rehearsal and bounded validation window                                                            |
| R     | Controlled reconciliation procedure in §6                                      | Add only missing historical shared usage; preserve all original money/consent/IDs        | Separate approval to execute; all writers closed/root-fenced; complete reviewed mapping; no gateway attempts yet |

These timestamps follow the actual installed migrations. Check for collisions before future file creation. A collision changes only a filename/order, never a historical migration. Do not run `migrate`, `migrate:fresh`, a schema helper or the reconciliation in this checkpoint.

The SQL listings are the exhaustive column/type/default/nullability specification. Every `ADD COLUMN` without `NOT NULL` is nullable with SQL NULL default; none has an implicit application default. The allocation table has seven non-null columns and no defaults. New identity fields are immutable as defined by the guards. All new FKs use `ON UPDATE RESTRICT ON DELETE RESTRICT`; creator-user references retain their existing `SET NULL` behavior and immutable A1 snapshots. No new membership FK is introduced: original membership identity is a historical snapshot, revalidated through the primary membership record at operation time.

The JSON object inventory names every proposed ALTER constraint, function, trigger and explicitly created index. Inline allocation PK/FKs/CHECK are additionally specified in M1. The two preserved partial state indexes and all untouched baseline objects are present in the catalog snapshot.

### Mechanical details resolved by this proposal

- `contract_version` separates legacy rows from the initial **customer-managed, connection_probe-only** gateway branch. VAP inference and generalized assistant-intent attempts are not admitted by these new checks. Provider-neutral controls can represent the already-approved roles, but do not provision them.
- Extra attempt metadata stores the approved receipt inputs and exact allocation/approval references. `usage_budget_id` and `aggregate_budget_id` identify the shared and customer aggregate ceilings; existing `budget_id` identifies the customer account ceiling. This is one ledger with nine allocation rows, not three bills or three attempts.
- `acknowledged_at_snapshot` captures the existing acknowledgement timestamp for fresh transaction checks. Timestamp precision is widened to six; a later gateway acknowledgement must advance its timestamp strictly under its row lock, even within one clock tick. This detects withdrawal/reacknowledgement changes during an operation without changing consent identity or adding another authority source. It is internal admission metadata, not an extra receipt claim or permission. The private operation context captures it, and the transaction compares it; an acknowledgement ID alone never substitutes for current consent.
- `authority_references` is one bounded JSON array of sorted typed ID/revision triples, not an open metadata map. It records controls, budgets and owner approval. An immutable owner grant has revision string `1`; revocation is checked independently. Control/budget revisions are real stored revisions. The application must verify the exact required reference set and match each immutable grant/account mapping to the code policy. SQL validates shape/order/bounds, not those external facts.
- No FK is invented for external organization/workspace claims, a signer key ID, an opaque account reference, a deployment trust root or disclosure UUID. Their semantic validity belongs to code/deployment manifests and fresh authority evaluation. Stored UUID shape alone never approves one.
- `last_test_operation_id` and `verification_operation_id` have deferred composite references back to the bound attempt. This avoids acquiring an attempt lock while credential rows are still being updated earlier in the canonical order. Deferred matching guards validate final transaction state. Restrictive delete actions preserve retained history.
- The generic credential schema remains provider-neutral. The initial attempt-success branch requires the explicitly approved Anthropic claim strength. Additional provider claim-strength branches need their own later review; nullable columns are not permission to bypass this branch.

### Exact up listings

M1, M2 and M3 each use Laravel's PostgreSQL migration transaction. Use raw `DB::unprepared()` statements for PL/pgSQL, partial indexes and deferred constraint triggers; do not weaken semantics to fit Schema Builder. Installed Laravel's Migrator/grammar supports transactional PostgreSQL migrations. No dependency upgrade is needed. SQL functions are SECURITY INVOKER, use schema-qualified tables, and have no secrets or privileged signing capability.

Normal index builds are proposed under closed admission, with `lock_timeout=250ms` and per-statement `statement_timeout=10s`. They are not `CONCURRENTLY` builds. A blocked/large build aborts the migration transaction; do not silently raise limits or retry. A production-sized concurrent build would require a separately reviewed nontransactional sequence with invalid-index cleanup, not adding `CONCURRENTLY` inside this transaction. PostgreSQL documents those transaction and partial-index requirements in [CREATE INDEX](https://www.postgresql.org/docs/18/sql-createindex.html).

NOT VALID checks/FKs enforce new writes immediately while deferring historical validation. M3 must finish before any admission. The successor guards must all exist before removing `no_probe`, within the same M2 transaction. Temporary M1 checks keep the branch closed between migrations. No migration turns a control on.

#### M1 — additive structure

```sql
-- REVIEW LISTING ONLY. No execution authorized.
-- M1: 2026_10_09_020000_extend_ai_verification_metadata.php (proposed name only)
-- Laravel wraps M1 in one PostgreSQL transaction.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';

-- Fail closed on any state outside the accepted 3a/3b boundary.
DO $ddl$
BEGIN
  IF EXISTS (
    SELECT 1 FROM public.tenant_ai_credentials
    WHERE state NOT IN ('pending','revoked') OR verification_state <> 'unverified'
       OR verified_profile_id IS NOT NULL OR verification_operation_id IS NOT NULL
       OR last_used_at IS NOT NULL OR last_tested_at IS NOT NULL
       OR last_verified_at IS NOT NULL OR last_test_outcome IS NOT NULL
  ) THEN RAISE EXCEPTION 'AI migration precondition failed'; END IF;
  IF EXISTS (SELECT 1 FROM public.tenant_ai_connections WHERE payer_budget_id IS NOT NULL)
     OR EXISTS (SELECT 1 FROM public.tenant_ai_settings WHERE credential_version_id IS NOT NULL)
  THEN RAISE EXCEPTION 'AI migration precondition failed'; END IF;
END $ddl$;

ALTER TABLE public.assistant_provider_controls
  ADD COLUMN contract_version varchar(24) NOT NULL DEFAULT 'legacy',
  ADD COLUMN revision bigint NOT NULL DEFAULT 1,
  ADD COLUMN deployment_id uuid,
  ADD COLUMN ownership_kind varchar(24),
  ADD COLUMN account_role varchar(24),
  ADD COLUMN workspace_id bigint,
  ADD COLUMN provider_key varchar(80),
  ADD COLUMN account_reference uuid;

ALTER TABLE public.assistant_provider_windows
  ADD COLUMN reserved_attempt_units bigint NOT NULL DEFAULT 0,
  ADD COLUMN reserved_output_units bigint NOT NULL DEFAULT 0;

ALTER TABLE public.assistant_provider_tenants
  ADD COLUMN contract_version varchar(24) NOT NULL DEFAULT 'legacy',
  ADD COLUMN legal_entity_id bigint,
  ADD COLUMN environment varchar(16),
  ADD COLUMN deployment_id uuid,
  ADD COLUMN profile_id uuid,
  ADD COLUMN ownership_kind varchar(24),
  ADD COLUMN account_budget_id uuid,
  ADD COLUMN connection_id uuid,
  ADD COLUMN selection_revision bigint,
  ADD COLUMN disclosure_id uuid,
  ADD COLUMN entitlement_revision bigint,
  ADD COLUMN purpose varchar(24),
  ADD COLUMN verifier_policy_sha256 char(64),
  ADD COLUMN account_mapping_sha256 char(64);

ALTER TABLE public.assistant_provider_acknowledgements
  ADD COLUMN contract_version varchar(24) NOT NULL DEFAULT 'legacy',
  ADD COLUMN disclosure_id uuid;

ALTER TABLE public.assistant_provider_attempts
  ADD COLUMN contract_version varchar(24) NOT NULL DEFAULT 'legacy',
  ADD COLUMN deployment_id uuid,
  ADD COLUMN provider_key varchar(80),
  ADD COLUMN ownership_kind varchar(24),
  ADD COLUMN profile_id uuid,
  ADD COLUMN selection_revision bigint,
  ADD COLUMN connection_id uuid,
  ADD COLUMN credential_version_id uuid,
  ADD COLUMN vap_reference_id uuid,
  ADD COLUMN endpoint_policy_key varchar(80),
  ADD COLUMN disclosure_id uuid,
  ADD COLUMN entitlement_revision bigint,
  ADD COLUMN purpose varchar(24),
  ADD COLUMN monetary_applicable boolean,
  ADD COLUMN reserved_output_units bigint,
  ADD COLUMN usage_budget_id uuid,
  ADD COLUMN aggregate_budget_id uuid,
  ADD COLUMN owner_approval_id bigint,
  ADD COLUMN acknowledgement_id bigint,
  ADD COLUMN workspace_public_id char(26),
  ADD COLUMN legal_entity_public_id char(26),
  ADD COLUMN credential_version_number bigint,
  ADD COLUMN credential_created_at timestamp(6) with time zone,
  ADD COLUMN credential_wrap_revision bigint,
  ADD COLUMN expected_settings_revision bigint,
  ADD COLUMN expected_connection_revision bigint,
  ADD COLUMN prior_selected_version_id uuid,
  ADD COLUMN prior_active_generation bigint,
  ADD COLUMN proposed_activated_generation bigint,
  ADD COLUMN actor_membership_id bigint,
  ADD COLUMN profile_manifest_sha256 char(64),
  ADD COLUMN credential_family varchar(80),
  ADD COLUMN verifier_policy_key varchar(80),
  ADD COLUMN verifier_policy_sha256 char(64),
  ADD COLUMN account_mapping_sha256 char(64),
  ADD COLUMN authority_references jsonb,
  ADD COLUMN acknowledged_at_snapshot timestamp(6) with time zone,
  ADD COLUMN evidence_realm varchar(24),
  ADD COLUMN send_authorized_at timestamp(6) with time zone,
  ADD COLUMN observed_at timestamp(6) with time zone,
  ADD COLUMN verification_outcome varchar(40),
  ADD COLUMN claim_strength varchar(48),
  ADD COLUMN observed_organization_id uuid,
  ADD COLUMN observed_workspace_id varchar(127),
  ADD COLUMN observed_model_id varchar(120),
  ADD COLUMN evidence_id uuid,
  ADD COLUMN receipt_key_id varchar(80),
  ADD COLUMN receipt_mac char(64),
  ADD COLUMN promotion_expires_at timestamp(6) with time zone,
  ADD COLUMN promotion_disposition varchar(24),
  ADD COLUMN committed_activated_generation bigint;

ALTER TABLE public.tenant_ai_connections
  ADD COLUMN active_generation bigint NOT NULL DEFAULT 0;

ALTER TABLE public.tenant_ai_credentials
  ADD COLUMN activated_generation bigint,
  ADD COLUMN last_test_operation_id uuid;

ALTER TABLE public.assistant_provider_attempts
  ALTER COLUMN estimated_tokens DROP NOT NULL,
  ALTER COLUMN admitted_at TYPE timestamp(6) with time zone,
  ALTER COLUMN finalized_at TYPE timestamp(6) with time zone;

ALTER TABLE public.assistant_provider_acknowledgements
  ALTER COLUMN acknowledged_at TYPE timestamp(6) with time zone,
  ALTER COLUMN revoked_at TYPE timestamp(6) with time zone;

CREATE TABLE public.assistant_provider_allocations (
  attempt_id uuid NOT NULL,
  window_id bigint NOT NULL,
  role varchar(24) NOT NULL,
  reserved_micro_usd bigint NOT NULL,
  reserved_attempt_units bigint NOT NULL,
  reserved_output_units bigint NOT NULL,
  created_at timestamp(6) with time zone NOT NULL,
  CONSTRAINT ai3c_alloc_pk PRIMARY KEY(attempt_id,window_id),
  CONSTRAINT ai3c_alloc_attempt_fk FOREIGN KEY(attempt_id)
    REFERENCES public.assistant_provider_attempts(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT ai3c_alloc_window_fk FOREIGN KEY(window_id)
    REFERENCES public.assistant_provider_windows(id) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT ai3c_alloc_units_ck CHECK (
    (role='shared_usage' AND reserved_micro_usd=0 AND reserved_attempt_units=1 AND reserved_output_units>0)
    OR (role IN ('vap_aggregate','vap_account','customer_aggregate','customer_account')
      AND reserved_micro_usd>0 AND reserved_attempt_units=0 AND reserved_output_units=0)
  )
);
CREATE INDEX ai3c_alloc_window_idx ON public.assistant_provider_allocations(window_id);

-- Historical identity/state indexes already exist: VERIFY definitions; do not recreate/drop.
-- CREATE UNIQUE INDEX tenant_ai_credentials_one_pending
--   ON public.tenant_ai_credentials(connection_id) WHERE state='pending';
-- CREATE UNIQUE INDEX tenant_ai_credentials_one_active
--   ON public.tenant_ai_credentials(connection_id) WHERE state='active';
CREATE UNIQUE INDEX ai3c_credential_generation_uq
  ON public.tenant_ai_credentials(connection_id,activated_generation)
  WHERE activated_generation IS NOT NULL;
CREATE UNIQUE INDEX ai3c_evidence_id_uq ON public.assistant_provider_attempts(evidence_id)
  WHERE evidence_id IS NOT NULL;
CREATE UNIQUE INDEX ai3c_probe_inflight_uq ON public.assistant_provider_attempts(connection_id)
  WHERE contract_version='gateway_v1' AND purpose='connection_probe' AND state='admitted';
CREATE INDEX ai3c_probe_connection_idx ON public.assistant_provider_attempts(connection_id,admitted_at,id)
  WHERE contract_version='gateway_v1' AND purpose='connection_probe';
CREATE INDEX ai3c_probe_workspace_idx ON public.assistant_provider_attempts(workspace_id,purpose,admitted_at,id)
  WHERE contract_version='gateway_v1' AND purpose='connection_probe';
CREATE INDEX ai3c_probe_actor_idx ON public.assistant_provider_attempts(actor_attribution_id,purpose,admitted_at,id)
  WHERE contract_version='gateway_v1' AND purpose='connection_probe';
CREATE INDEX ai3c_attempt_workspace_idx ON public.assistant_provider_attempts(workspace_id,admitted_at,id);

ALTER TABLE public.assistant_provider_attempts
  ADD CONSTRAINT ai3c_attempt_credential_tuple_uq
    UNIQUE(id,credential_version_id,connection_id,workspace_id,deployment_id);
ALTER TABLE public.assistant_provider_attempts
  ADD CONSTRAINT ai3c_attempt_credential_fk
    FOREIGN KEY(credential_version_id,connection_id,workspace_id,deployment_id)
    REFERENCES public.tenant_ai_credentials(id,connection_id,workspace_id,deployment_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID,
  ADD CONSTRAINT ai3c_attempt_prior_fk
    FOREIGN KEY(prior_selected_version_id,connection_id,workspace_id,deployment_id)
    REFERENCES public.tenant_ai_credentials(id,connection_id,workspace_id,deployment_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
ALTER TABLE public.tenant_ai_credentials
  ADD CONSTRAINT ai3c_credential_verified_attempt_fk
    FOREIGN KEY(verification_operation_id,id,connection_id,workspace_id,deployment_id)
    REFERENCES public.assistant_provider_attempts(id,credential_version_id,connection_id,workspace_id,deployment_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED NOT VALID,
  ADD CONSTRAINT ai3c_credential_test_attempt_fk
    FOREIGN KEY(last_test_operation_id,id,connection_id,workspace_id,deployment_id)
    REFERENCES public.assistant_provider_attempts(id,credential_version_id,connection_id,workspace_id,deployment_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT DEFERRABLE INITIALLY DEFERRED NOT VALID,
  ADD CONSTRAINT ai3c_credential_generation_ck CHECK (activated_generation IS NULL OR activated_generation>0) NOT VALID;
ALTER TABLE public.tenant_ai_connections
  ADD CONSTRAINT ai3c_connection_generation_ck CHECK(active_generation>=0) NOT VALID;
ALTER TABLE public.assistant_provider_windows
  ADD CONSTRAINT ai3c_window_units_ck CHECK(reserved_attempt_units>=0 AND reserved_output_units>=0) NOT VALID;
ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_ref_1_fk FOREIGN KEY(workspace_id) REFERENCES public.workspaces(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_1_idx ON public.assistant_provider_controls(workspace_id);
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_ref_2_fk FOREIGN KEY(profile_id) REFERENCES public.ai_model_profiles(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_2_idx ON public.assistant_provider_tenants(profile_id);
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_ref_3_fk FOREIGN KEY(account_budget_id) REFERENCES public.assistant_provider_controls(budget_id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_3_idx ON public.assistant_provider_tenants(account_budget_id);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_ref_4_fk FOREIGN KEY(profile_id) REFERENCES public.ai_model_profiles(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_4_idx ON public.assistant_provider_attempts(profile_id);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_ref_5_fk FOREIGN KEY(usage_budget_id) REFERENCES public.assistant_provider_controls(budget_id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_5_idx ON public.assistant_provider_attempts(usage_budget_id);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_ref_6_fk FOREIGN KEY(aggregate_budget_id) REFERENCES public.assistant_provider_controls(budget_id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_6_idx ON public.assistant_provider_attempts(aggregate_budget_id);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_ref_7_fk FOREIGN KEY(owner_approval_id) REFERENCES public.assistant_provider_tenants(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_7_idx ON public.assistant_provider_attempts(owner_approval_id);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_ref_8_fk FOREIGN KEY(acknowledgement_id) REFERENCES public.assistant_provider_acknowledgements(id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_ref_8_idx ON public.assistant_provider_attempts(acknowledgement_id);

ALTER TABLE public.assistant_provider_tenants
  ADD CONSTRAINT ai3c_approval_entity_fk FOREIGN KEY(legal_entity_id,workspace_id)
    REFERENCES public.legal_entities(id,workspace_id) ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID,
  ADD CONSTRAINT ai3c_approval_connection_fk FOREIGN KEY(connection_id,workspace_id,deployment_id)
    REFERENCES public.tenant_ai_connections(id,workspace_id,deployment_id)
    ON UPDATE RESTRICT ON DELETE RESTRICT NOT VALID;
CREATE INDEX ai3c_approval_entity_idx ON public.assistant_provider_tenants(legal_entity_id,workspace_id);
CREATE INDEX ai3c_approval_connection_idx ON public.assistant_provider_tenants(connection_id,workspace_id,deployment_id);
CREATE INDEX ai3c_attempt_credential_idx ON public.assistant_provider_attempts(credential_version_id,connection_id,workspace_id,deployment_id);
CREATE INDEX ai3c_attempt_prior_idx ON public.assistant_provider_attempts(prior_selected_version_id,connection_id,workspace_id,deployment_id);
CREATE INDEX ai3c_credential_test_idx ON public.tenant_ai_credentials(last_test_operation_id,id,connection_id,workspace_id,deployment_id);
-- Existing UNIQUE(verification_operation_id) already supplies the leading reference index.

-- M1 commits with new branches closed; M2 removes these only after successor guards exist.
ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_stage_closed_1 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_stage_closed_2 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_acknowledgements ADD CONSTRAINT ai3c_stage_closed_3 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_stage_closed_4 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_stage_estimate CHECK(estimated_tokens IS NOT NULL);
```

#### M2 — versioned constraints and indexes

```sql
-- REVIEW LISTING ONLY.
-- M2: 2026_10_09_020100_install_ai_verification_integrity.php (proposed)
-- Execute only with admission closed, old writers drained and compatible version-scoped code deployed.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';

ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_control_shape_ck CHECK (
  (revision>0 AND (
    (contract_version='legacy' AND deployment_id IS NULL AND ownership_kind IS NULL
      AND account_role IS NULL AND workspace_id IS NULL AND provider_key IS NULL AND account_reference IS NULL)
    OR (contract_version='gateway_v1' AND deployment_id IS NOT NULL
      AND ownership_kind IN ('shared_usage','vap_managed','customer_managed')
      AND account_role IN ('usage','aggregate','account')
      AND ((ownership_kind='customer_managed' AND workspace_id IS NOT NULL)
        OR (ownership_kind IN ('shared_usage','vap_managed') AND workspace_id IS NULL))
      AND ((ownership_kind='shared_usage' AND account_role='usage' AND provider_key IS NULL AND account_reference IS NULL)
        OR (ownership_kind IN ('vap_managed','customer_managed') AND account_role='aggregate' AND provider_key IS NULL AND account_reference IS NULL)
        OR (ownership_kind IN ('vap_managed','customer_managed') AND account_role='account' AND provider_key IS NOT NULL AND account_reference IS NOT NULL)))
  )
) IS TRUE) NOT VALID;
CREATE UNIQUE INDEX ai3c_control_usage_uq ON public.assistant_provider_controls(deployment_id)
  WHERE contract_version='gateway_v1' AND ownership_kind='shared_usage' AND account_role='usage';
CREATE UNIQUE INDEX ai3c_control_vap_aggregate_uq ON public.assistant_provider_controls(deployment_id)
  WHERE contract_version='gateway_v1' AND ownership_kind='vap_managed' AND account_role='aggregate';
CREATE UNIQUE INDEX ai3c_control_vap_account_uq ON public.assistant_provider_controls(deployment_id,provider_key,account_reference)
  WHERE contract_version='gateway_v1' AND ownership_kind='vap_managed' AND account_role='account';
CREATE UNIQUE INDEX ai3c_control_customer_aggregate_uq ON public.assistant_provider_controls(deployment_id,workspace_id)
  WHERE contract_version='gateway_v1' AND ownership_kind='customer_managed' AND account_role='aggregate';
CREATE UNIQUE INDEX ai3c_control_customer_account_uq ON public.assistant_provider_controls(deployment_id,workspace_id,provider_key,account_reference)
  WHERE contract_version='gateway_v1' AND ownership_kind='customer_managed' AND account_role='account';

ALTER TABLE public.assistant_provider_acknowledgements ADD CONSTRAINT ai3c_ack_shape_ck CHECK ((
  (contract_version='legacy' AND disclosure_id IS NULL)
  OR (contract_version='gateway_v1' AND disclosure_id IS NOT NULL)
) IS TRUE) NOT VALID;
-- Replace only after legacy callers use this exact predicate in their ON CONFLICT target.
ALTER TABLE public.assistant_provider_acknowledgements DROP CONSTRAINT assistant_provider_ack_identity;
CREATE UNIQUE INDEX ai3c_ack_legacy_uq ON public.assistant_provider_acknowledgements(actor_attribution_id,workspace_id,policy)
  WHERE contract_version='legacy';
CREATE UNIQUE INDEX ai3c_ack_gateway_uq ON public.assistant_provider_acknowledgements(actor_attribution_id,workspace_id,disclosure_id)
  WHERE contract_version='gateway_v1';

DROP INDEX public.assistant_provider_tenant_active;
CREATE UNIQUE INDEX assistant_provider_tenant_active ON public.assistant_provider_tenants(workspace_id,policy,profile)
  WHERE contract_version='legacy' AND revoked_at IS NULL;
CREATE UNIQUE INDEX ai3c_approval_gateway_active_uq ON public.assistant_provider_tenants(
  workspace_id,legal_entity_id,environment,deployment_id,profile_id,ownership_kind,
  account_budget_id,connection_id,selection_revision,disclosure_id,entitlement_revision,purpose)
  NULLS NOT DISTINCT WHERE contract_version='gateway_v1' AND revoked_at IS NULL;
-- Shape checks require all customer fields; NULLS NOT DISTINCT protects the future VAP null-connection branch too.

-- Legacy/global columns retain their existing values; gateway scopes are explicitly branched.
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_approval_shape_ck CHECK ((
  (contract_version='legacy' AND legal_entity_id IS NULL AND environment IS NULL
    AND deployment_id IS NULL AND profile_id IS NULL AND ownership_kind IS NULL
    AND account_budget_id IS NULL AND connection_id IS NULL AND selection_revision IS NULL
    AND disclosure_id IS NULL AND entitlement_revision IS NULL AND purpose IS NULL
    AND verifier_policy_sha256 IS NULL AND account_mapping_sha256 IS NULL)
  OR (contract_version='gateway_v1' AND legal_entity_id IS NOT NULL AND environment='production'
    AND deployment_id IS NOT NULL AND profile_id IS NOT NULL AND ownership_kind='customer_managed'
    AND account_budget_id IS NOT NULL AND connection_id IS NOT NULL AND selection_revision>0
    AND disclosure_id IS NOT NULL AND entitlement_revision>0 AND purpose='connection_probe'
    AND verifier_policy_sha256 ~ '^[0-9a-f]{64}$' AND account_mapping_sha256 ~ '^[0-9a-f]{64}$')
) IS TRUE) NOT VALID;

-- No second application authorizer: this checks a CLOSED metadata shape, not authenticity.
CREATE FUNCTION public.ai3c_refs_shape(v jsonb) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE STRICT AS $f$
DECLARE x jsonb; previous_key text := ''; sort_key text; n integer;
BEGIN
  IF jsonb_typeof(v)<>'array' THEN RETURN false; END IF;
  n:=jsonb_array_length(v); IF n<1 OR n>16 THEN RETURN false; END IF;
  FOR x IN SELECT value FROM jsonb_array_elements(v) LOOP
    IF jsonb_typeof(x)<>'array' OR jsonb_array_length(x)<>3
      OR jsonb_typeof(x->0)<>'string' OR jsonb_typeof(x->1)<>'string'
      OR jsonb_typeof(x->2)<>'string' THEN RETURN false; END IF;
    IF x->>0 NOT IN ('gateway_control','budget_control','owner_approval')
      OR x->>2 !~ '^[1-9][0-9]{0,18}$' THEN RETURN false; END IF;
    PERFORM (x->>2)::bigint;
    IF x->>0='owner_approval' THEN
      IF x->>1 !~ '^[1-9][0-9]{0,18}$' THEN RETURN false; END IF;
      PERFORM (x->>1)::bigint;
    ELSE
      IF x->>1 !~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'
        THEN RETURN false; END IF;
    END IF;
    sort_key:=(x->>0)||':'||(x->>1);
    IF previous_key COLLATE "C">=sort_key COLLATE "C" THEN RETURN false; END IF;
    previous_key:=sort_key;
  END LOOP;
  RETURN true;
EXCEPTION WHEN OTHERS THEN RETURN false;
END $f$;

ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT assistant_provider_attempts_values;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT assistant_provider_attempts_values CHECK ((
 environment='production' AND state IN ('admitted','received','failed','usage_unknown') AND (
 (contract_version='legacy' AND estimated_tokens IS NOT NULL
  AND reserved_micro_usd=552816 AND estimated_tokens BETWEEN 1024 AND 8192
  AND request_bytes BETWEEN 1 AND 7168
  AND (input_tokens IS NULL OR input_tokens BETWEEN 0 AND 1000000)
  AND (output_tokens IS NULL OR output_tokens BETWEEN 0 AND 1024)
  AND (actual_micro_usd IS NULL OR actual_micro_usd>=0)
  AND deployment_id IS NULL AND provider_key IS NULL AND ownership_kind IS NULL AND profile_id IS NULL AND selection_revision IS NULL AND connection_id IS NULL AND credential_version_id IS NULL AND vap_reference_id IS NULL AND endpoint_policy_key IS NULL AND disclosure_id IS NULL AND entitlement_revision IS NULL AND purpose IS NULL AND monetary_applicable IS NULL AND reserved_output_units IS NULL AND usage_budget_id IS NULL AND aggregate_budget_id IS NULL AND owner_approval_id IS NULL AND acknowledgement_id IS NULL AND workspace_public_id IS NULL AND legal_entity_public_id IS NULL AND credential_version_number IS NULL AND credential_created_at IS NULL AND credential_wrap_revision IS NULL AND expected_settings_revision IS NULL AND expected_connection_revision IS NULL AND prior_selected_version_id IS NULL AND prior_active_generation IS NULL AND proposed_activated_generation IS NULL AND actor_membership_id IS NULL AND profile_manifest_sha256 IS NULL AND credential_family IS NULL AND verifier_policy_key IS NULL AND verifier_policy_sha256 IS NULL AND account_mapping_sha256 IS NULL AND authority_references IS NULL AND acknowledged_at_snapshot IS NULL AND evidence_realm IS NULL AND send_authorized_at IS NULL AND observed_at IS NULL AND verification_outcome IS NULL AND claim_strength IS NULL AND observed_organization_id IS NULL AND observed_workspace_id IS NULL AND observed_model_id IS NULL AND evidence_id IS NULL AND receipt_key_id IS NULL AND receipt_mac IS NULL AND promotion_expires_at IS NULL AND promotion_disposition IS NULL AND committed_activated_generation IS NULL)
 OR (contract_version='gateway_v1' AND deployment_id IS NOT NULL AND provider_key IS NOT NULL AND ownership_kind IS NOT NULL AND profile_id IS NOT NULL AND selection_revision IS NOT NULL AND connection_id IS NOT NULL AND credential_version_id IS NOT NULL AND endpoint_policy_key IS NOT NULL AND disclosure_id IS NOT NULL AND entitlement_revision IS NOT NULL AND purpose IS NOT NULL AND monetary_applicable IS NOT NULL AND reserved_output_units IS NOT NULL AND usage_budget_id IS NOT NULL AND aggregate_budget_id IS NOT NULL AND owner_approval_id IS NOT NULL AND acknowledgement_id IS NOT NULL AND workspace_public_id IS NOT NULL AND legal_entity_public_id IS NOT NULL AND credential_version_number IS NOT NULL AND credential_created_at IS NOT NULL AND credential_wrap_revision IS NOT NULL AND expected_settings_revision IS NOT NULL AND expected_connection_revision IS NOT NULL AND prior_active_generation IS NOT NULL AND proposed_activated_generation IS NOT NULL AND actor_membership_id IS NOT NULL AND profile_manifest_sha256 IS NOT NULL AND credential_family IS NOT NULL AND verifier_policy_key IS NOT NULL AND verifier_policy_sha256 IS NOT NULL AND account_mapping_sha256 IS NOT NULL AND authority_references IS NOT NULL AND acknowledged_at_snapshot IS NOT NULL AND evidence_realm IS NOT NULL AND verification_outcome IS NOT NULL
  AND ownership_kind='customer_managed' AND purpose='connection_probe' AND vap_reference_id IS NULL
  AND monetary_applicable AND reserved_micro_usd>0 AND reserved_output_units>0
  AND selection_revision>0 AND entitlement_revision>0
  AND credential_version_number>0 AND credential_wrap_revision>0
  AND expected_settings_revision=selection_revision AND expected_connection_revision>0
  AND prior_active_generation>=0 AND prior_active_generation<9223372036854775807
  AND proposed_activated_generation=prior_active_generation+1 AND actor_membership_id>0
  AND profile_manifest_sha256 ~ '^[0-9a-f]{64}$'
  AND verifier_policy_sha256 ~ '^[0-9a-f]{64}$' AND account_mapping_sha256 ~ '^[0-9a-f]{64}$'
  AND public.ai3c_refs_shape(authority_references)
  AND workspace_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND legal_entity_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND evidence_realm IN ('provider_tls','offline_fixture')
  AND (send_authorized_at IS NULL OR send_authorized_at>=admitted_at)
  AND (observed_at IS NULL OR (send_authorized_at IS NOT NULL AND observed_at>=send_authorized_at))
  AND estimated_tokens IS NULL AND request_bytes=0 AND input_tokens IS NULL
  AND output_tokens IS NULL AND actual_micro_usd IS NULL
  AND ((state='admitted' AND outcome IS NULL AND finalized_at IS NULL AND verification_outcome='not_observed'
     AND observed_at IS NULL AND evidence_id IS NULL AND receipt_key_id IS NULL
     AND receipt_mac IS NULL AND promotion_expires_at IS NULL AND promotion_disposition IS NULL
     AND committed_activated_generation IS NULL AND claim_strength IS NULL
     AND observed_organization_id IS NULL AND observed_workspace_id IS NULL AND observed_model_id IS NULL)
   OR (state IN ('failed','usage_unknown') AND outcome='usage_unknown' AND finalized_at IS NOT NULL
     AND finalized_at>=admitted_at AND verification_outcome<>'not_observed'
     AND verification_outcome IN ('provider_authenticated_model_visible','credential_rejected','provider_denied',
       'model_unavailable','provider_unavailable','timeout','transport_policy_rejected','malformed_response',
       'account_context_unproven','local_policy_denied','stale_candidate','expired','abandoned')
     AND ((verification_outcome='provider_authenticated_model_visible'
       AND state='usage_unknown' AND send_authorized_at IS NOT NULL AND observed_at IS NOT NULL
       AND admitted_at<=send_authorized_at AND send_authorized_at<=observed_at AND observed_at<=finalized_at
       AND claim_strength='organization_and_workspace_observed'
       AND observed_organization_id IS NOT NULL AND observed_workspace_id ~ '^wrkspc_[A-Za-z0-9]{1,120}$'
       AND observed_model_id IS NOT NULL AND evidence_id IS NOT NULL
       AND receipt_key_id ~ '^[A-Za-z0-9_-]{1,80}$' AND receipt_mac ~ '^[0-9a-f]{64}$'
       AND promotion_expires_at IS NOT NULL AND promotion_expires_at<=observed_at+interval '5 seconds'
       AND promotion_expires_at<=admitted_at+interval '10 seconds'
       AND promotion_disposition IN ('promoted','rejected_stale','rejected_policy','expired','abandoned')
       AND ((promotion_disposition='promoted' AND committed_activated_generation=proposed_activated_generation
          AND finalized_at<promotion_expires_at)
        OR (promotion_disposition<>'promoted' AND committed_activated_generation IS NULL)))
      OR (verification_outcome<>'provider_authenticated_model_visible'
       AND evidence_id IS NULL AND receipt_key_id IS NULL AND receipt_mac IS NULL
       AND promotion_expires_at IS NULL AND committed_activated_generation IS NULL
       AND claim_strength IS NULL AND observed_organization_id IS NULL
       AND observed_workspace_id IS NULL AND observed_model_id IS NULL
       AND (promotion_disposition='not_applicable'
          OR (verification_outcome='abandoned' AND promotion_disposition='abandoned' AND state='usage_unknown'))))))
 ))) IS TRUE) NOT VALID;
```

#### M2 — successor integrity guards

```sql
-- REVIEW LISTING ONLY. Completes proposed M2; all functions are SECURITY INVOKER.
CREATE FUNCTION public.ai3c_metadata_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE oldj jsonb; newj jsonb; mutable text[]; old_gateway boolean;
BEGIN
  oldj:=CASE WHEN TG_OP='INSERT' THEN '{}'::jsonb ELSE to_jsonb(OLD) END;
  newj:=CASE WHEN TG_OP='DELETE' THEN '{}'::jsonb ELSE to_jsonb(NEW) END;
  old_gateway:=oldj->>'contract_version'='gateway_v1';
  IF TG_OP='DELETE' THEN
    IF old_gateway THEN RAISE EXCEPTION 'Retained AI evidence'; END IF;
    RETURN OLD;
  END IF;
  IF TG_OP='INSERT' THEN RETURN NEW; END IF;
  IF NEW.contract_version IS DISTINCT FROM OLD.contract_version
    THEN RAISE EXCEPTION 'Immutable AI branch'; END IF;
  IF TG_TABLE_NAME='assistant_provider_controls' THEN
    mutable:=ARRAY['enabled','circuit_blocked','approval_reference','approval_expires_at','outcome','updated_at','revision','profile','policy'];
    IF (newj-mutable) IS DISTINCT FROM (oldj-mutable) OR NEW.revision<>OLD.revision+1
      THEN RAISE EXCEPTION 'Invalid AI control revision'; END IF;
  ELSIF TG_TABLE_NAME='assistant_provider_attempts' AND old_gateway THEN
    mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','observed_at','verification_outcome',
      'claim_strength','observed_organization_id','observed_workspace_id','observed_model_id','evidence_id',
      'receipt_key_id','receipt_mac','promotion_expires_at','promotion_disposition','committed_activated_generation'];
    IF (newj-mutable) IS DISTINCT FROM (oldj-mutable)
      OR (OLD.state<>'admitted' AND newj IS DISTINCT FROM oldj)
      OR (OLD.send_authorized_at IS NOT NULL AND NEW.send_authorized_at IS DISTINCT FROM OLD.send_authorized_at)
      THEN RAISE EXCEPTION 'Immutable AI attempt'; END IF;
  ELSIF TG_TABLE_NAME='assistant_provider_tenants' THEN
    IF (newj-'revoked_at') IS DISTINCT FROM (oldj-'revoked_at')
      THEN RAISE EXCEPTION 'Immutable AI approval'; END IF;
  ELSIF TG_TABLE_NAME='assistant_provider_acknowledgements' THEN
    IF old_gateway AND (NEW.acknowledged_at IS DISTINCT FROM OLD.acknowledged_at
      OR (OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS NULL))
      AND NEW.acknowledged_at<=OLD.acknowledged_at THEN RAISE EXCEPTION 'Nonmonotonic AI acknowledgement'; END IF;
    IF (newj-ARRAY['acknowledged_at','revoked_at']) IS DISTINCT FROM (oldj-ARRAY['acknowledged_at','revoked_at'])
      THEN RAISE EXCEPTION 'Immutable AI acknowledgement'; END IF;
  END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER ai3c_control_guard BEFORE UPDATE OR DELETE ON public.assistant_provider_controls
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_metadata_guard();
CREATE TRIGGER ai3c_attempt_guard BEFORE UPDATE OR DELETE ON public.assistant_provider_attempts
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_metadata_guard();
CREATE TRIGGER ai3c_approval_guard BEFORE UPDATE OR DELETE ON public.assistant_provider_tenants
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_metadata_guard();
CREATE TRIGGER ai3c_ack_guard BEFORE UPDATE OR DELETE ON public.assistant_provider_acknowledgements
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_metadata_guard();

CREATE FUNCTION public.ai3c_attempt_initial_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF NEW.contract_version='gateway_v1' AND (NEW.state<>'admitted' OR NEW.verification_outcome<>'not_observed'
    OR NEW.send_authorized_at IS NOT NULL OR NEW.finalized_at IS NOT NULL)
  THEN RAISE EXCEPTION 'Invalid AI admission'; END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER ai3c_attempt_initial BEFORE INSERT ON public.assistant_provider_attempts
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_attempt_initial_guard();

CREATE FUNCTION public.ai3c_window_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF TG_OP='DELETE' THEN
    IF EXISTS (SELECT 1 FROM public.assistant_provider_controls c WHERE c.budget_id=OLD.budget_id AND c.contract_version='gateway_v1')
      THEN RAISE EXCEPTION 'Retained AI usage'; END IF;
    RETURN OLD;
  END IF;
  IF NEW.reserved_attempt_units<OLD.reserved_attempt_units OR NEW.reserved_output_units<OLD.reserved_output_units
    THEN RAISE EXCEPTION 'Nonmonotonic AI usage'; END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER ai3c_window_guard BEFORE UPDATE OR DELETE ON public.assistant_provider_windows
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_window_guard();

CREATE FUNCTION public.ai3c_allocation_immutable() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN RAISE EXCEPTION 'Retained immutable AI allocation'; END $f$;
CREATE TRIGGER ai3c_allocation_immutable BEFORE UPDATE OR DELETE ON public.assistant_provider_allocations
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_allocation_immutable();

-- Restricts connection changes; no provider call or decrypt is a database operation.
CREATE FUNCTION public.ai3c_connection_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF TG_OP='INSERT' THEN
    IF NEW.active_generation<>0 OR NEW.payer_budget_id IS NOT NULL
      THEN RAISE EXCEPTION 'Invalid initial AI connection'; END IF;
  ELSE
    IF NEW.active_generation IS DISTINCT FROM OLD.active_generation
      AND (OLD.active_generation=9223372036854775807 OR NEW.active_generation<>OLD.active_generation+1 OR NEW.revision<>OLD.revision+1)
      THEN RAISE EXCEPTION 'Invalid AI generation'; END IF;
    IF OLD.payer_budget_id IS NOT NULL AND NEW.payer_budget_id IS DISTINCT FROM OLD.payer_budget_id
      THEN RAISE EXCEPTION 'Immutable AI payer'; END IF;
  END IF;
  IF NEW.payer_budget_id IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM public.assistant_provider_controls b WHERE b.budget_id=NEW.payer_budget_id
      AND b.contract_version='gateway_v1' AND b.ownership_kind='customer_managed' AND b.account_role='account'
      AND b.workspace_id=NEW.workspace_id AND b.deployment_id=NEW.deployment_id AND b.provider_key=NEW.provider_key
      AND b.account_reference IS NOT NULL
  ) THEN RAISE EXCEPTION 'Invalid AI payer binding'; END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER ai3c_connection_guard BEFORE INSERT OR UPDATE ON public.tenant_ai_connections
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_connection_guard();
ALTER TABLE public.tenant_ai_connections DROP CONSTRAINT tenant_ai_connections_payer_budget_id_check;

CREATE FUNCTION public.ai3c_credential_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE fields text[];
BEGIN
  IF NEW.last_used_at IS NOT NULL THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
  IF TG_OP='INSERT' THEN
    IF NEW.activated_generation IS NOT NULL OR NEW.last_test_operation_id IS NOT NULL
      THEN RAISE EXCEPTION 'Invalid initial AI credential'; END IF;
    RETURN NEW;
  END IF;
  IF OLD.activated_generation IS NOT NULL AND NEW.activated_generation IS DISTINCT FROM OLD.activated_generation
    THEN RAISE EXCEPTION 'Immutable activated generation'; END IF;
  IF NEW.activated_generation IS DISTINCT FROM OLD.activated_generation
    AND NOT (OLD.state='pending' AND NEW.state='active')
    THEN RAISE EXCEPTION 'Invalid activation transition'; END IF;
  fields:=ARRAY['verification_state','verified_profile_id','verification_operation_id','last_verified_at',
    'last_test_operation_id','last_tested_at','last_test_outcome'];
  IF OLD.state IN ('active','replaced','revoked') AND EXISTS (
    SELECT 1 FROM unnest(fields) AS x(k) WHERE to_jsonb(NEW)->k IS DISTINCT FROM to_jsonb(OLD)->k
  ) THEN RAISE EXCEPTION 'Immutable verification history'; END IF;
  IF OLD.verification_state<>'failed' AND NEW.verification_state='failed' AND NOT EXISTS (
    SELECT 1 FROM public.assistant_provider_attempts a WHERE a.id=NEW.last_test_operation_id
      AND a.credential_version_id=NEW.id AND a.verification_outcome='credential_rejected'
      AND a.finalized_at IS NOT NULL AND a.state<>'admitted'
  ) THEN RAISE EXCEPTION 'Unproven AI authentication rejection'; END IF;
  IF OLD.state='active' AND NEW.state IN ('replaced','revoked') AND (
    NEW.secret_destroyed_at IS NULL OR NEW.secret_ciphertext IS NOT NULL
    OR NEW.wrapped_dek IS NOT NULL OR NEW.kek_version IS NOT NULL)
    THEN RAISE EXCEPTION 'Active retirement requires destruction'; END IF;
  IF OLD.verification_state='failed' AND NEW.verification_state='unverified'
    THEN RAISE EXCEPTION 'Verification cannot infer recovery'; END IF;
  IF NEW.verification_state='verified' AND OLD.verification_state<>'verified'
    AND NOT (OLD.state='pending' AND NEW.state='active')
    THEN RAISE EXCEPTION 'Verification requires atomic promotion'; END IF;
  RETURN NEW;
END $f$;
CREATE TRIGGER ai3c_credential_guard BEFORE INSERT OR UPDATE ON public.tenant_ai_credentials
  FOR EACH ROW EXECUTE FUNCTION public.ai3c_credential_guard();

-- This is an integrity validator, not a cryptographic verifier or policy-admission routine.
CREATE FUNCTION public.ai3c_check_attempt(attempt_uuid uuid) RETURNS void LANGUAGE plpgsql AS $f$
DECLARE a public.assistant_provider_attempts%ROWTYPE; p public.ai_model_profiles%ROWTYPE;
        c public.tenant_ai_connections%ROWTYPE; v public.tenant_ai_credentials%ROWTYPE;
        g public.assistant_provider_tenants%ROWTYPE; k public.assistant_provider_acknowledgements%ROWTYPE;
        u public.assistant_provider_controls%ROWTYPE; m public.assistant_provider_controls%ROWTYPE;
        b public.assistant_provider_controls%ROWTYPE; bad boolean;
BEGIN
  SELECT * INTO a FROM public.assistant_provider_attempts WHERE id=attempt_uuid;
  IF NOT FOUND THEN RETURN; END IF;
  IF a.contract_version='legacy' THEN
    IF EXISTS (SELECT 1 FROM public.assistant_provider_allocations WHERE attempt_id=a.id)
      THEN RAISE EXCEPTION 'Legacy AI allocation forbidden'; END IF;
    RETURN;
  END IF;
  SELECT * INTO p FROM public.ai_model_profiles WHERE id=a.profile_id;
  SELECT * INTO c FROM public.tenant_ai_connections WHERE id=a.connection_id;
  SELECT * INTO v FROM public.tenant_ai_credentials WHERE id=a.credential_version_id;
  SELECT * INTO g FROM public.assistant_provider_tenants WHERE id=a.owner_approval_id;
  SELECT * INTO k FROM public.assistant_provider_acknowledgements WHERE id=a.acknowledgement_id;
  SELECT * INTO u FROM public.assistant_provider_controls WHERE budget_id=a.usage_budget_id;
  SELECT * INTO m FROM public.assistant_provider_controls WHERE budget_id=a.aggregate_budget_id;
  SELECT * INTO b FROM public.assistant_provider_controls WHERE budget_id=a.budget_id;
  IF p.id IS NULL OR c.id IS NULL OR v.id IS NULL OR g.id IS NULL OR k.id IS NULL
     OR u.budget_id IS NULL OR m.budget_id IS NULL OR b.budget_id IS NULL
    THEN RAISE EXCEPTION 'Missing AI binding'; END IF;
  IF ROW(a.workspace_id,a.deployment_id,a.provider_key,a.credential_family,a.endpoint_policy_key,a.budget_id)
      IS DISTINCT FROM ROW(c.workspace_id,c.deployment_id,c.provider_key,c.credential_family,c.endpoint_policy_key,c.payer_budget_id)
    OR ROW(a.connection_id,a.workspace_id,a.deployment_id,a.credential_version_number,a.credential_created_at)
      IS DISTINCT FROM ROW(v.connection_id,v.workspace_id,v.deployment_id,v.version_number,v.created_at)
    OR ROW(a.provider_key,a.credential_family,a.endpoint_policy_key,a.profile_manifest_sha256,a.profile,a.price_profile,a.reserved_micro_usd,a.reserved_output_units)
      IS DISTINCT FROM ROW(p.provider_key,p.credential_family,p.endpoint_policy_key,p.manifest_sha256,p.profile_key,p.price_key,p.reservation_micro_usd,p.output_envelope)
    OR NOT p.allows_customer OR NOT p.monetary_applicable OR p.currency IS DISTINCT FROM 'USD'
    OR a.admitted_at<p.valid_from OR a.admitted_at>=p.valid_until
    OR ROW(a.workspace_id,a.legal_entity_id,a.environment,a.deployment_id,a.profile_id,a.ownership_kind,a.budget_id,a.connection_id,
           a.selection_revision,a.disclosure_id,a.entitlement_revision,a.purpose,a.verifier_policy_sha256,a.account_mapping_sha256)
      IS DISTINCT FROM ROW(g.workspace_id,g.legal_entity_id,g.environment,g.deployment_id,g.profile_id,g.ownership_kind,g.account_budget_id,g.connection_id,
           g.selection_revision,g.disclosure_id,g.entitlement_revision,g.purpose,g.verifier_policy_sha256,g.account_mapping_sha256)
    OR g.contract_version<>'gateway_v1' OR k.contract_version<>'gateway_v1'
    OR ROW(a.actor_attribution_id,a.workspace_id,a.disclosure_id) IS DISTINCT FROM ROW(k.actor_attribution_id,k.workspace_id,k.disclosure_id)
    OR a.workspace_public_id IS DISTINCT FROM (SELECT public_id FROM public.workspaces WHERE id=a.workspace_id)
    OR a.legal_entity_public_id IS DISTINCT FROM (SELECT public_id FROM public.legal_entities WHERE id=a.legal_entity_id AND workspace_id=a.workspace_id)
    THEN RAISE EXCEPTION 'AI binding mismatch'; END IF;
  -- Current revisions/withdrawal/expiry/enabled state are NOT compared here: that would prevent
  -- later failure/recovery after authority changes. Runtime admission/promotion checks them freshly.
  IF u.contract_version<>'gateway_v1' OR u.deployment_id IS DISTINCT FROM a.deployment_id
    OR u.ownership_kind<>'shared_usage' OR u.account_role<>'usage'
    OR m.contract_version<>'gateway_v1' OR m.deployment_id IS DISTINCT FROM a.deployment_id
    OR m.workspace_id IS DISTINCT FROM a.workspace_id OR m.ownership_kind<>'customer_managed' OR m.account_role<>'aggregate'
    OR b.contract_version<>'gateway_v1' OR b.deployment_id IS DISTINCT FROM a.deployment_id
    OR b.workspace_id IS DISTINCT FROM a.workspace_id OR b.ownership_kind<>'customer_managed' OR b.account_role<>'account'
    OR b.provider_key IS DISTINCT FROM a.provider_key OR b.account_reference IS NULL
    OR a.day_start IS DISTINCT FROM (date_trunc('day',a.admitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')
    OR a.month_start IS DISTINCT FROM (date_trunc('month',a.admitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')
    THEN RAISE EXCEPTION 'Invalid AI allocation context'; END IF;

  IF a.verification_outcome='provider_authenticated_model_visible'
    AND a.observed_model_id IS DISTINCT FROM p.model_key
    THEN RAISE EXCEPTION 'Invalid AI observed model'; END IF;
  IF a.promotion_disposition='promoted' AND (
    v.verification_operation_id IS DISTINCT FROM a.id OR v.verification_state IS DISTINCT FROM 'verified'
    OR v.activated_generation IS DISTINCT FROM a.committed_activated_generation
    OR v.state NOT IN ('active','replaced','revoked'))
    THEN RAISE EXCEPTION 'Unmatched AI promotion'; END IF;

  WITH expected(budget_id,scope,scope_key,starts,ends,role,money,attempts,outputs) AS (
    VALUES
    (a.usage_budget_id,'deployment_month','deployment',a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC','shared_usage',0::bigint,1::bigint,a.reserved_output_units),
    (a.usage_budget_id,'deployment_day','deployment',a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC','shared_usage',0,1,a.reserved_output_units),
    (a.usage_budget_id,'workspace_month',a.workspace_id::text,a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC','shared_usage',0,1,a.reserved_output_units),
    (a.usage_budget_id,'workspace_day',a.workspace_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC','shared_usage',0,1,a.reserved_output_units),
    (a.usage_budget_id,'user_day',a.actor_attribution_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC','shared_usage',0,1,a.reserved_output_units),
    (a.aggregate_budget_id,'workspace_month',a.workspace_id::text,a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC','customer_aggregate',a.reserved_micro_usd,0,0),
    (a.aggregate_budget_id,'workspace_day',a.workspace_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC','customer_aggregate',a.reserved_micro_usd,0,0),
    (a.budget_id,'workspace_month',a.workspace_id::text,a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC','customer_account',a.reserved_micro_usd,0,0),
    (a.budget_id,'workspace_day',a.workspace_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC','customer_account',a.reserved_micro_usd,0,0)
  ), actual AS (
    SELECT w.budget_id,w.scope,w.scope_key,w.window_start,w.window_end,x.role,x.reserved_micro_usd,x.reserved_attempt_units,x.reserved_output_units
    FROM public.assistant_provider_allocations x JOIN public.assistant_provider_windows w ON w.id=x.window_id WHERE x.attempt_id=a.id
  ), difference AS ((SELECT * FROM expected EXCEPT ALL SELECT * FROM actual)
                    UNION ALL (SELECT * FROM actual EXCEPT ALL SELECT * FROM expected))
  SELECT EXISTS(SELECT 1 FROM difference) INTO bad;
  IF bad OR EXISTS(SELECT 1 FROM public.assistant_provider_allocations x WHERE x.attempt_id=a.id AND x.created_at<>a.admitted_at)
    THEN RAISE EXCEPTION 'Incomplete AI allocation set'; END IF;
END $f$;

CREATE FUNCTION public.ai3c_attempt_constraint() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF TG_TABLE_NAME='assistant_provider_allocations' THEN
    PERFORM public.ai3c_check_attempt(CASE WHEN TG_OP='DELETE' THEN OLD.attempt_id ELSE NEW.attempt_id END);
  ELSE
    PERFORM public.ai3c_check_attempt(CASE WHEN TG_OP='DELETE' THEN OLD.id ELSE NEW.id END);
  END IF;
  RETURN NULL;
END $f$;
CREATE CONSTRAINT TRIGGER ai3c_attempt_integrity AFTER INSERT OR UPDATE OR DELETE ON public.assistant_provider_attempts
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ai3c_attempt_constraint();
CREATE CONSTRAINT TRIGGER ai3c_allocation_integrity AFTER INSERT OR UPDATE OR DELETE ON public.assistant_provider_allocations
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ai3c_attempt_constraint();

CREATE FUNCTION public.ai3c_credential_constraint() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE v public.tenant_ai_credentials%ROWTYPE; a public.assistant_provider_attempts%ROWTYPE;
        c public.tenant_ai_connections%ROWTYPE; expected_test text;
BEGIN
  SELECT * INTO v FROM public.tenant_ai_credentials WHERE id=NEW.id;
  IF NOT FOUND THEN RETURN NULL; END IF;
  SELECT * INTO c FROM public.tenant_ai_connections WHERE id=v.connection_id;
  IF v.verification_state='verified' THEN
    SELECT * INTO a FROM public.assistant_provider_attempts WHERE id=v.verification_operation_id;
    IF a.id IS NULL OR a.contract_version<>'gateway_v1' OR a.purpose<>'connection_probe'
      OR a.state<>'usage_unknown' OR a.finalized_at IS NULL
      OR a.verification_outcome<>'provider_authenticated_model_visible' OR a.promotion_disposition<>'promoted'
      OR a.evidence_id IS NULL OR a.receipt_mac IS NULL OR a.receipt_key_id IS NULL
      OR v.state NOT IN ('active','replaced','revoked')
      OR ROW(v.id,v.connection_id,v.workspace_id,v.deployment_id,v.version_number,v.created_at,v.activated_generation,v.verified_profile_id)
        IS DISTINCT FROM ROW(a.credential_version_id,a.connection_id,a.workspace_id,a.deployment_id,a.credential_version_number,a.credential_created_at,a.committed_activated_generation,a.profile_id)
      OR v.last_verified_at IS DISTINCT FROM a.observed_at OR v.last_tested_at IS DISTINCT FROM a.observed_at
      OR v.last_test_outcome IS DISTINCT FROM 'success' OR v.last_test_operation_id IS DISTINCT FROM a.id
      OR (v.state='active' AND v.activated_generation IS DISTINCT FROM c.active_generation)
      THEN RAISE EXCEPTION 'Invalid AI verification relationship'; END IF;
  ELSE
    IF v.activated_generation IS NOT NULL OR v.verification_operation_id IS NOT NULL
      OR v.verified_profile_id IS NOT NULL OR v.last_verified_at IS NOT NULL
      THEN RAISE EXCEPTION 'Unverified AI activation metadata'; END IF;
    IF v.last_test_operation_id IS NULL THEN
      IF v.last_tested_at IS NOT NULL OR v.last_test_outcome IS NOT NULL OR v.verification_state<>'unverified'
        THEN RAISE EXCEPTION 'AI test evidence missing'; END IF;
    ELSE
      SELECT * INTO a FROM public.assistant_provider_attempts WHERE id=v.last_test_operation_id;
      expected_test:=CASE
        WHEN a.verification_outcome='credential_rejected' THEN 'auth_failed'
        WHEN a.verification_outcome IN ('model_unavailable','malformed_response','account_context_unproven') THEN 'protocol_failed'
        WHEN a.verification_outcome='provider_unavailable' THEN 'unavailable'
        ELSE 'unknown' END;
      IF a.id IS NULL OR a.contract_version<>'gateway_v1' OR a.purpose<>'connection_probe'
        OR a.state='admitted' OR a.finalized_at IS NULL OR a.verification_outcome IN ('not_observed','provider_authenticated_model_visible')
        OR ROW(v.id,v.connection_id,v.workspace_id,v.deployment_id) IS DISTINCT FROM ROW(a.credential_version_id,a.connection_id,a.workspace_id,a.deployment_id)
        OR v.last_tested_at IS DISTINCT FROM coalesce(a.observed_at,a.finalized_at)
        OR v.last_test_outcome IS DISTINCT FROM expected_test
        OR (a.verification_outcome='credential_rejected' AND v.verification_state<>'failed')
        THEN RAISE EXCEPTION 'Invalid AI test relationship'; END IF;
    END IF;
  END IF;
  IF TG_OP='UPDATE' AND OLD.state='active' AND v.state IN ('replaced','revoked')
    AND c.active_generation IS DISTINCT FROM OLD.activated_generation+1
    THEN RAISE EXCEPTION 'Retirement generation mismatch'; END IF;
  RETURN NULL;
END $f$;
CREATE CONSTRAINT TRIGGER ai3c_verification_authority_v1 AFTER INSERT OR UPDATE ON public.tenant_ai_credentials
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ai3c_credential_constraint();

CREATE FUNCTION public.ai3c_active_parent_constraint() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF TG_OP='UPDATE' AND NEW.active_generation IS DISTINCT FROM OLD.active_generation
    AND NOT EXISTS (SELECT 1 FROM public.tenant_ai_credentials v WHERE v.connection_id=NEW.id
      AND ((v.activated_generation=NEW.active_generation AND v.verification_state='verified')
        OR (v.activated_generation=OLD.active_generation AND v.state='revoked')))
    THEN RAISE EXCEPTION 'Generation without authority transition'; END IF;
  IF EXISTS (
    SELECT 1 FROM public.tenant_ai_credentials v JOIN public.tenant_ai_connections c ON c.id=v.connection_id
    WHERE c.id=NEW.id AND v.state='active' AND v.activated_generation IS DISTINCT FROM c.active_generation
  ) THEN RAISE EXCEPTION 'Invalid active AI generation'; END IF;
  RETURN NULL;
END $f$;
CREATE CONSTRAINT TRIGGER ai3c_active_parent AFTER INSERT OR UPDATE ON public.tenant_ai_connections
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ai3c_active_parent_constraint();

-- Keep the original initial/active CHECK, transition, ciphertext, wrap, timestamp,
-- selection and retention guards. Replace ONLY the old no_probe trigger/function.
DROP TRIGGER tai_197f2f5deff189f741f7 ON public.tenant_ai_credentials;
DROP FUNCTION public.tai_197f2f5deff189f741f7();
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_stage_closed_1;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_stage_closed_2;
ALTER TABLE public.assistant_provider_acknowledgements DROP CONSTRAINT ai3c_stage_closed_3;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_stage_closed_4;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_stage_estimate;
ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_uuid_1_ck CHECK(deployment_id IS NULL OR deployment_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_uuid_2_ck CHECK(account_reference IS NULL OR account_reference::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_uuid_3_ck CHECK(deployment_id IS NULL OR deployment_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_uuid_4_ck CHECK(profile_id IS NULL OR profile_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_uuid_5_ck CHECK(account_budget_id IS NULL OR account_budget_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_uuid_6_ck CHECK(connection_id IS NULL OR connection_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_uuid_7_ck CHECK(disclosure_id IS NULL OR disclosure_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_acknowledgements ADD CONSTRAINT ai3c_uuid_8_ck CHECK(disclosure_id IS NULL OR disclosure_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_9_ck CHECK(deployment_id IS NULL OR deployment_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_10_ck CHECK(profile_id IS NULL OR profile_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_11_ck CHECK(connection_id IS NULL OR connection_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_12_ck CHECK(credential_version_id IS NULL OR credential_version_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_13_ck CHECK(vap_reference_id IS NULL OR vap_reference_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_14_ck CHECK(disclosure_id IS NULL OR disclosure_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_15_ck CHECK(usage_budget_id IS NULL OR usage_budget_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_16_ck CHECK(aggregate_budget_id IS NULL OR aggregate_budget_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_17_ck CHECK(prior_selected_version_id IS NULL OR prior_selected_version_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_uuid_18_ck CHECK(evidence_id IS NULL OR evidence_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
ALTER TABLE public.tenant_ai_credentials ADD CONSTRAINT ai3c_uuid_19_ck CHECK(last_test_operation_id IS NULL OR last_test_operation_id::text ~ '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') NOT VALID;
```

#### M3 — staged validation

```sql
-- REVIEW LISTING ONLY. M3: 2026_10_09_020200_validate_ai_verification_integrity.php.
-- Proposed one transaction; no admission or feature-availability change.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_attempt_credential_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_attempt_prior_fk;
ALTER TABLE public.tenant_ai_credentials VALIDATE CONSTRAINT ai3c_credential_verified_attempt_fk;
ALTER TABLE public.tenant_ai_credentials VALIDATE CONSTRAINT ai3c_credential_test_attempt_fk;
ALTER TABLE public.tenant_ai_credentials VALIDATE CONSTRAINT ai3c_credential_generation_ck;
ALTER TABLE public.tenant_ai_connections VALIDATE CONSTRAINT ai3c_connection_generation_ck;
ALTER TABLE public.assistant_provider_windows VALIDATE CONSTRAINT ai3c_window_units_ck;
ALTER TABLE public.assistant_provider_controls VALIDATE CONSTRAINT ai3c_ref_1_fk;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_ref_2_fk;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_ref_3_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_ref_4_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_ref_5_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_ref_6_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_ref_7_fk;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_ref_8_fk;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_entity_fk;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_connection_fk;
ALTER TABLE public.assistant_provider_controls VALIDATE CONSTRAINT ai3c_control_shape_ck;
ALTER TABLE public.assistant_provider_acknowledgements VALIDATE CONSTRAINT ai3c_ack_shape_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_shape_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT assistant_provider_attempts_values;
ALTER TABLE public.assistant_provider_controls VALIDATE CONSTRAINT ai3c_uuid_1_ck;
ALTER TABLE public.assistant_provider_controls VALIDATE CONSTRAINT ai3c_uuid_2_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_uuid_3_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_uuid_4_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_uuid_5_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_uuid_6_ck;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_uuid_7_ck;
ALTER TABLE public.assistant_provider_acknowledgements VALIDATE CONSTRAINT ai3c_uuid_8_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_9_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_10_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_11_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_12_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_13_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_14_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_15_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_16_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_17_ck;
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT ai3c_uuid_18_ck;
ALTER TABLE public.tenant_ai_credentials VALIDATE CONSTRAINT ai3c_uuid_19_ck;
```

## 3. Enforcement matrix and old/new equivalence

| Invariant                                         | Database enforcement in proposal                                                                                                                                      | Future transaction/application obligation                                                                                                                                          |
| ------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| At most one pending and one active per connection | Preserved non-null `connection_id` partial unique indexes; generation uniqueness adds historical nonreuse                                                             | Promotion must commit one active when successful; zero active is valid before activation/after revoke. Index does not force an always-active credential.                           |
| Stable tenant/provider identity                   | Existing immutable composite connection/profile and credential context FKs; new attempt tuple and matching triggers                                                   | Primary owner/entity/production/deployment context; never browser selection, replica or foreign connection lookup                                                                  |
| No NULL bypass                                    | Whole versioned CHECK expressions use `IS TRUE`; explicit non-null tuple; optional prior selection is the only deliberate nullable credential reference               | Validate canonical inputs; no nullable shortcut to provider/account authorization                                                                                                  |
| Active means structurally verified                | Existing active CHECK plus deferred matching attempt, outcome, disposition, profile, generation and timestamps                                                        | Genuine registered one-use permit, authentic MAC, fresh policy/expiry/CAS; fully forged coherent DB rows remain unusable                                                           |
| No self-issued database authority                 | No signer, trust key, permit or decrypt in schema; immutable receipts/attempts are records only                                                                       | Private verifier-session registry, strict acquisition path and `hash_equals`; DB cannot create the registry identity or signing secret                                             |
| No replay/substitution                            | Unique evidence ID, unique credential verification operation, credential-generation unique pair, immutable admission/finalized record and bound FKs                   | One-use request/PID/fiber identity; current revisions/generation; five-second/ten-second limits; key/deployment/realm checks; consumed after rollback                              |
| Complete accounting set                           | Deferred attempt/allocation triggers require exactly five shared windows and four account/aggregate windows, exact units, UTC identity and immutable manifest reserve | Root-fenced overflow-safe increment of each window and required audit in admission TX. FK/trigger does **not** itself increment counters or check configured caps.                 |
| Quotas                                            | One unfinished admitted probe per connection; bounded history indexes                                                                                                 | Under root count at most one trailing-minute connection match/five trailing-hour workspace or A1 matches, all outcomes included                                                    |
| Generation transition                             | Positive immutable credential activation generation, nonnegative +1 connection increment, active-parent and retirement checks                                         | Exactly one active transition per transaction; check selected old authority and receipt before rotation, no generation change for pending failure                                  |
| Secret destruction                                | Preserved envelope guards; added active retirement requires all three envelope fields NULL with destruction timestamp                                                 | Retire/destroy A, activate B, update selection/generations and audit atomically; rollback restores old state. Never clear tombstones.                                              |
| Selection                                         | Preserved deferred settings/connection/credential matching integrity                                                                                                  | Settings revision and selected ID freshly match request; null selected version allowed only under valid initial/current context; explicit selection after disable is not automatic |
| Legacy invariants                                 | Old reserved 552816, token/body bounds and non-null estimate remain equivalent; old identity/terminal/counter guards retained                                         | All legacy consumers explicitly select legacy branch; no gateway consent/probe finalization in old paths                                                                           |
| Frozen evidence/retention                         | Gateway terminal attempt whole-row immutability; allocations immutable and nondeletable; old credential retention remains                                             | Backups/restore quarantined; no cleanup job or secret recovery added                                                                                                               |
| Current authorization                             | Not a CHECK and not supplied by scope/credential possession                                                                                                           | Fresh original actor/membership, strict owner/MFA/session/CSRF/reauth, code profile/account/egress approvals, disclosure and purpose                                               |
| Audit                                             | Stored state and audit share primary TX through RequiredAudit; this is a transaction guarantee, not a SQL trigger                                                     | Failure rolls back admission/send mark/promotion; fixed event fields only; no full receipt, secrets, raw upstream or financial result sets                                         |
| No inference coupling                             | `last_used_at` remains NULL; initial gateway branch is probes only                                                                                                    | Gateway/planners/transports receive no new tenant credential resolver or provider binding; production gates remain independently closed                                            |

The five original tables are retained. The only persistent new table is `assistant_provider_allocations`. All original constraints/triggers/indexes remain except the specifically replaced `assistant_provider_attempts_values`, acknowledgement global unique constraint, tenant active unique predicate, connection payer-null CHECK, and the single `no_probe` trigger/function. Widening timestamp precision and nullable physical estimated-token storage are explicitly branch-protected. No model-profile purpose is changed: the probe references an inference profile's immutable reservation/model identity, while the separate verifier manifest owns the GET protocol.

PostgreSQL defers the matching constraint triggers to transaction completion; they validate final rows, not each intermediate rotation statement. Immediate unique indexes still require retiring A before activating B. Deferred triggers cannot replace current authorization, and their ordinary reads do not establish a new locking hierarchy. [PostgreSQL CREATE TRIGGER](https://www.postgresql.org/docs/18/sql-createtrigger.html) describes this distinction.

### Compatibility defects avoided by the future source changes

1. `app/Fiscal/AssistantProviderLedger.php:83` currently uses Laravel's predicate-free three-column acknowledgement upsert. After replacing that global UNIQUE with a partial legacy index, PostgreSQL cannot infer it without the predicate. Future SQL must use `ON CONFLICT (actor_attribution_id,workspace_id,policy) WHERE contract_version='legacy' DO UPDATE ...`, or an equivalent explicitly locked legacy-only insert/update. Keeping the global unique constraint would incorrectly merge/conflict with separately scoped gateway disclosures. [PostgreSQL INSERT](https://www.postgresql.org/docs/18/sql-insert.html) defines partial-index inference.
2. Admission, notice, withdrawal, `assertAdmitted`, finalization and recovery currently lack the new discriminator. Add `contract_version='legacy'` to every legacy lookup/write. Gateway metadata success must not enter legacy inference pricing or the unknown-usage circuit path. Recovery must dispatch purpose-aware finalization; a thirty-second probe recovery never calls the two-minute legacy inference finalizer.
3. Root fencing without ongoing shared-unit accounting is insufficient. After reconciliation, each later legacy admission must reserve its original monetary windows **and** the mapped shared usage windows in one root-fenced transaction. Without this, alternating legacy/customer traffic could escape shared limits.
4. New control revision guards require all legacy control updates, including circuit changes, to increment the revision. Do not keep an old worker writing without this update/root protocol.
5. A nullable estimated-token column needs an explicit legacy non-null predicate, not just `BETWEEN`; CHECK accepts UNKNOWN otherwise. The proposal includes it.

These are migration integration requirements, not new defects introduced into the current unchanged runtime. They must be implemented and tested before applying M2 with application traffic.

## 4. Lock, race and active-rotation analysis

Canonical order remains §7: deployment roots in UUID byte order → provider/model controls in UUID order → budget controls in UUID order → settings by workspace → connections by UUID → credentials by UUID → approvals then acknowledgements by PK → windows by budget UUID, scope rank, scope-key bytes and UTC start → attempt/allocations → required audit. Use READ COMMITTED on primary. No row lock spans DNS, TLS, HTTP, remote KEK access or provider waiting.

| Operation             | Rows locked before attempt/evidence                                                       | Attempt/evidence placement                                                               | Commit obligation                                                                               |
| --------------------- | ----------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| Admission             | Root/controls/budgets/context/versions/approval/ack/windows                               | Insert unique attempt then nine allocations                                              | Counters, admission and audit together                                                          |
| Send prerequisite     | Same required context/control classes, freshly reloaded                                   | Existing attempt last                                                                    | One send marker and audit; then release locks before request bytes                              |
| Success/promotion     | Same classes; old active and candidate sorted by UUID                                     | Finalize current nonterminal attempt after context locks                                 | Receipt, A retirement/destruction, B activation, connection/settings advance and audit together |
| Failure/recovery      | Discover immutable IDs; root and persisted allocations' budget/windows in canonical order | Existing attempt last                                                                    | Never resolve today's selection as the original attempt; no refund/decrypt/resend/promotion     |
| Active revoke/disable | Root through context and affected versions                                                | Receipt is read metadata, never authority minted; no evidence write required for disable | Exact-version semantics, selection clear and generation/revision rules                          |
| Legacy coexistence    | New root first; all needed old/shared budgets and windows merged into canonical order     | Original legacy attempt last                                                             | Existing money/finalization unchanged, shared usage continuously constrained                    |

Deferred credential→attempt FKs avoid an implicit early attempt lock. Window/attempt FKs introduced after their already-locked parent rows do not require an earlier-class acquisition. Read-only immutable profiles need no update lock. Canonical constraints operate under the same application root fence; they do not provide arbitrary SQL writers with application authority. Deadlock/lock-timeout handling returns fixed failure; no automatic transaction retry creates a second send.

### Exactly-one-active proof

`connection_id` is non-null and immutable. Every active row for that connection is in `tenant_ai_credentials_one_active`, irrespective of candidate age, disabled state, provider string, expiry or current selection. Two transactions attempting active rows on the same connection cannot both commit: PostgreSQL unique-index arbitration waits/fails even if both previously observed zero. Pending, revoked and replaced rows are outside that predicate; retained verified tombstones do not block rotation. Disabled connections retain the index and may retain an active credential, but no current authority to use it. Two different connections are intentionally different active scopes; workspace settings and future resolution supply the narrower selected-use boundary.

A normal A→B transaction consumes its private permit once, verifies A's historic MAC and current generation, locks both UUIDs, and rechecks all captured revisions. It finalizes B's successful observation with promoted disposition; sets A replaced/revoked/destruction timestamps and nulls ciphertext/wrapped DEK/KEK; activates B with generation `g+1`; advances connection generation/revision and settings selection/revision; emits the ordered retirement/destruction/promotion/selection audit; commits. A is retired before B enters the immediate unique index. The deferred reciprocal matching checks require the promoted attempt and B to agree at commit. An exception at any step restores A, its envelope, selection and generation; the already admitted reservation remains, while the process permit stays consumed.

A failed B verification does not increment active generation, alter A or select B. A current HTTP 401 may mark B failed with an exact failed test reference. Transient failures cannot infer credential invalidity or reset a previously failed candidate to unverified. Successful remote observation rejected locally has a signed rejected disposition on its attempt and never marks the credential verified.

### Adversarial serializations

| Race                                                    | Outcome required by the design                                                                                                                                                                                                 |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Two A→B/A→C proposals                                   | Root/CAS and one-pending index select the current candidate. There cannot be two fresh simultaneously current verified candidates.                                                                                             |
| B returns after replacement by C                        | B attempt may record a rejected/stale observation; B cannot update C or receive activation. One unfinished probe, cooldown and permit TTL are not relaxed to manufacture a race fixture.                                       |
| Duplicate same observation/attempt                      | One registered handle consumption; terminal row/operation/evidence uniqueness rejects divergence. A second worker cannot redeem a DB receipt.                                                                                  |
| Revoke/connection disable/workspace disable wins first  | New revisions or terminal candidate invalidate captured context; no partial retirement of A. Disable→enable ABA still changes revisions.                                                                                       |
| Promotion wins first                                    | A fresh exact-B revoke can revoke B and advances generation; stale exact-A request never follows selection to B. Current disable requires fresh expected revisions.                                                            |
| Owner/membership/approval/disclosure/entitlement change | Fresh primary recheck denies promotion; immutable admission tuple remains historical. No DB trigger requires current approval to remain valid just to finalize a failed/stale attempt.                                         |
| Crash before/after send marker or response              | After thirty seconds recovery locks the attempt and finalizes unknown/abandoned, without MAC/permit/decrypt/resend/refund. Late worker cannot overwrite terminal recovery.                                                     |
| Rewrap/generation change                                | Captured wrap/revision/generation mismatch denies. A later ordinary pending creation does not invalidate A's signed historical activation generation.                                                                          |
| Full database row forgery                               | Structural constraints may be satisfiable by a sufficiently privileged DB writer; forged/unknown-key MAC and absent in-memory registry identity still deny promotion/consumption. This is explicitly not SQL HMAC enforcement. |

Historical receipt expiry is not a periodic re-verification requirement. Promotion expires within the approved limits; a previously committed receipt is historical evidence after that time. Any future inference consumer still requires its separately reviewed current invocation authority. That consumer remains absent.

## 5. Authenticated receipt layout and immutable vectors

The exact field order and two complete canonical byte vectors are in the JSON artifact. Index zero is `schema_version`; all identifiers are strings, bigint values are canonical unsigned decimal strings within signed bigint bounds, and absent values are JSON null. There are no JSON objects in the receipt. Authority references are sorted by ASCII `kind:id`, distinct, limited to sixteen, and include all controls/budgets/grants actually relied upon; the vectors intentionally use an incomplete synthetic reference set and are **serialization vectors, not admissible verification evidence**.

Encoding: UTF-8 without BOM; compact JSON arrays with separators `,` and `:`; no whitespace, floats, numeric bigint tokens, optional omissions or slash escaping; ASCII closed identifiers avoid Unicode normalization ambiguity. UTC times are exactly `YYYY-MM-DDTHH:MM:SS.ffffffZ`, rejecting alternate timezone/precision forms. Prefix bytes are ASCII `facturac:tenant-ai:verification:v1` followed by one LF (hex `0a`), not backslash-plus-n. Receipt input including prefix must be at most 8,192 bytes. Validate before signing. The HMAC is lowercase hexadecimal SHA-256 over these bytes with a dedicated protected 32-byte receipt key, never KEK or APP_KEY. A DB key ID selects only a code-allowlisted trust record, never a path/algorithm.

The published vector key is **32 public zero bytes**, non-operational and never provisioned. It verifies algorithm/encoding interoperability without reading or creating any provider credential or deployment signing key. Both vectors have rejected disposition and null committed generation. Computing their digest does not instantiate a verifier, permit, database attempt or synthetic promotion.

`rotation_observation_rejected`: 1,364 bytes; SHA-256 `efc83374be6ae120b9a2411253922292d44b1bfc77ca672e560bda1d5918cbce`.

`null_prior_and_large_decimal`: 1,345 bytes; SHA-256 `b419a05dd2a84985f1ae2b8b2030b8f589a12479050b27be5e9ed16efb66c0f9`. It tests explicit null and `9007199254740993` as a string, above JavaScript's exact integer range.

Future tests must mutate every position; reorder/change/add/remove authority references; change context/version/wrap/claim/model/times/disposition/generation; reject unknown/retired signer, wrong deployment or fixture realm in production; test 8,192/8,193 bytes and 16/17 references; and compare independently implemented PHP canonicalization against these bytes. No vector grants authority merely because its HMAC is computable.

## 6. Reconciliation and populated-data migration

No existing row is upgraded to verified/active, converted from legacy to gateway, or granted consent. The new fields take their exact inert defaults. Existing pending/revoked records remain below active. Fresh installs traverse the same historical migrations before M1–M3; do not collapse them into an edited schema helper.

The reconciliation listing follows. It is parameterized by an independently reviewed, exhaustive immutable mapping from each legacy budget UUID to its deployment/root and canonical shared-usage budget. No actual mapping, customer account/profile/grant, cap or deployment ID is inferred or materialized here. Missing, ambiguous or unmapped history refuses reconciliation and keeps admission closed. Multi-deployment maintenance acquires all roots in UUID order, or must be separately partitioned with equivalent exhaustive inventory controls.

For each retained legacy attempt in **every state**, expand its original five UTC windows, original A1 user scope, one attempt unit and fixed 1,024 output envelope. Verify old window reservation/attempt inclusion against history before touching only the new shared usage counters. Existing actual-money/token counters are preserved, not recomputed from model metadata. Unknown or missing history with unexplained liability stops for operator reconciliation; never subtract old liability to make a comparison pass.

The temporary tables are transaction-local calculation/input tables, not a persisted checkpoint or second ledger. All sums use numeric intermediates followed by checked bigint casts; overflow aborts. The operation sets exact derived shared units only where existing units are no greater, rejects unexplained larger/extra shared counters, and never decrements. Repeating before generalized traffic is idempotent. Once gateway attempts exist, this initial procedure refuses; it cannot overwrite their usage. It does not modify old attempts, windows' original money, IDs, approvals, acknowledgements or actual usage. It must commit its required bounded audit with the update. Any statement/lock timeout rolls the entire transaction back.

**Ongoing coexistence is mandatory:** the immutable reviewed mapping remains used by future legacy admissions. They increment mapped shared units atomically with original legacy monetary reservations, under the same root and merged canonical budget/window locks. Legacy finalization never refunds these units. VAP aggregate admission checks include the distinct retained legacy budget windows plus new VAP allocation windows under applicable deployment/day/month scopes; deduplicate budget identities, use exact integer amounts and never sum overlapping scope/role ceilings as payable money. There is no VAP gateway admission in 3c-1, but tests must prove legacy usage cannot disappear from its future aggregate and current shared ceilings. New legacy budgets without a reviewed mapping deny generalized coexistence. Changing the immutable mapping after usage exists requires a new reconciliation review, not tenant configuration.

The SQL is intentionally not a seed: its prepared input has no EXECUTE values and creates no controls. Rehearsal after DDL approval must supply synthetic reviewed controls/mapping in a disposable database. Production requires its own authorized input and capacity rehearsal.

### Reconciliation coverage remediation R1 — independent review history retained

The independent concrete-DDL review **FAILED** with one MEDIUM finding at the former attempt-only completeness check (line 985) and mapping-inner-joined retained-window check (line 1015). The source universe was incorrectly restricted by the mapping under review. A budget with retained counters but no surviving attempts could disappear. This revision addresses that finding only; it is **awaiting independent re-review**, not accepted DDL or permission to implement.

The inspected legacy migration `2026_10_08_183139_create_assistant_provider_metadata_tables.php` defines six bigint NOT NULL DEFAULT 0 window counters and `assistant_provider_windows_values` requires each to be nonnegative. Its transition trigger prevents decreases. `AssistantProviderLedger::reserve()` increments reservations and attempts in each of five windows; `finalize()` increments actual token/money counters and increments unknown usage only for unknown, potentially sent work. These overlapping window scopes are ceilings, not amounts to sum together into payable money.

| Legacy table                                  | Column                                                                                      | Meaning                                                                           | Zero neutral?                                               | Mapping/explanation when nonzero?                                                                               |
| --------------------------------------------- | ------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------- | ----------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------- |
| assistant_provider_windows                    | reserved_micro_usd                                                                          | Retained conservative monetary reservation, not a refund-adjusted balance         | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows                    | attempt_count                                                                               | Every admitted attempt, including failed/unsent/unknown                           | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows                    | actual_input_tokens                                                                         | Reported cumulative input usage                                                   | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows                    | actual_output_tokens                                                                        | Reported cumulative output usage                                                  | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows                    | actual_micro_usd                                                                            | Cumulative recorded actual charge                                                 | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows                    | unknown_usage_count                                                                         | Finalizations with unknown usage, excluding explicitly not-sent work              | Yes for this counter alone                                  | Yes                                                                                                             |
| assistant_provider_windows after M1           | reserved_attempt_units                                                                      | New shared-unit counter; absent from historical schema, default zero              | Yes                                                         | Yes; nonzero on a legacy budget is unexplained by this initial procedure and refuses                            |
| assistant_provider_windows after M1           | reserved_output_units                                                                       | New output-envelope counter; absent from historical schema, default zero          | Yes                                                         | Yes; same refusal rule                                                                                          |
| assistant_provider_attempts                   | reserved_micro_usd                                                                          | Immutable per-attempt reserved money; source A                                    | No valid zero; legacy requires 552816                       | Every surviving legacy attempt requires mapping, regardless of final state                                      |
| assistant_provider_attempts                   | input_tokens, output_tokens, actual_micro_usd                                               | Nullable per-attempt reported results used to explain corresponding window totals | Zero means a reported zero; NULL is not proof of zero usage | Every surviving attempt remains in A; inconsistent terminal/result combinations refuse                          |
| assistant_provider_controls                   | budget_id                                                                                   | Retained budget identity, not an amount                                           | Not numeric liability                                       | Required if referenced by A or B; zero-use controls may remain unmapped                                         |
| assistant_provider_controls                   | enabled, circuit_blocked, profile, policy, approval_reference, approval_expires_at, outcome | Authority/policy/status metadata, not counters                                    | Not applicable                                              | Never used to erase or exclude liability, including disabled/expired budgets                                    |
| assistant_provider_tenants / acknowledgements | approval/consent fields                                                                     | Authorization evidence, not usage balances                                        | Not applicable                                              | Never converted or fabricated by reconciliation                                                                 |
| assistant_provider_allocations after M1       | reserved_micro_usd, reserved_attempt_units, reserved_output_units                           | New immutable allocation liabilities                                              | Role-dependent                                              | Table must be empty for this initial reconciliation; any row refuses, rather than being reinterpreted as legacy |

**Independent source sets:** A is every legacy budget referenced by surviving legacy attempts. B is every legacy budget whose retained window has any of the eight post-M1 counters `IS DISTINCT FROM 0`, discovered directly from windows and their actual controls, never through `ai3c_legacy_map`. The required budget universe is A UNION B. Window identity remains `(budget_id, scope, scope_key, window_start)` with exact `window_end`; covering a budget does not excuse a missing or inconsistent window.

All eight counters are checked for NULL/negative values before coverage. The six historical counters cannot legitimately contain those values with the inspected NOT NULL/CHECK constraints enabled; M1/M2 similarly constrain the two new counters. The defensive preflight nevertheless refuses malformed restored data. `IS DISTINCT FROM 0`, rather than `>0`, ensures malformed values cannot silently define a neutral source. Missing control references also refuse. Nonnegative but inconsistent totals are possible under the original schema; they must be explained exactly by surviving history or refused.

The SQL now performs an A UNION B anti-join against the candidate mapping before any persistent writes. Retained-window validation starts from authoritative windows/controls and LEFT JOINs both mapping and derived history, explicitly rejecting missing matches. For each liable window it compares all six historical counters to surviving attempt evidence, not only reservations/attempt counts. Known result fields are summed from recorded values; unknown finalizations are counted from validated legacy states. NULL actual fields on admitted/not-sent/unknown attempts contribute nothing to _reported actual counters_; this never asserts zero provider cost or releases the positive reserved liability. Unknown usage remains represented explicitly. Unrecognized or internally inconsistent attempt-result combinations refuse rather than guessing.

**Conservation:** original counters, attempts, identities, consent and envelopes remain byte/value-identical. Reconciliation may add only the exact missing shared attempt/output units derived from complete surviving admissions. It cannot claim success unless every liability-bearing source is covered and its retained counters agree with that history. Missing attribution is refusal, not a conservative allocation invented here. Supplement §6 authorizes adding shared usage from admitted attempts; it supplies no alternative operator-explanation/aggregate-only backfill mechanism. A complete mapping for an orphan-liability budget therefore still refuses. A human note does not replace lost attempt evidence.

A zero-liability orphan (no attempts, all eight counters exactly zero, no mapping) may remain unmapped and unchanged. It carries neither A nor B liability. This does not authorize future admission under that budget: ongoing coexistence still requires a reviewed mapping before any new usage.

**Cardinality/context:** the temporary mapping PK rejects duplicate source budgets; a source maps to one deployment/shared budget. Several source budgets may map to the same canonical shared budget only within the same reviewed deployment. Existing checks reject nonexistent/nonlegacy sources, wrong ownership/role, wrong destination deployment and conflicting shared destinations for one deployment. Exact window identities prevent tenant/scope/window substitution during accounting comparison. Legacy rows do not record deployment identity: the independently reviewed immutable source-budget→deployment manifest remains an external prerequisite, not something SQL can infer from a workspace or provider string. A mapping with a mutually consistent but false root assignment is not authenticated merely by passing FK checks; missing/stale/unproven manifest provenance must refuse before invoking this procedure. This remediation neither invents a new root field nor weakens that accepted prerequisite.

**Order/refusal:** all writers remain closed and drained. Acquire ordered roots, then ordered budgets, exactly as before. Coverage and all source/destination comparison checks complete before the first persistent INSERT/UPDATE. Source reads acquire no attempt-row locks or out-of-order budget/window locks. The procedure does not lock a discovered unmapped source after locking windows; it refuses immediately. After preflight, existing canonical ordered window insertion/locking/update remains unchanged. The repeated per-row check is defense in depth. Any later race/constraint/timeout/audit failure still rolls the entire transaction back. Temporary calculation tables and caller-supplied mapping input are not persisted reconciliation state; no mapping is generated from discovered orphans.

**Inherited debt:** the original attempt identity/terminal triggers cover UPDATE, not DELETE. This remediation does not add DELETE protection or change runtime code. It detects the retained counters that deleting historical attempts can leave behind.

Mandatory proposed fixtures, all **NOT RUN**:

| ID    | Construction                                                                                                                                                             | Expected result and exact checks                                                                                                                                                                                                                           |
| ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| R1-01 | Budget B, no surviving attempts, omitted mapping, a retained window with reserved_micro_usd=552816, attempt_count=1, unknown_usage_count=1; other counters zero          | REFUSED during coverage, before persistent writes. Compare all source counters, controls, existing destination rows, allocation/attempt/evidence/credential/audit rows exactly before/after. No manufactured mapping, attempt, allocation or verification. |
| R1-02 | Repeat R1-01 with each of the eight counters individually nonzero                                                                                                        | REFUSED for each field, even if the combination is not producible by the ordinary application; do not classify it as neutral.                                                                                                                              |
| R1-03 | Same orphan B but supply a syntactically valid, independently reviewed mapping and an operator explanation                                                               | Still REFUSED: §6 provides no aggregate-only/missing-history allocation authority. This is the requested missing-history “positive counterpart”; it is not a permitted success case.                                                                       |
| R1-04 | Complete valid attempt history and all five corresponding retained windows; exact six-counter totals and approved mapping                                                | Success permitted after all other gates; derive exact shared units, preserve old values, second run idempotent. No live provider activity.                                                                                                                 |
| R1-05 | Valid legacy admission/finalization fixture; delete its attempts through the inherited DELETE gap while retaining windows; omit mapping, then separately include mapping | Omitted mapping refuses at coverage; supplied mapping refuses at history comparison. No mutation of retained liability in either case.                                                                                                                     |
| R1-06 | No attempts, all eight window counters zero, omitted mapping                                                                                                             | Source remains unmapped/unchanged; it alone does not block another fully valid mapped source. If there is no approved mapping at all, existing empty-input refusal remains.                                                                                |
| R1-07 | Partial history, extra window, wrong scope/key/start/end, mismatched actual token/money or unknown counter totals                                                        | REFUSED before any destination writes; no inferred lower liability.                                                                                                                                                                                        |
| R1-08 | Duplicate/conflicting mapping, nonexistent source, wrong destination role/deployment/root provenance, stale manifest, cross-tenant window substitution                   | REFUSED by PK, preflight identity checks, exact window checks or the required trusted-manifest prerequisite, as applicable. Do not credit SQL with authenticating an unstored historical root.                                                             |
| R1-09 | NULL/negative counters attempted with normal constraints; separately reviewed corrupt-restore fixture                                                                    | Ordinary writes rejected by actual constraints. Corrupt-restore preflight refuses; no production constraint bypass is added.                                                                                                                               |
| R1-10 | Valid source first, omitted liable source later; existing destination mismatch/extra counters; induced audit failure after accepted preflight                            | Completeness/source/destination faults precede every persistent write. Audit failure rolls back all writes. Row counts and exact values including audit remain unchanged after refusal.                                                                    |

For refusal fixtures, snapshot all six original counters plus both new counters by exact window identity; mapping input bytes/rows; shared windows; allocations; attempts/receipt fields; credential state/envelopes; controls; and required audit. The procedure must not repair sources. Temporary input may be staged before a test savepoint so rollback-to-savepoint permits exact input-row comparison; whole transaction rollback instead discards temporary tables without changing the caller's immutable input or any persistent mapping (none exists). No future SQL/runtime fixture has been executed in this documentation task.

The exactly-one-active index, receipt vectors/47 signed positions, MAC/bindings, active rotation, simultaneous promotion, canonical order, custody, all up/down DDL, forward-only policy, gateway isolation and activation separation are unchanged. Previous independent evidence that both vectors reproduce and 47/47 signed-position mutations invalidate the original MAC is retained; no vector was regenerated.

```sql
-- REVIEW TEMPLATE ONLY: not a migration, command, grant materializer or runnable seed.
-- Future approved operator procedure uses ONE transaction, all admission closed,
-- all legacy workers drained. Populate the map from an independently reviewed manifest.
-- The map is temporary input, not another persistent ledger.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
CREATE TEMP TABLE ai3c_legacy_map (
  legacy_budget_id uuid PRIMARY KEY,
  deployment_id uuid NOT NULL,
  usage_budget_id uuid NOT NULL
) ON COMMIT DROP;
PREPARE ai3c_load_map(jsonb) AS
  INSERT INTO ai3c_legacy_map
  SELECT legacy_budget_id,deployment_id,usage_budget_id
  FROM jsonb_to_recordset($1) AS x(legacy_budget_id uuid,deployment_id uuid,usage_budget_id uuid);
-- EXECUTE is deliberately absent: there is no authorized real mapping or customer grant.
-- After a separately approved manifest is loaded, execute the following in the same transaction.
DO $reconcile$
DECLARE r record;
BEGIN
  IF NOT EXISTS(SELECT 1 FROM ai3c_legacy_map)
    OR EXISTS(SELECT deployment_id FROM ai3c_legacy_map GROUP BY deployment_id HAVING count(DISTINCT usage_budget_id)<>1)
    THEN RAISE EXCEPTION 'AI reconciliation mapping required'; END IF;
  FOR r IN SELECT c.id FROM public.ai_gateway_controls c
    WHERE c.kind='global' AND c.deployment_id IN (SELECT deployment_id FROM ai3c_legacy_map)
    ORDER BY c.id FOR UPDATE LOOP NULL; END LOOP;
  IF EXISTS(SELECT 1 FROM ai3c_legacy_map m WHERE NOT EXISTS(SELECT 1 FROM public.ai_gateway_controls c
    WHERE c.deployment_id=m.deployment_id AND c.kind='global'))
    THEN RAISE EXCEPTION 'AI reconciliation root missing'; END IF;
  FOR r IN SELECT c.budget_id FROM public.assistant_provider_controls c
    WHERE c.budget_id IN (SELECT legacy_budget_id FROM ai3c_legacy_map UNION SELECT usage_budget_id FROM ai3c_legacy_map)
    ORDER BY c.budget_id FOR UPDATE LOOP NULL; END LOOP;
  -- Coverage comes from authoritative sources, never from the candidate mapping.
  -- All writers are closed/drained; roots and mapped budgets are already locked.
  IF EXISTS(SELECT 1 FROM public.assistant_provider_windows w
    LEFT JOIN public.assistant_provider_controls c ON c.budget_id=w.budget_id
    WHERE c.budget_id IS NULL OR (c.contract_version='legacy' AND (w.reserved_micro_usd IS NULL OR w.reserved_micro_usd<0 OR w.attempt_count IS NULL OR w.attempt_count<0 OR w.actual_input_tokens IS NULL OR w.actual_input_tokens<0 OR w.actual_output_tokens IS NULL OR w.actual_output_tokens<0 OR w.actual_micro_usd IS NULL OR w.actual_micro_usd<0 OR w.unknown_usage_count IS NULL OR w.unknown_usage_count<0 OR w.reserved_attempt_units IS NULL OR w.reserved_attempt_units<0 OR w.reserved_output_units IS NULL OR w.reserved_output_units<0)))
    THEN RAISE EXCEPTION 'AI corrupt retained counters'; END IF;
  IF EXISTS (
    SELECT 1 FROM (
      SELECT a.budget_id FROM public.assistant_provider_attempts a WHERE a.contract_version='legacy'
      UNION
      SELECT w.budget_id FROM public.assistant_provider_windows w
        JOIN public.assistant_provider_controls c ON c.budget_id=w.budget_id
        WHERE c.contract_version='legacy' AND (w.reserved_micro_usd IS DISTINCT FROM 0 OR w.attempt_count IS DISTINCT FROM 0 OR w.actual_input_tokens IS DISTINCT FROM 0 OR w.actual_output_tokens IS DISTINCT FROM 0 OR w.actual_micro_usd IS DISTINCT FROM 0 OR w.unknown_usage_count IS DISTINCT FROM 0 OR w.reserved_attempt_units IS DISTINCT FROM 0 OR w.reserved_output_units IS DISTINCT FROM 0)
    ) required_budget
    WHERE NOT EXISTS(SELECT 1 FROM ai3c_legacy_map m WHERE m.legacy_budget_id=required_budget.budget_id)
  ) THEN RAISE EXCEPTION 'AI incomplete liability mapping'; END IF;
  IF EXISTS(SELECT 1 FROM ai3c_legacy_map m
    LEFT JOIN public.assistant_provider_controls old ON old.budget_id=m.legacy_budget_id
    LEFT JOIN public.assistant_provider_controls u ON u.budget_id=m.usage_budget_id
    WHERE old.budget_id IS NULL OR old.contract_version<>'legacy'
      OR u.budget_id IS NULL OR u.contract_version<>'gateway_v1'
      OR u.deployment_id IS DISTINCT FROM m.deployment_id
      OR u.ownership_kind<>'shared_usage' OR u.account_role<>'usage')
    OR EXISTS(SELECT 1 FROM public.assistant_provider_attempts a
      WHERE a.contract_version='legacy' AND NOT EXISTS(SELECT 1 FROM ai3c_legacy_map m WHERE m.legacy_budget_id=a.budget_id))
    OR EXISTS(SELECT 1 FROM public.assistant_provider_attempts WHERE contract_version='gateway_v1')
    OR EXISTS(SELECT 1 FROM public.assistant_provider_allocations)
    THEN RAISE EXCEPTION 'AI reconciliation precondition failed'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts a JOIN ai3c_legacy_map m ON m.legacy_budget_id=a.budget_id
    WHERE a.contract_version<>'legacy' OR a.profile<>'p7-anthropic-haiku55-us-2026-10-08-v1'
      OR a.price_profile<>'p7-price-haiku55-us-2026-10-08-v1' OR a.policy<>'p7-question-v1'
      OR a.reserved_micro_usd<>552816
      OR NOT ((
        (a.state='admitted' AND a.finalized_at IS NULL AND a.outcome IS NULL
          AND a.input_tokens IS NULL AND a.output_tokens IS NULL AND a.actual_micro_usd IS NULL)
        OR (a.state='usage_unknown' AND a.finalized_at IS NOT NULL AND a.outcome='usage_unknown'
          AND a.input_tokens IS NULL AND a.output_tokens IS NULL AND a.actual_micro_usd IS NULL)
        OR (a.state='failed' AND a.finalized_at IS NOT NULL AND a.outcome='not_sent'
          AND a.input_tokens IS NULL AND a.output_tokens IS NULL AND a.actual_micro_usd IS NULL)
        OR (a.finalized_at IS NOT NULL AND a.input_tokens IS NOT NULL AND a.output_tokens IS NOT NULL
          AND a.actual_micro_usd IS NOT NULL AND ((a.state='received' AND a.outcome='received')
            OR (a.state='failed' AND a.outcome IN ('failed','profile_mismatch'))))
      ) IS TRUE)
      OR a.day_start IS DISTINCT FROM (date_trunc('day',a.admitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC')
      OR a.month_start IS DISTINCT FROM (date_trunc('month',a.admitted_at AT TIME ZONE 'UTC') AT TIME ZONE 'UTC'))
    THEN RAISE EXCEPTION 'AI reconciliation history mismatch'; END IF;
END $reconcile$;
CREATE TEMP TABLE ai3c_history ON COMMIT DROP AS
  SELECT a.budget_id,m.usage_budget_id,z.scope,z.scope_key,z.starts,z.ends,
    count(*)::bigint AS attempts,sum(1024::numeric)::bigint AS outputs,
    sum(a.reserved_micro_usd::numeric)::bigint AS money,
    sum(coalesce(a.input_tokens,0)::numeric)::bigint AS actual_inputs,
    sum(coalesce(a.output_tokens,0)::numeric)::bigint AS actual_outputs,
    sum(coalesce(a.actual_micro_usd,0)::numeric)::bigint AS actual_money,
    sum(CASE WHEN a.state='usage_unknown' THEN 1::numeric ELSE 0::numeric END)::bigint AS unknowns
  FROM public.assistant_provider_attempts a JOIN ai3c_legacy_map m ON m.legacy_budget_id=a.budget_id
  CROSS JOIN LATERAL (VALUES
    ('deployment_month','deployment',a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC'),
    ('deployment_day','deployment',a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC'),
    ('workspace_month',a.workspace_id::text,a.month_start,((a.month_start AT TIME ZONE 'UTC')+interval '1 month') AT TIME ZONE 'UTC'),
    ('workspace_day',a.workspace_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC'),
    ('user_day',a.actor_attribution_id::text,a.day_start,((a.day_start AT TIME ZONE 'UTC')+interval '1 day') AT TIME ZONE 'UTC')
  ) z(scope,scope_key,starts,ends)
  GROUP BY a.budget_id,m.usage_budget_id,z.scope,z.scope_key,z.starts,z.ends;
DO $reconcile$
BEGIN
  IF EXISTS(SELECT 1 FROM ai3c_history h LEFT JOIN public.assistant_provider_windows w
    ON ROW(w.budget_id,w.scope,w.scope_key,w.window_start)=ROW(h.budget_id,h.scope,h.scope_key,h.starts)
    WHERE w.id IS NULL OR w.window_end<>h.ends OR w.reserved_micro_usd<>h.money OR w.attempt_count<>h.attempts)
    OR EXISTS(SELECT 1 FROM public.assistant_provider_windows w
      JOIN public.assistant_provider_controls c ON c.budget_id=w.budget_id
      LEFT JOIN ai3c_legacy_map m ON m.legacy_budget_id=w.budget_id
      LEFT JOIN ai3c_history h ON ROW(w.budget_id,w.scope,w.scope_key,w.window_start)
        =ROW(h.budget_id,h.scope,h.scope_key,h.starts)
      WHERE c.contract_version='legacy' AND (w.reserved_micro_usd IS DISTINCT FROM 0 OR w.attempt_count IS DISTINCT FROM 0 OR w.actual_input_tokens IS DISTINCT FROM 0 OR w.actual_output_tokens IS DISTINCT FROM 0 OR w.actual_micro_usd IS DISTINCT FROM 0 OR w.unknown_usage_count IS DISTINCT FROM 0 OR w.reserved_attempt_units IS DISTINCT FROM 0 OR w.reserved_output_units IS DISTINCT FROM 0)
        AND (m.legacy_budget_id IS NULL OR h.budget_id IS NULL
          OR w.window_end IS DISTINCT FROM h.ends
          OR w.reserved_micro_usd IS DISTINCT FROM h.money
          OR w.attempt_count IS DISTINCT FROM h.attempts
          OR w.actual_input_tokens IS DISTINCT FROM h.actual_inputs
          OR w.actual_output_tokens IS DISTINCT FROM h.actual_outputs
          OR w.actual_micro_usd IS DISTINCT FROM h.actual_money
          OR w.unknown_usage_count IS DISTINCT FROM h.unknowns
          OR w.reserved_attempt_units IS DISTINCT FROM 0 OR w.reserved_output_units IS DISTINCT FROM 0))
    THEN RAISE EXCEPTION 'AI legacy liability reconciliation required'; END IF;
END $reconcile$;
CREATE TEMP TABLE ai3c_shared_target ON COMMIT DROP AS
  SELECT usage_budget_id AS budget_id,scope,scope_key,starts,ends,
    sum(attempts::numeric)::bigint AS attempts,sum(outputs::numeric)::bigint AS outputs
  FROM ai3c_history GROUP BY usage_budget_id,scope,scope_key,starts,ends;
DO $reconcile$
DECLARE r record; shared_window_row public.assistant_provider_windows%ROWTYPE;
BEGIN
  -- Full destination preflight also precedes all persistent accounting writes.
  IF EXISTS(SELECT 1 FROM public.assistant_provider_windows w JOIN ai3c_shared_target t
    ON ROW(w.budget_id,w.scope,w.scope_key,w.window_start)=ROW(t.budget_id,t.scope,t.scope_key,t.starts)
    WHERE w.window_end IS DISTINCT FROM t.ends
      OR w.reserved_attempt_units IS NULL OR w.reserved_attempt_units<0 OR w.reserved_attempt_units>t.attempts
      OR w.reserved_output_units IS NULL OR w.reserved_output_units<0 OR w.reserved_output_units>t.outputs
      OR w.reserved_micro_usd IS DISTINCT FROM 0 OR w.actual_micro_usd IS DISTINCT FROM 0
      OR w.actual_input_tokens IS DISTINCT FROM 0 OR w.actual_output_tokens IS DISTINCT FROM 0
      OR w.attempt_count IS DISTINCT FROM 0 OR w.unknown_usage_count IS DISTINCT FROM 0)
    THEN RAISE EXCEPTION 'AI shared usage requires reconciliation'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_windows w
    WHERE w.budget_id IN (SELECT usage_budget_id FROM ai3c_legacy_map)
      AND NOT EXISTS(SELECT 1 FROM ai3c_shared_target t
        WHERE ROW(w.budget_id,w.scope,w.scope_key,w.window_start)=ROW(t.budget_id,t.scope,t.scope_key,t.starts))
      AND (w.reserved_micro_usd IS DISTINCT FROM 0 OR w.attempt_count IS DISTINCT FROM 0 OR w.actual_input_tokens IS DISTINCT FROM 0 OR w.actual_output_tokens IS DISTINCT FROM 0 OR w.actual_micro_usd IS DISTINCT FROM 0 OR w.unknown_usage_count IS DISTINCT FROM 0 OR w.reserved_attempt_units IS DISTINCT FROM 0 OR w.reserved_output_units IS DISTINCT FROM 0))
    THEN RAISE EXCEPTION 'AI unexplained shared usage'; END IF;
  -- Missing target rows may be inserted only under the root and budget locks above.
  FOR r IN SELECT * FROM ai3c_shared_target ORDER BY budget_id,
    CASE scope WHEN 'deployment_month' THEN 0 WHEN 'deployment_day' THEN 1 WHEN 'workspace_month' THEN 2
      WHEN 'workspace_day' THEN 3 ELSE 4 END,scope_key COLLATE "C",starts LOOP
    INSERT INTO public.assistant_provider_windows(budget_id,scope,scope_key,window_start,window_end)
      VALUES(r.budget_id,r.scope,r.scope_key,r.starts,r.ends)
      ON CONFLICT(budget_id,scope,scope_key,window_start) DO NOTHING;
    SELECT * INTO shared_window_row FROM public.assistant_provider_windows
      WHERE ROW(budget_id,scope,scope_key,window_start)=ROW(r.budget_id,r.scope,r.scope_key,r.starts) FOR UPDATE;
    IF shared_window_row.window_end<>r.ends OR shared_window_row.reserved_attempt_units>r.attempts OR shared_window_row.reserved_output_units>r.outputs
      OR shared_window_row.reserved_micro_usd<>0 OR shared_window_row.actual_micro_usd<>0 OR shared_window_row.actual_input_tokens<>0 OR shared_window_row.actual_output_tokens<>0
      THEN RAISE EXCEPTION 'AI shared usage requires reconciliation'; END IF;
    UPDATE public.assistant_provider_windows SET reserved_attempt_units=r.attempts,reserved_output_units=r.outputs WHERE id=shared_window_row.id;
  END LOOP;

END $reconcile$;
DEALLOCATE ai3c_load_map;
-- Required metadata-only reconciliation audit and postconditions run before COMMIT.
-- No COMMIT supplied here: this listing is not an execution authorization.
```

## 7. Down, restore and forward-only boundary

The safe pre-authority rollback order is **M3 → M2 → M1**. M3 down is deliberately a no-op: it does not make validated constraints untrusted again. M2 down and M1 down each run the shared preflight in the same closed-admission transaction. M2 restores the exact old `no_probe` definition first, removes successor triggers before their functions, removes dependent CHECKs before the shape function, restores original uniqueness and attempt/payer CHECKs, and reinstates M1 branch-closed checks. M1 removes referencing FKs before the referenced unique tuple/allocation table, then indexes, columns and precision changes. No CASCADE is used.

Down refuses if any gateway control/grant/ack/attempt, allocation, receipt, test evidence, activation generation, payer binding, selected credential or nonzero new shared unit exists. It also refuses changed retained control revisions or timestamp values whose microseconds would be lost. These conservative refusals preserve evidence rather than inventing an automatic rollback for it. It is not enough that there is no _currently active_ credential: a revoked verified tombstone still crosses the forward-only boundary.

Once 3c-1 data exists, operational rollback means close admission/egress, retain the compatible schema and root-aware metadata recovery, investigate, then deploy a reviewed forward correction. Do not run old unfenced binaries against an open generalized ledger. Do not resurrect a destroyed key, rewrite verified→unverified, delete receipts, move old IDs or drop allocations. Backup restore is separately quarantined until key retirement, retained liability, policy revisions and revoked/active state are reconciled against later evidence. No automatic restore or state conversion is authorized by this package.

The following down listings are exact inverse proposals only for preflight-safe data. Shared preflight is required for both M2 and M1; do not interpret any statement independently as authorization to downgrade.

#### Shared safe-down preflight

```sql
-- REVIEW LISTING ONLY. Shared down preflight, before M2 or M1 downgrade.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
DO $down$
BEGIN
  IF EXISTS(SELECT 1 FROM public.assistant_provider_allocations) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_controls WHERE contract_version IS DISTINCT FROM 'legacy' OR revision IS DISTINCT FROM 1 OR deployment_id IS NOT NULL OR ownership_kind IS NOT NULL OR account_role IS NOT NULL OR workspace_id IS NOT NULL OR provider_key IS NOT NULL OR account_reference IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_windows WHERE reserved_attempt_units IS DISTINCT FROM 0 OR reserved_output_units IS DISTINCT FROM 0) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_tenants WHERE contract_version IS DISTINCT FROM 'legacy' OR legal_entity_id IS NOT NULL OR environment IS NOT NULL OR deployment_id IS NOT NULL OR profile_id IS NOT NULL OR ownership_kind IS NOT NULL OR account_budget_id IS NOT NULL OR connection_id IS NOT NULL OR selection_revision IS NOT NULL OR disclosure_id IS NOT NULL OR entitlement_revision IS NOT NULL OR purpose IS NOT NULL OR verifier_policy_sha256 IS NOT NULL OR account_mapping_sha256 IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_acknowledgements WHERE contract_version IS DISTINCT FROM 'legacy' OR disclosure_id IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts WHERE contract_version IS DISTINCT FROM 'legacy' OR deployment_id IS NOT NULL OR provider_key IS NOT NULL OR ownership_kind IS NOT NULL OR profile_id IS NOT NULL OR selection_revision IS NOT NULL OR connection_id IS NOT NULL OR credential_version_id IS NOT NULL OR vap_reference_id IS NOT NULL OR endpoint_policy_key IS NOT NULL OR disclosure_id IS NOT NULL OR entitlement_revision IS NOT NULL OR purpose IS NOT NULL OR monetary_applicable IS NOT NULL OR reserved_output_units IS NOT NULL OR usage_budget_id IS NOT NULL OR aggregate_budget_id IS NOT NULL OR owner_approval_id IS NOT NULL OR acknowledgement_id IS NOT NULL OR workspace_public_id IS NOT NULL OR legal_entity_public_id IS NOT NULL OR credential_version_number IS NOT NULL OR credential_created_at IS NOT NULL OR credential_wrap_revision IS NOT NULL OR expected_settings_revision IS NOT NULL OR expected_connection_revision IS NOT NULL OR prior_selected_version_id IS NOT NULL OR prior_active_generation IS NOT NULL OR proposed_activated_generation IS NOT NULL OR actor_membership_id IS NOT NULL OR profile_manifest_sha256 IS NOT NULL OR credential_family IS NOT NULL OR verifier_policy_key IS NOT NULL OR verifier_policy_sha256 IS NOT NULL OR account_mapping_sha256 IS NOT NULL OR authority_references IS NOT NULL OR acknowledged_at_snapshot IS NOT NULL OR evidence_realm IS NOT NULL OR send_authorized_at IS NOT NULL OR observed_at IS NOT NULL OR verification_outcome IS NOT NULL OR claim_strength IS NOT NULL OR observed_organization_id IS NOT NULL OR observed_workspace_id IS NOT NULL OR observed_model_id IS NOT NULL OR evidence_id IS NOT NULL OR receipt_key_id IS NOT NULL OR receipt_mac IS NOT NULL OR promotion_expires_at IS NOT NULL OR promotion_disposition IS NOT NULL OR committed_activated_generation IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.tenant_ai_connections WHERE active_generation IS DISTINCT FROM 0) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.tenant_ai_credentials WHERE activated_generation IS NOT NULL OR last_test_operation_id IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained data'; END IF;
  IF EXISTS(SELECT 1 FROM public.tenant_ai_credentials WHERE state NOT IN ('pending','revoked') OR verification_state<>'unverified' OR verification_operation_id IS NOT NULL OR verified_profile_id IS NOT NULL OR last_tested_at IS NOT NULL OR last_verified_at IS NOT NULL OR last_test_outcome IS NOT NULL OR last_used_at IS NOT NULL) OR EXISTS(SELECT 1 FROM public.tenant_ai_connections WHERE payer_budget_id IS NOT NULL) OR EXISTS(SELECT 1 FROM public.tenant_ai_settings WHERE credential_version_id IS NOT NULL) THEN RAISE EXCEPTION 'AI downgrade refused: retained authority'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts WHERE admitted_at IS DISTINCT FROM date_trunc('second',admitted_at)) THEN RAISE EXCEPTION 'AI downgrade refused: precision loss'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts WHERE finalized_at IS DISTINCT FROM date_trunc('second',finalized_at)) THEN RAISE EXCEPTION 'AI downgrade refused: precision loss'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_acknowledgements WHERE acknowledged_at IS DISTINCT FROM date_trunc('second',acknowledged_at)) THEN RAISE EXCEPTION 'AI downgrade refused: precision loss'; END IF;
  IF EXISTS(SELECT 1 FROM public.assistant_provider_acknowledgements WHERE revoked_at IS DISTINCT FROM date_trunc('second',revoked_at)) THEN RAISE EXCEPTION 'AI downgrade refused: precision loss'; END IF;
END $down$;
```

#### M2 down — restore the historical boundary

```sql
-- REVIEW LISTING ONLY. M2 down; run shared down preflight in this same transaction first.
CREATE OR REPLACE FUNCTION public.tai_197f2f5deff189f741f7()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$ BEGIN IF NEW.verification_state<>'unverified' OR NEW.verified_profile_id IS NOT NULL OR NEW.verification_operation_id IS NOT NULL OR NEW.last_used_at IS NOT NULL OR NEW.last_tested_at IS NOT NULL OR NEW.last_verified_at IS NOT NULL OR NEW.last_test_outcome IS NOT NULL THEN RAISE EXCEPTION 'Invalid tenant AI state'; END IF; RETURN NEW; END; $function$;
CREATE TRIGGER tai_197f2f5deff189f741f7 BEFORE INSERT OR UPDATE ON public.tenant_ai_credentials FOR EACH ROW EXECUTE FUNCTION tai_197f2f5deff189f741f7();
DROP TRIGGER ai3c_active_parent ON public.tenant_ai_connections;
DROP TRIGGER ai3c_verification_authority_v1 ON public.tenant_ai_credentials;
DROP TRIGGER ai3c_allocation_integrity ON public.assistant_provider_allocations;
DROP TRIGGER ai3c_attempt_integrity ON public.assistant_provider_attempts;
DROP TRIGGER ai3c_credential_guard ON public.tenant_ai_credentials;
DROP TRIGGER ai3c_connection_guard ON public.tenant_ai_connections;
DROP TRIGGER ai3c_allocation_immutable ON public.assistant_provider_allocations;
DROP TRIGGER ai3c_window_guard ON public.assistant_provider_windows;
DROP TRIGGER ai3c_attempt_initial ON public.assistant_provider_attempts;
DROP TRIGGER ai3c_ack_guard ON public.assistant_provider_acknowledgements;
DROP TRIGGER ai3c_approval_guard ON public.assistant_provider_tenants;
DROP TRIGGER ai3c_attempt_guard ON public.assistant_provider_attempts;
DROP TRIGGER ai3c_control_guard ON public.assistant_provider_controls;
ALTER TABLE public.tenant_ai_credentials DROP CONSTRAINT ai3c_uuid_19_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_18_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_17_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_16_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_15_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_14_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_13_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_12_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_11_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_10_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_uuid_9_ck;
ALTER TABLE public.assistant_provider_acknowledgements DROP CONSTRAINT ai3c_uuid_8_ck;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_uuid_7_ck;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_uuid_6_ck;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_uuid_5_ck;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_uuid_4_ck;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_uuid_3_ck;
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_uuid_2_ck;
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_uuid_1_ck;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT assistant_provider_attempts_values;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_approval_shape_ck;
ALTER TABLE public.assistant_provider_acknowledgements DROP CONSTRAINT ai3c_ack_shape_ck;
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_control_shape_ck;
DROP INDEX public.ai3c_approval_gateway_active_uq;
DROP INDEX public.assistant_provider_tenant_active;
DROP INDEX public.ai3c_ack_gateway_uq;
DROP INDEX public.ai3c_ack_legacy_uq;
DROP INDEX public.ai3c_control_customer_account_uq;
DROP INDEX public.ai3c_control_customer_aggregate_uq;
DROP INDEX public.ai3c_control_vap_account_uq;
DROP INDEX public.ai3c_control_vap_aggregate_uq;
DROP INDEX public.ai3c_control_usage_uq;
DROP FUNCTION public.ai3c_active_parent_constraint();
DROP FUNCTION public.ai3c_credential_constraint();
DROP FUNCTION public.ai3c_attempt_constraint();
DROP FUNCTION public.ai3c_check_attempt(uuid);
DROP FUNCTION public.ai3c_credential_guard();
DROP FUNCTION public.ai3c_connection_guard();
DROP FUNCTION public.ai3c_allocation_immutable();
DROP FUNCTION public.ai3c_window_guard();
DROP FUNCTION public.ai3c_attempt_initial_guard();
DROP FUNCTION public.ai3c_metadata_guard();
DROP FUNCTION public.ai3c_refs_shape(jsonb);
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT assistant_provider_attempts_values CHECK ((((environment)::text = 'production'::text) AND ((state)::text = ANY ((ARRAY['admitted'::character varying, 'received'::character varying, 'failed'::character varying, 'usage_unknown'::character varying])::text[])) AND (reserved_micro_usd = 552816) AND ((estimated_tokens >= 1024) AND (estimated_tokens <= 8192)) AND ((request_bytes >= 1) AND (request_bytes <= 7168)) AND ((input_tokens IS NULL) OR ((input_tokens >= 0) AND (input_tokens <= 1000000))) AND ((output_tokens IS NULL) OR ((output_tokens >= 0) AND (output_tokens <= 1024))) AND ((actual_micro_usd IS NULL) OR (actual_micro_usd >= 0))));
ALTER TABLE public.tenant_ai_connections ADD CONSTRAINT tenant_ai_connections_payer_budget_id_check CHECK ((payer_budget_id IS NULL));
ALTER TABLE public.assistant_provider_acknowledgements ADD CONSTRAINT assistant_provider_ack_identity UNIQUE (actor_attribution_id, workspace_id, policy);
CREATE UNIQUE INDEX assistant_provider_tenant_active ON public.assistant_provider_tenants USING btree (workspace_id, policy, profile) WHERE (revoked_at IS NULL);

-- M1 commits with new branches closed; M2 removes these only after successor guards exist.
ALTER TABLE public.assistant_provider_controls ADD CONSTRAINT ai3c_stage_closed_1 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_tenants ADD CONSTRAINT ai3c_stage_closed_2 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_acknowledgements ADD CONSTRAINT ai3c_stage_closed_3 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_stage_closed_4 CHECK(contract_version='legacy');
ALTER TABLE public.assistant_provider_attempts ADD CONSTRAINT ai3c_stage_estimate CHECK(estimated_tokens IS NOT NULL);
```

#### M1 down — remove unused additive structure

```sql
-- REVIEW LISTING ONLY. M1 down; M2 must already be reversed and shared down preflight passed.
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_stage_estimate;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_stage_closed_4;
ALTER TABLE public.assistant_provider_acknowledgements DROP CONSTRAINT ai3c_stage_closed_3;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_stage_closed_2;
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_stage_closed_1;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_approval_connection_fk;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_approval_entity_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_ref_8_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_ref_7_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_ref_6_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_ref_5_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_ref_4_fk;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_ref_3_fk;
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_ref_2_fk;
ALTER TABLE public.assistant_provider_controls DROP CONSTRAINT ai3c_ref_1_fk;
ALTER TABLE public.assistant_provider_windows DROP CONSTRAINT ai3c_window_units_ck;
ALTER TABLE public.tenant_ai_connections DROP CONSTRAINT ai3c_connection_generation_ck;
ALTER TABLE public.tenant_ai_credentials DROP CONSTRAINT ai3c_credential_generation_ck;
ALTER TABLE public.tenant_ai_credentials DROP CONSTRAINT ai3c_credential_test_attempt_fk;
ALTER TABLE public.tenant_ai_credentials DROP CONSTRAINT ai3c_credential_verified_attempt_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_attempt_prior_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_attempt_credential_fk;
ALTER TABLE public.assistant_provider_attempts DROP CONSTRAINT ai3c_attempt_credential_tuple_uq;
DROP INDEX public.ai3c_credential_test_idx;
DROP INDEX public.ai3c_attempt_prior_idx;
DROP INDEX public.ai3c_attempt_credential_idx;
DROP INDEX public.ai3c_approval_connection_idx;
DROP INDEX public.ai3c_approval_entity_idx;
DROP INDEX public.ai3c_ref_8_idx;
DROP INDEX public.ai3c_ref_7_idx;
DROP INDEX public.ai3c_ref_6_idx;
DROP INDEX public.ai3c_ref_5_idx;
DROP INDEX public.ai3c_ref_4_idx;
DROP INDEX public.ai3c_ref_3_idx;
DROP INDEX public.ai3c_ref_2_idx;
DROP INDEX public.ai3c_ref_1_idx;
DROP INDEX public.ai3c_attempt_workspace_idx;
DROP INDEX public.ai3c_probe_actor_idx;
DROP INDEX public.ai3c_probe_workspace_idx;
DROP INDEX public.ai3c_probe_connection_idx;
DROP INDEX public.ai3c_probe_inflight_uq;
DROP INDEX public.ai3c_evidence_id_uq;
DROP INDEX public.ai3c_credential_generation_uq;
DROP INDEX public.ai3c_alloc_window_idx;
DROP TABLE public.assistant_provider_allocations;
ALTER TABLE public.tenant_ai_credentials DROP COLUMN last_test_operation_id, DROP COLUMN activated_generation;
ALTER TABLE public.tenant_ai_connections DROP COLUMN active_generation;
ALTER TABLE public.assistant_provider_attempts DROP COLUMN committed_activated_generation, DROP COLUMN promotion_disposition, DROP COLUMN promotion_expires_at, DROP COLUMN receipt_mac, DROP COLUMN receipt_key_id, DROP COLUMN evidence_id, DROP COLUMN observed_model_id, DROP COLUMN observed_workspace_id, DROP COLUMN observed_organization_id, DROP COLUMN claim_strength, DROP COLUMN verification_outcome, DROP COLUMN observed_at, DROP COLUMN send_authorized_at, DROP COLUMN evidence_realm, DROP COLUMN acknowledged_at_snapshot, DROP COLUMN authority_references, DROP COLUMN account_mapping_sha256, DROP COLUMN verifier_policy_sha256, DROP COLUMN verifier_policy_key, DROP COLUMN credential_family, DROP COLUMN profile_manifest_sha256, DROP COLUMN actor_membership_id, DROP COLUMN proposed_activated_generation, DROP COLUMN prior_active_generation, DROP COLUMN prior_selected_version_id, DROP COLUMN expected_connection_revision, DROP COLUMN expected_settings_revision, DROP COLUMN credential_wrap_revision, DROP COLUMN credential_created_at, DROP COLUMN credential_version_number, DROP COLUMN legal_entity_public_id, DROP COLUMN workspace_public_id, DROP COLUMN acknowledgement_id, DROP COLUMN owner_approval_id, DROP COLUMN aggregate_budget_id, DROP COLUMN usage_budget_id, DROP COLUMN reserved_output_units, DROP COLUMN monetary_applicable, DROP COLUMN purpose, DROP COLUMN entitlement_revision, DROP COLUMN disclosure_id, DROP COLUMN endpoint_policy_key, DROP COLUMN vap_reference_id, DROP COLUMN credential_version_id, DROP COLUMN connection_id, DROP COLUMN selection_revision, DROP COLUMN profile_id, DROP COLUMN ownership_kind, DROP COLUMN provider_key, DROP COLUMN deployment_id, DROP COLUMN contract_version;
ALTER TABLE public.assistant_provider_acknowledgements DROP COLUMN disclosure_id, DROP COLUMN contract_version;
ALTER TABLE public.assistant_provider_tenants DROP COLUMN account_mapping_sha256, DROP COLUMN verifier_policy_sha256, DROP COLUMN purpose, DROP COLUMN entitlement_revision, DROP COLUMN disclosure_id, DROP COLUMN selection_revision, DROP COLUMN connection_id, DROP COLUMN account_budget_id, DROP COLUMN ownership_kind, DROP COLUMN profile_id, DROP COLUMN deployment_id, DROP COLUMN environment, DROP COLUMN legal_entity_id, DROP COLUMN contract_version;
ALTER TABLE public.assistant_provider_windows DROP COLUMN reserved_output_units, DROP COLUMN reserved_attempt_units;
ALTER TABLE public.assistant_provider_controls DROP COLUMN account_reference, DROP COLUMN provider_key, DROP COLUMN workspace_id, DROP COLUMN account_role, DROP COLUMN ownership_kind, DROP COLUMN deployment_id, DROP COLUMN revision, DROP COLUMN contract_version;
ALTER TABLE public.assistant_provider_attempts ALTER COLUMN estimated_tokens SET NOT NULL, ALTER COLUMN admitted_at TYPE timestamp(0) with time zone, ALTER COLUMN finalized_at TYPE timestamp(0) with time zone;
ALTER TABLE public.assistant_provider_acknowledgements ALTER COLUMN acknowledged_at TYPE timestamp(0) with time zone, ALTER COLUMN revoked_at TYPE timestamp(0) with time zone;
```

## 8. Later implementation-file map — no changes in this checkpoint

| Existing/new path                                                                                                                               | Exact later responsibility                                                                                                                                                                             | Explicit boundary                                                                                                                  |
| ----------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| Proposed M1–M3 paths in §2                                                                                                                      | Materialize reviewed PostgreSQL and fast SQLite-equivalent integrity; preserve accepted historical migration bytes                                                                                     | Only after independent DDL approval; no profile/grant seeds                                                                        |
| New `app/Fiscal/TenantAiVerificationSchema.php`                                                                                                 | Contain only new driver-specific additive/constraint implementation where sibling convention warrants it                                                                                               | Never change historical `TenantAiSchema` or add test guard exceptions                                                              |
| New `app/Fiscal/TenantAiVerificationContext.php`                                                                                                | Explicit owner/entity/production/deployment, original membership and fresh prerequisite snapshots                                                                                                      | Reuse unchanged `TenantAiContext`; no browser-state fallback/emergency verification                                                |
| New `app/Fiscal/TenantAiCredentialVerifier.php`                                                                                                 | Final request-local session, private registry/signing, full bounded acquisition and permit consumption                                                                                                 | No public evidence/permit factory or arbitrary caller transport                                                                    |
| New `app/Fiscal/TenantAiVerificationReceipt.php`                                                                                                | Closed ordered encoding, fixed typed parsing and MAC verification                                                                                                                                      | Signer remains private to verifier; no public `sign(array)` API                                                                    |
| New `app/Fiscal/TenantAiVerificationLedger.php`                                                                                                 | Root-fenced probe quotas/reservation, nine allocations, purpose-aware terminal metadata/recovery                                                                                                       | One existing ledger; no customer inference admission; no refund                                                                    |
| New `app/Fiscal/TenantAiActiveLifecycle.php`                                                                                                    | Current-authority candidate operations, active rotation/revoke/disable, generation/CAS and required audit                                                                                              | Keep accepted below-active `TenantAiLifecycle` byte-identical                                                                      |
| New `app/Fiscal/TenantAiVerificationTransport.php`                                                                                              | Reviewed Anthropic fixed metadata GET, bounded parser, final send prerequisite, one-use JIT envelope consumer                                                                                          | No SDK generic client, Messages request, fallback URL/model, raw response persistence or provider call during implementation tests |
| `app/Fiscal/TenantAiEnvelope.php`, `TenantAiKeyFile.php`, `SensitiveCredentialQuery.php`                                                        | Reuse immutable binding/parser/KEK/sensitive-query primitives through a narrow reviewed consumer                                                                                                       | No `inspectForStorageTest()` production call; no public decrypt-by-ID API or new secret format; frozen bytes preferred             |
| `app/Fiscal/AssistantProviderLedger.php`                                                                                                        | Root fencing; immutable reviewed legacy→shared mapping; continuous shared-unit reserve; sorted merged lock sets; revision increments; all legacy predicates; partial-index-aware acknowledgement write | Preserve old liability, privacy and legacy pricing/finalization; no active credential resolution or gateway consumption            |
| `app/Fiscal/AssistantProviderTransport.php`                                                                                                     | At most reviewed extraction of existing DNS/pinned-cURL primitives for verifier reuse if necessary                                                                                                     | Differential wire/security tests must preserve legacy behavior; no new customer inference path                                     |
| Existing `routes/console.php` recovery integration, if selected                                                                                 | Keep legacy recovery legacy-only; no operator probe/provisioning command                                                                                                                               | No decrypt/retry/promote; recovery receives attempt ID only                                                                        |
| `app/Fiscal/RequiredAudit.php` and existing AI audit caller                                                                                     | Use the established transactional facility with the fixed VC1 event/metadata set                                                                                                                       | No broad logging rewrite; do not persist full receipt/provider responses/secret fields                                             |
| New focused feature/unit PostgreSQL tests                                                                                                       | Implement named acceptance cases below after checkpoint approval                                                                                                                                       | Frozen 3a/3b assertions remain against their historical boundary; new successor tests use real guards                              |
| `VapAiGateway`, `GatewayAssistantPlanner`, `LegacyAssistantAiGateway`, `AnthropicIntentPlanner`, `AiInferenceRequest`, gateway/assistant config | **No tenant credential consumption changes**                                                                                                                                                           | No provider binding, model inference, fallback, approval shortcut or activation                                                    |

Names for new private classes may follow existing conventions during the later authorized implementation; their responsibility and authority boundary are fixed. This table is not permission to implement them now. No route, UI, provisioning command, account-approval creator, endpoint manager, rewrap tool or customer profile catalogue expansion is included.

## 9. Proposed tests and populated rehearsal — ALL NOT RUN

These tests are requirements for the later authorized implementation/rehearsal. No PHP test was created or modified here. A proposed test name is not evidence of a passing test.

| ID         | Proposed test / fixture                                                   | Required assertions                                                                                                                                                                                                                                      |
| ---------- | ------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| DDL-01     | `TenantAiVerificationMigrationTest::emptyAndPopulatedUp`                  | Empty install and populated historical boundary; exact defaults; all old row IDs, consent, money, ciphertext and A1 snapshots unchanged; no new verified/active rows or real customer profile/grant                                                      |
| DDL-02     | `PostgresTenantAiVerificationSchemaTest::catalogAndValidation`            | All expected types/defaults/FKs/index predicates/deferrability; every new CHECK/FK validated after M3; successor installed atomically; exact old guard restoration on safe down                                                                          |
| DDL-03     | `legacyBranchEquivalence`                                                 | Null estimate, 1023/1024/8192/8193 estimates; 0/1/7168/7169 request bytes; exact 552816 reserve; original token/money states; no gateway columns on legacy; no SQL UNKNOWN bypass                                                                        |
| DDL-04     | `exactAllocationSet`                                                      | All nine expected rows; remove each, add extra role/window, duplicate, wrong UTC identity, money/units, account/tenant/profile digest/reserve; foreign/substituted IDs; no partial commit; counter increment tested separately                           |
| DDL-05     | `oneActiveAndHistory`                                                     | Concurrent same-connection active attempts fail uniqueness; revoked/replaced rows retained; independent connections valid; disabled connection does not remove uniqueness; selected-version mismatch fails                                               |
| DDL-06     | `forgedEvidenceIsNotAuthority`                                            | Structurally malformed SQL denied; fully coherent forged DB record still fails private permit/MAC consumer tests; no test claims SQL verifies HMAC                                                                                                       |
| DDL-07     | `downRefusalAndRestore`                                                   | Safe empty/pre-authority down; refusal after each retained new datum, nonzero usage, receipt, verified tombstone and precision loss; no secret resurrection; interrupted M1/M2/M3 leaves admission closed                                                |
| DDL-08     | `reconcileTwiceAndMismatch`                                               | All legacy outcomes, two budgets/deployments, actor across workspaces, zero-use windows, day/month/year/leap UTC edges; exact old monetary inclusion; run twice no double count; missing history/ambiguous mapping/overflow abort; no consent conversion |
| DDL-09     | `legacyCompatibility`                                                     | Partial-index acknowledgement conflict target; legacy-only notice/withdraw/admit/assert/finalize/recover; no gateway acknowledgement accepted as legacy; control revision increments                                                                     |
| AUTH-01    | `verificationFreshContext`                                                | Strict owner/original membership/MFA/reauth/CSRF/entity/production/deployment; foreign/missing IDs indistinguishable; absent account/profile/egress/approval/disclosure/cap denies before sensitive read/DNS                                             |
| AUTH-02    | `receiptCanonicalizationAndTampering`                                     | Exact vectors, every field, null/decimal/time types, 16/17 refs, 8192/8193 bytes, MAC/key/deployment/realm, mutated binding/disposition/gen, copied request/PID/fiber, consumed-on-rollback                                                              |
| AUTH-03    | `currentFailureAndStaleSuccess`                                           | Genuine offline success promotes; current 401 may fail B; 403/transient/malformed do not infer invalid key; old active A remains; successful stale observation never marks credential verified                                                           |
| AUTH-04    | `destructionAndRollbackEveryWrite`                                        | Inject failures after receipt, A retirement, each destruction field, B activation, connection/settings update and audit; no partial authority; admitted liability remains; no second send/permit reissue                                                 |
| PG-01      | `PostgresTenantAiVerificationConcurrencyTest::candidateAndAdmissionRaces` | Independent PostgreSQL workers/barriers, actual lock waiting; one pending/inflight; race A→B/A→C proposals and stale B after C; do not disable indexes to create impossible two-current-candidate state                                                  |
| PG-02      | `promotionAgainstRevocationAndDisable`                                    | Both serializations of exact candidate/active revoke and workspace/connection disable; correct CAS errors; disable/re-enable revision ABA; no A revoke follows new B selection                                                                           |
| PG-03      | `recoveryAgainstDelayedWorker`                                            | Crash before/after admission/send/response/commit; 30s recovery; late worker loses to terminal unknown; one accounting finalization/audit, no refund/resend/decrypt/promotion                                                                            |
| PG-04      | `lastQuotaSlotAndSharedLegacyTraffic`                                     | Connection 1/60s, workspace 5/hour, actor A1 5/hour across workspaces; legacy and probe admissions contend on shared caps; no rotation/mode/new-connection bypass; all admitted outcomes consume quota                                                   |
| PG-05      | `lockOrderAndDeferredFks`                                                 | Root→sorted class order, credentials sorted not active-first; attempt FK check deferred; approvals/ack withdrawal; revoke/finalize/recovery/control updates; no mixed old unfenced writer; lock timeout fixed failure                                    |
| PG-06      | `quotaPlans`                                                              | EXPLAIN with BUFFERS for empty/sparse/dense bounded history queries using LIMIT 1/5 and indexed timestamp predicates; fixed 1s runtime statement/250ms lock limits; no unbounded COUNT/history materialization                                           |
| WIRE-01    | `boundedMetadataVerification`                                             | Exact fixed GET/bodyless/header/model/account policy; 16-address/4096-byte DNS, public IP/pin/TLS/proxy/redirect attacks, header/body/JSON/deadline limits; one request; no real provider calls                                                          |
| CUSTODY-01 | `secretRedactionAndPurposeIsolation`                                      | Secret sentinels absent from query listeners, exceptions, models/resources/audit/logs/session/cache/jobs/SDK/config; only designated transport header; no legacy storage-test decrypt; no inference consumption/last_used_at                             |
| REG-01     | Frozen phase suites plus successor suites                                 | Historical 3a/3b assertions untouched and run at their migration boundary; postmigration successors exercise real guards; Phase 6–7/gateway/quarantine/audit/CLI/FPM remain green                                                                        |

Populated rehearsal must run only **after independent DDL approval**, against a restorable disposable UTF8 PostgreSQL database. Capture pre-schema definitions and restricted in-test row hashes (do not export secrets); seed only synthetic historical fixtures; check closed admission and drained workers; apply M1/M2/M3 in order; prove old/new invariants; execute the reviewed synthetic mapping twice; simulate timeout/interrupt after each stage; prove recovery and down refusal; restore the disposable backup; compare unchanged old money/consent/IDs. Then run independent-process races, quota plans and mixed legacy traffic. A migration timeout or mismatch is a failed rehearsal, not permission to skip validation.

The later implementation must run every §13.13/§10/governing §17 gate: full SQLite; UTF8 PostgreSQL integration; full `FISCAL_PG_GATE=1` concurrency; focused custody/lifecycle/gateway/quarantine/Phase 6–7 audit/wire/CLI/FPM; assistant UI/types; changed PHP syntax/Pint and changed-file lint/format; exact-object PHPStan comparison. Baseline commands must be taken from the existing reports/harness and recorded with actual exit statuses/counts. Do not present a planned command as an executed gate.

## 10. This checkpoint's validation and preservation

Executed validation is limited to repository/catalog inspection, serialization vectors and document/evidence checks. SQL delimiter/object-list checks are useful static review checks, **not PostgreSQL parsing, migration execution or concurrency proof**. Full runtime suites, migration rehearsal, provider transport tests and PostgreSQL race tests are **NOT RUN**, intentionally outside this checkpoint.

The evidence artifact records exact durable read-only SQL commands, statuses, inspected catalog, preservation hashes, canonical vectors, proposed object inventory and the final document hash. A first sandboxed local PostgreSQL connection was denied by the sandbox; the authorized read-only retry succeeded. That was an environment access failure, not a rejected DDL test. Two incorrect exploratory source paths were corrected to `app/Fiscal`; an exploratory catalog script used the wrong JSON key and was corrected. Static drafting found and corrected a missing CHECK parenthesis and incomplete inverse-DDL inventory extraction. No failed implementation or migration occurred; no runtime implementation was attempted.

All 56 accepted frozen-file hashes match at entry and completion. The task also compared the 2,600 pre-existing workspace files inventoried at entry (excluding Git/vendor/node_modules/storage/build/environment files); none changed. This is stronger than relying on the already-dirty Git worktree, which contains prior authorized phases. Only the two requested documents are added. Hashes for the frozen source, config, migrations, tests and governing documents are included in the JSON artifact.

The inherited 21 PHPStan diagnostics, 9,719 ESLint errors, four resource-formatting failures, SQLite/PostgreSQL warnings and the previously noted PDF anomaly were **not rerun, fixed or reclassified** by this documentation-only checkpoint. No new runtime or frontend diagnostic delta is asserted. They remain separately recorded inherited debt in the governing reports, not evidence that proposed DDL or concurrency has passed.

Production inference remains disabled in the inspected source (`config/assistant.php` provider `enabled=false`, `egress_enabled=false`); no activation configuration was changed. External integration defaults remain off and unchanged. This is repository evidence, not a claim about an uninspected remote process. Existing gateway code is preserved and still has no tenant credential consumption.

## 11. Required verdicts

These verdicts assess the **proposed contract and review package**, together with explicitly identified future transaction/application obligations. They do not claim the unexecuted schema or verifier already exists. In particular, “database-enforced” means the at-most-one active uniqueness invariant, not a permanent requirement to always have an active credential.

| Required verdict                            | Result                         |
| ------------------------------------------- | ------------------------------ |
| CONCRETE 3c-1 DDL                           | REVIEWABLE                     |
| EXACTLY ONE ACTIVE CREDENTIAL               | DATABASE-ENFORCED              |
| DATABASE SELF-AUTHORIZED VERIFICATION       | ABSENT                         |
| VERIFICATION EVIDENCE REPLAY PROTECTION     | SUFFICIENT                     |
| TENANT/CREDENTIAL/PROVIDER EVIDENCE BINDING | SUFFICIENT                     |
| STALE EVIDENCE                              | FAIL CLOSED                    |
| ACTIVE ROTATION SCHEMA SUPPORT              | SUFFICIENT                     |
| CANONICAL LOCK ORDER                        | COMPATIBLE                     |
| 7B.3a CUSTODY                               | PRESERVED                      |
| 7B.3b LIFECYCLE                             | PRESERVED                      |
| EXISTING DATA MIGRATION                     | FAIL CLOSED                    |
| ROLLBACK SEMANTICS                          | REQUIRES FORWARD-ONLY BOUNDARY |
| GATEWAY CREDENTIAL CONSUMPTION              | ABSENT                         |
| PRODUCTION INFERENCE                        | DISABLED                       |

No new architectural contradiction requiring amendment is asserted. Independent review must challenge the proposed concrete SQL, reciprocal/deferred bindings, NULL semantics, allocation completeness, generation rules, legacy coexistence, reconciliation and inverse order before any implementation/execution approval. Actual SQL compilation, populated performance, lock waiting, independent-process races, applied-schema inspection and full regressions remain unperformed implementation gates. Real customer profile/account/disclosure/verification-egress approval, signer/key custody and production restore/deployment evidence remain separate operational gates. Provider success/account-header behavior has not been established through a live call here.

### R1 remediation verdicts — design only, pending independent re-review

- ORIGINAL MEDIUM — ORPHAN LEGACY LIABILITY: **REMEDIATED**
- ATTEMPT-DERIVED COVERAGE: **COMPLETE**
- RETAINED-LIABILITY COVERAGE: **COMPLETE**
- MAPPING TABLE DEFINES SOURCE UNIVERSE: **NO**
- UNMAPPED NONZERO LIABILITY: **REJECTED**
- MISSING ATTEMPTS REDUCE LIABILITY: **NO**
- ORPHAN-LIABILITY REFUSAL: **FAIL CLOSED**
- REFUSAL PRESERVES LEGACY COUNTERS: **YES**
- FABRICATED USAGE DURING RECONCILIATION: **ABSENT**
- CANONICAL RECONCILIATION LOCK ORDER: **PRESERVED**
- FORWARD-ONLY BOUNDARY: **PRESERVED**
- GATEWAY CREDENTIAL CONSUMPTION: **ABSENT**
- PRODUCTION INFERENCE: **DISABLED**

EXISTING-DATA RECONCILIATION: **FAIL CLOSED** under the corrected proposal and the unchanged closed-writer/trusted-manifest prerequisites. No execution proof or independent acceptance is claimed.

## 12. Exact narrow Astra independent DDL-review handoff

> Perform the independent **Phase 7B.3c-1 reconciliation-coverage remediation re-review**. Preserve the earlier failed review. Inspect R1 against its original MEDIUM finding: independently sourced A UNION B, all retained counters, missing/partial/deleted attempts, omitted and conflicting mapping, exact source-window comparisons, complete pre-write checks and transactional refusal. Do not infer an unstored deployment binding from SQL; require the already-approved manifest provenance. Confirm R1-01 through R1-10 are explicit proposed tests, not executed evidence. Confirm all other SQL listings and receipt vectors are unchanged. Do not broaden remediation or implement anything. Continue the original concrete-DDL checkpoint obligations: Read the entire approved `docs/phase-7b-3-tenant-ai-control-plane-supplement.md`, particularly §§6–7 and VC1 §§13.5–13.9/13.13/13.16; the accepted 3a/3b reports/evidence; `docs/phase-7b-3c-1-migration-review-package.md` and `docs/phase-7b-3c-1-migration-review-evidence.json`; and the actual unchanged baseline schema/runtime/tests. Treat this package as a proposal, not proof or an amendment.
>
> Review every SQL listing and object independently. Attempt to falsify exact one-active scope, NULL/partial uniqueness, immutable tenant/provider/credential binding, deferred reciprocal attempt/credential integrity, generation transitions, terminal retention/destruction, receipt provenance versus authority, closed success/failure states, profile/reservation/allocation completeness, PostgreSQL locking/FK order, versioned legacy equivalence and legacy partial-index upsert compatibility. Verify that proposed mapping/reconciliation and ongoing root-fenced shared-unit accounting preserve old liabilities/consent/IDs and prevent old/new quota bypass. Inspect exact pre-authority down order and the forward-only refusal after retained evidence. Compare all 56 frozen hashes and the actual PostgreSQL catalog; do not treat SQLite or delimiter checks as PostgreSQL proof.
>
> This is still **review only**. Do not execute migrations/reconciliation, create runnable migration/runtime/test files, materialize real or synthetic promotion, create customer grants/profiles/credentials, send provider requests, enable inference or external access, wire the Gateway, or begin 3c-2. Authorize a later disposable populated rehearsal only as an explicit review outcome; do not perform it in this review. Do not redesign VC1 to make a draft fit. If a genuine architectural conflict remains, identify the narrow Astra decision and withhold approval. Classify concrete SQL/correctness findings with exact listing/location, impact and smallest required correction. Explicitly conclude **PHASE 7B.3c-1 CONCRETE DDL — APPROVED FOR BOUNDED IMPLEMENTATION AND DISPOSABLE REHEARSAL** or **PHASE 7B.3c-1 CONCRETE DDL — CHANGES REQUIRED**. Approval does not authorize live provider egress or production activation. Supply the next corrected bounded implementation instruction only if ready, then stop.

PHASE 7B.3c-1 MIGRATION DESIGN REMEDIATION COMPLETE — READY FOR INDEPENDENT DDL RE-REVIEW

## 13. R2 — approved mechanical identifier correction

Date: 2026-10-09. **FROZEN SQL IDENTIFIER CORRECTION: APPROVED.** This narrow amendment supersedes historical pending-review wording above only for the accepted package and this identifier correction. It authorizes resuming the bounded migration implementation/rehearsal, not provider verification, promotion, gateway consumption, or production inference.

### History and independently checked execution evidence

The original package failed on R1 orphan-liability coverage. R1 was corrected and independently accepted in the conversation, with one non-blocking requirement to make unmapped surviving-attempt coverage explicit. Subsequent execution of the accepted SQL exposed R2, and implementation correctly stopped. The unchanged implementation report/evidence preserve that failure. This amendment does not erase it or claim successful corrected execution.

All eight SQL strings and hashes in the execution evidence matched the pre-amendment frozen package. Logs and recorded exits support separate M1, M2 and M3 installation (exit 0), then safe-preflight M2/M1 unused-schema downgrade (exit 0). A second combined transaction reinstalled the same SQL. The reconciliation command used `ON_ERROR_STOP=1 --single-transaction` and exited 3 at the final DO block's first destination query. The subsequent read-only census showed zero roots, controls, windows, attempts, allocations, credentials and audit rows: all three fixture inserts rolled back. This proves that particular pre-write failure rolled back, not the unexecuted post-write/audit failure cases or successful reconciliation.

[PostgreSQL 18 variable substitution and plan caching](https://www.postgresql.org/docs/18/plpgsql-implementation.html) explain both observations: ambiguous variable/column references fail by default, and individual embedded SQL expressions are analyzed when reached. The declaration `w ...%ROWTYPE` and relation alias `w` coexist in the final DO scope. Its `w.budget_id` has two candidate bindings. This is identifier resolution, not missing schema or mismatched fixture data.

### Exact approved correction and binding audit

Rename only the procedural destination-window variable to `shared_window_row`. It is a shared destination row, not a legacy source row. Keep all SQL aliases, loop variable `r`, columns, types, predicates, comparisons, sums, statements, ordering, errors and transaction boundaries byte-identical. Do not change `plpgsql.variable_conflict`.

There are ten changed identifier occurrences across five lines:

| Existing package line | Classification                         | Before → after                                                                                                                        | Same logical binding                             |
| --------------------- | -------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------ |
| 1126                  | declaration (one occurrence)           | `w` → `shared_window_row`                                                                                                             | Destination window row, same `%ROWTYPE`          |
| 1151                  | procedural-variable reference (one)    | `INTO w` → `INTO shared_window_row`                                                                                                   | Target receiving the same locked destination row |
| 1153                  | procedural-variable references (three) | `w.window_end`, `w.reserved_attempt_units`, `w.reserved_output_units` → corresponding `shared_window_row.*`                           | Same destination end and unit counters           |
| 1154                  | procedural-variable references (four)  | `w.reserved_micro_usd`, `w.actual_micro_usd`, `w.actual_input_tokens`, `w.actual_output_tokens` → corresponding `shared_window_row.*` | Same destination money/token counters            |
| 1156                  | procedural-variable reference (one)    | `w.id` → `shared_window_row.id`                                                                                                       | Same locked destination row identifier           |

No SQL table alias or SQL alias reference changes. All 96 original `w.<column>` occurrences are individually classified by line/column in the evidence: eight procedural field references above are renamed; the other 88 remain relation aliases. Source DO blocks have no procedural `w`; destination preflight now has no procedural `w` either. Thus every remaining `w.*` resolves to its existing SQL relation alias. Bare column references in SELECT/ORDER BY/ON CONFLICT/UPDATE do not collide with either declared variable.

The complete reconciliation scope audit found no additional collisions. The first DO declares only loop record `r`, with no SQL relation/column named `r`; the second declares no variables. The final DO declares `r` and `shared_window_row`; `r.*` addresses the shared-target iteration, and the renamed row addresses the destination selected FOR UPDATE. Neither name is used as a relation alias or column. Prepared JSON input `$1` and aliases outside DO blocks have no procedural scope; there are no CTEs or PL/pgSQL function parameters in this listing. This is a static binding audit, not a claim that every runtime path has executed.

Exact diff:

```diff
--- R1-reconciliation.sql
+++ R2-reconciliation.sql
@@ -121,7 +121,7 @@
     sum(attempts::numeric)::bigint AS attempts,sum(outputs::numeric)::bigint AS outputs
   FROM ai3c_history GROUP BY usage_budget_id,scope,scope_key,starts,ends;
 DO $reconcile$
-DECLARE r record; w public.assistant_provider_windows%ROWTYPE;
+DECLARE r record; shared_window_row public.assistant_provider_windows%ROWTYPE;
 BEGIN
   -- Full destination preflight also precedes all persistent accounting writes.
   IF EXISTS(SELECT 1 FROM public.assistant_provider_windows w JOIN ai3c_shared_target t
@@ -146,12 +146,12 @@
     INSERT INTO public.assistant_provider_windows(budget_id,scope,scope_key,window_start,window_end)
       VALUES(r.budget_id,r.scope,r.scope_key,r.starts,r.ends)
       ON CONFLICT(budget_id,scope,scope_key,window_start) DO NOTHING;
-    SELECT * INTO w FROM public.assistant_provider_windows
+    SELECT * INTO shared_window_row FROM public.assistant_provider_windows
       WHERE ROW(budget_id,scope,scope_key,window_start)=ROW(r.budget_id,r.scope,r.scope_key,r.starts) FOR UPDATE;
-    IF w.window_end<>r.ends OR w.reserved_attempt_units>r.attempts OR w.reserved_output_units>r.outputs
-      OR w.reserved_micro_usd<>0 OR w.actual_micro_usd<>0 OR w.actual_input_tokens<>0 OR w.actual_output_tokens<>0
+    IF shared_window_row.window_end<>r.ends OR shared_window_row.reserved_attempt_units>r.attempts OR shared_window_row.reserved_output_units>r.outputs
+      OR shared_window_row.reserved_micro_usd<>0 OR shared_window_row.actual_micro_usd<>0 OR shared_window_row.actual_input_tokens<>0 OR shared_window_row.actual_output_tokens<>0
       THEN RAISE EXCEPTION 'AI shared usage requires reconciliation'; END IF;
-    UPDATE public.assistant_provider_windows SET reserved_attempt_units=r.attempts,reserved_output_units=r.outputs WHERE id=w.id;
+    UPDATE public.assistant_provider_windows SET reserved_attempt_units=r.attempts,reserved_output_units=r.outputs WHERE id=shared_window_row.id;
   END LOOP;

 END $reconcile$;
```

Replacing `shared_window_row` back with `w` in the corrected SQL reproduces the entire previous listing byte-for-byte. No query structure, source union, join, predicate, counter calculation, checked bigint cast, write, lock, audit obligation, rollback rule or forward-only boundary changed. R1 remains preserved, including independent discovery from attempts and retained windows, missing-history refusal and pre-write coverage.

Old reconciliation SHA-256: `4ad379eec69b0a4b62caecd3e377af175917bfd773854b11b30cfb72efa7400f`.

New reconciliation SHA-256: `19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc`.

The seven other SQL listings, receipt vectors and all 47 signed input positions remain unchanged. The 56 frozen source files, 2,600 pre-existing inventoried files, supplement and both execution-blocker artifacts remain unchanged. New evidence is limited to the rename/diff/binding audit, dependent hashes, preservation results and this decision. Historical execution/validation remains historical; it has not been regenerated as purported corrected execution.

### Decision and exact resume instruction

**MECHANICAL IDENTIFIER CORRECTION — NO DDL/RECONCILIATION SEMANTIC CHANGE.** No full DDL architecture re-review is required for this lexical change. Corrected SQL has not been executed in this review. Existing installation and unused-schema downgrade results remain valid; post-evidence downgrade remains unproven.

> Resume the previously bounded Phase 7B.3c-1 migration/schema/reconciliation implementation using the frozen package with R2 and its refreshed evidence. Start from a clean disposable PostgreSQL state; do not continue from the failed rehearsal database. Preserve exact M1–M3 chronology and all accepted SQL semantics. Exercise the corrected destination-preflight block with a valid zero-use mapping and complete positive historical data, including the per-row path. Implement every previous mandatory fixture, especially independent unmapped surviving-attempt coverage and orphan retained-liability refusal. Run all originally required PostgreSQL, populated-upgrade, concurrency, rollback, custody/lifecycle and quality gates. Preserve historical failure evidence. Stop on any further design incompatibility. Do not implement provider verification, runtime receipt authority, promotion/rotation services, gateway consumption, 3c-2, provider calls or production activation.

**PHASE 7B.3c-1 SQL CORRECTION APPROVED — MIGRATION IMPLEMENTATION MAY RESUME**

## 14. R3 — approved public-ID shape and exact snapshot binding

Date: 2026-10-09. **FROZEN PUBLIC-ID CHECK CORRECTION: APPROVED.** This is a narrow representation correction, not authorization redesign. It supersedes the two uppercase snapshot predicates and the two inconsistent synthetic receipt vectors. Historical R1/R2 statements and execution artifacts remain historical, unchanged evidence.

### Independently established identity contract

`Workspace` and `LegalEntity` use installed Laravel 13.24.0 `HasUlids`. Its `newUniqueId()` returns `strtolower((string) Str::ulid())`. Both models select `public_id` as their unique identifier and route key. Their factories leave this field to the real generator. No model cast, mutator or observer overrides its case. The source migrations `2026_08_02_181024_create_workspaces_table.php` and `2026_08_02_181026_create_legal_entities_table.php` declare unique ULID columns.

The canonical language is exactly 26 lowercase ASCII Crockford base32 characters, first character `0`–`7`, remaining characters `0123456789abcdefghjkmnpqrstvwxyz`, no prefix or separator. Symfony's ULID validator enforces the first-character limit required by the 128-bit encoding. `AssistantJson::publicId` independently enforces that limit and accepts case variants at the input boundary before returning lowercase. The format is `^[0-7][0-9a-hjkmnp-tv-z]{25}$`. This validates representation, not provenance: a well-formed identifier is insufficient to authorize anything.

| Snapshot field                                       | Authoritative source/model                | Generator and actual shape                         | Source DB constraint                           | Snapshot DB constraint                                                     | Comparison semantics                                                                     |
| ---------------------------------------------------- | ----------------------------------------- | -------------------------------------------------- | ---------------------------------------------- | -------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `assistant_provider_attempts.workspace_public_id`    | `workspaces.public_id`, `Workspace`       | `HasUlids::newUniqueId()`, lowercase ULID as above | `character(26)`, NOT NULL, UNIQUE within table | Nullable for legacy rows; gateway branch requires non-null canonical shape | Exact stored value; deferred guard resolves source by `workspace_id`                     |
| `assistant_provider_attempts.legal_entity_public_id` | `legal_entities.public_id`, `LegalEntity` | Same generator, alphabet, length and case          | `character(26)`, NOT NULL, UNIQUE within table | Nullable for legacy rows; gateway branch requires non-null canonical shape | Exact stored value; deferred guard resolves by both `legal_entity_id` and `workspace_id` |

These are the only two public IDs covered by the affected uppercase predicates. Connection/credential identifiers and all other shapes remain unchanged.

`TenantAiContext` and `IntegrationReadContext` resolve persisted identifiers and copy their values. Document request validation uses ULID syntax plus exact lookup; customer/catalogue request parsing and assistant input parsing accept case variants and normalize input. The existing assistant context also lowercases its public context strings. This is not a global model storage rule or permission to transform a future signed snapshot. Resource serialization does not supply a global case-normalizing cast. No higher governing contract requires uppercase identity storage.

Read-only inspection of disposable `facturac_test_phase7b3a_storage` found `character(26)` source columns, deterministic default collation, unique constraints and no source alphabet CHECK. Its one workspace has canonical lowercase ID `01m4fv558rhd46wphn69wd5eqa`; it has no legal entities. The earlier failed real-model fixture supplies additional evidence for entity generation. Independently invoking each actual model generator 1,000 times without persistence produced zero noncanonical IDs. This is representative local evidence, not a production census.

PostgreSQL returned false for lowercase-versus-uppercase equality under the inspected column comparison. [Deterministic collations](https://www.postgresql.org/docs/18/sql-createcollation.html) preserve case distinctions here; neither column is `citext`. `character(n)` ignores trailing padding in comparisons, so general arbitrary-string byte equality is not claimed. For the required 26-character ASCII, space-free canonical language, these comparisons preserve exact representation. Source uniqueness does not prohibit manually inserted case variants: uppercase and lowercase could be different stored identities, despite some request parsers treating input as equivalent. Therefore normalization is unsafe as an authorization binding strategy.

No production database was accessed or changed. Before any future production use, inspect source IDs for noncanonical values under the deployment's real catalog/collation. If present, fail closed and return them for identity review; do not rewrite IDs, normalize snapshots or weaken a guard during this phase.

### Exact correction and invariant

Approve: **snapshot ID = exact authoritative stored public ID when captured**. The receipt signs those same bytes. The deferred guard continues comparing them with the current authoritative row; changes invalidate the binding rather than rewriting history.

```diff
-  AND workspace_public_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
-  AND legal_entity_public_id ~ '^[0-9A-HJKMNP-TV-Z]{26}$'
+  AND workspace_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
+  AND legal_entity_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
```

The former language required uppercase and incorrectly allowed a first character above `7`. The corrected language matches the application's canonical ULID representation, including its existing 128-bit first-character restriction. This additionally rejects overflow encodings previously admitted by the defective expression; it does not exclude any valid canonical application ULID or introduce a new identity policy. No time plausibility, issuance provenance or new prefix requirement is inferred from this shape check. Nil/maximal syntactically valid ULIDs remain accepted by shape alone, subject to exact binding and all other authority checks.

The deferred expressions remain byte-identical:

```sql
OR a.workspace_public_id IS DISTINCT FROM (SELECT public_id FROM public.workspaces WHERE id=a.workspace_id)
OR a.legal_entity_public_id IS DISTINCT FROM (SELECT public_id FROM public.legal_entities WHERE id=a.legal_entity_id AND workspace_id=a.workspace_id)
```

Do not add `UPPER`, `LOWER`, case-insensitive comparison or transformed snapshot fallback. Transforming only the snapshot breaks exact binding; transforming both sides could collapse distinct persisted identities. It also changes MAC input, creates incompatible replay verification across versions, risks identity substitution, and encourages future verifier/provider-binding implementations to accept two representations. Receipt encoding must validate canonical source bytes and serialize them unchanged, not repair them.

### Directly dependent receipt correction

Both previous synthetic vectors used uppercase workspace/entity IDs, contradicting the real generator. Their original SHA/HMAC calculations were independently reproduced before amendment: the cryptography was consistent with the wrong fixture representation. Positions 6 and 7 (zero-based) now contain `01arz3ndektsv4rrffq69g5fav` and `01arz3ndektsv4rrffq69g5faw`. All other 45 positions, the 47-field order, domain prefix, public 32-zero-byte test key, schema version and receipt authority semantics remain unchanged. This is correction of fixture literals, not a normalization algorithm. These deliberately incomplete, non-operational vectors still cannot authorize promotion.

| Vector                          | Length, including prefix | Corrected SHA-256                                                  | Corrected HMAC-SHA256 with public test key                         |
| ------------------------------- | ------------------------ | ------------------------------------------------------------------ | ------------------------------------------------------------------ |
| `rotation_observation_rejected` | 1364                     | `efc83374be6ae120b9a2411253922292d44b1bfc77ca672e560bda1d5918cbce` | `9861c86b2148bedac56a2c09924959a379aebd59cb1895551c648a931dabb146` |
| `null_prior_and_large_decimal`  | 1345                     | `b419a05dd2a84985f1ae2b8b2030b8f589a12479050b27be5e9ed16efb66c0f9` | `7875f05385aad227fa867a5ae7a6aa22f5bb1253203fa96677547358738df278` |

The evidence retains the complete previous vectors under `public_identity_amendment_R3.historical_canonicalization_before_R3`. Do not accept historical uppercase vectors through a compatibility normalization path or reuse their old MAC with corrected values. No real issued receipt or historical database row is changed.

### Preserved execution evidence and bounded verification

The unchanged implementation artifacts record fresh installation success, corrected R2 reconciliation, zero-use and positive-history success, eight independent orphan-counter refusals and independent unmapped surviving-attempt refusal. The focused run remains **12 passed, 1 error, 109 assertions**, failing on R3's uppercase shape predicate. Its transaction rolled back and subsequent census recorded zero fixture roots, controls, windows, attempts, allocations, credentials and audit rows. That confirms the recorded failure's rollback, not successful corrected CHECK execution or all remaining failure paths.

This review executed no DDL or migrations. Read-only PostgreSQL regex evaluation accepted canonical lowercase, nil and maximum valid format; rejected uppercase, overflow prefix, 25/27-character values, invalid `i`, separator, empty and space-containing input. In-memory real-model generation passed 2,000 samples. Independent PHP serialization/hash checks and static preservation checks are recorded in the evidence. These are design verification; they do not replace actual constraints, deferred guards, migration, reconciliation, concurrency or rollback tests.

Only the M2 constraints listing changes. The other seven SQL listings, including R2 reconciliation SHA-256 `19aaabdbf829e0f03f0c0e92c4f8573723909caba63f92c77868158f29726abc`, remain identical. All 56 frozen source files and all 2,600 original inventoried files match their baseline hashes. The implementation report/evidence and existing test are unchanged. No table structure, FK, unique index, accounting, lock order, transaction boundary, receipt authority or promotion rule changes. No provider/settings/runtime changes or calls occur. No production catalog change was made; independent production catalog equivalence was not measured.

### Mandatory resumed implementation acceptance

1. Start a **NEW clean disposable PostgreSQL database**. Repeat the complete approved M1–M3 migration/reconciliation gate, preserving every prior R1/R2 case and chronology.
2. Persist real `Workspace` and `LegalEntity` factory/model records using the production generator; capture stored IDs, copy them unchanged into the gateway snapshot, satisfy other constraints and force deferred checks. Prove accepted identity binding and strict snapshot/source equality. Do not uppercase fixtures to satisfy DDL.
3. Reject uppercase equivalents, overflow first characters, wrong length, excluded alphabet characters, separator, empty and null gateway snapshots. Respect legacy-null compatibility. A too-long value may fail column assignment before the CHECK; record the actual boundary exercised.
4. Prove exact canonical source matching passes the deferred identity primitive and a different valid lowercase source ID fails it; uppercase transformations must fail canonical shape. Exercise both workspace and entity identity and a correctly shaped entity from another workspace. Force deferred constraints before claiming success. Do not implement provider authority to test this primitive.
5. Verify the corrected two receipt vectors independently, mutate all 47 signed positions and prove MAC mismatch, including workspace/entity case substitutions. Reject noncanonical input rather than normalize it before validation/MAC verification.
6. Repeat all originally mandatory populated-upgrade, numeric-boundary, concurrency, audit/rollback, custody/lifecycle and quality gates. Preserve failed-attempt history; stop on any further contract incompatibility.

### Required verdicts and exact bounded handoff

| Verdict                                | Decision                                                                                                     |
| -------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| R3 PUBLIC-ID SHAPE MISMATCH            | CONFIRMED                                                                                                    |
| AUTHORITATIVE PUBLIC-ID REPRESENTATION | Lowercase 26-character ULID: `^[0-7][0-9a-hjkmnp-tv-z]{25}$`                                                 |
| MODEL GENERATION AND FROZEN CHECK      | INCONSISTENT before R3; CONSISTENT after this amendment                                                      |
| SNAPSHOT SEMANTICS                     | EXACT STORED BYTES                                                                                           |
| DEFERRED GUARD COMPARISON              | EXACT                                                                                                        |
| CASE NORMALIZATION DURING SNAPSHOT     | PROHIBITED                                                                                                   |
| CORRECTION TYPE                        | CHECK-CONSTRAINT REPRESENTATION                                                                              |
| DATABASE FORMAT VALIDATION             | PRESERVED                                                                                                    |
| TENANT/CONNECTION/CREDENTIAL BINDING   | PRESERVED                                                                                                    |
| RECEIPT SIGNED INPUTS                  | REQUIRE AMENDMENT only to the two synthetic public-ID values per vector; field order and semantics unchanged |
| RECEIPT VECTORS                        | REQUIRE REGENERATION; completed for the two inconsistent vectors only                                        |
| RECONCILIATION SQL                     | UNCHANGED                                                                                                    |
| R2 REMEDIATION                         | PRESERVED                                                                                                    |
| CANONICAL LOCK ORDER                   | UNCHANGED                                                                                                    |
| GATEWAY CREDENTIAL CONSUMPTION         | ABSENT                                                                                                       |
| PROVIDER VERIFICATION                  | ABSENT                                                                                                       |
| PRODUCTION INFERENCE                   | DISABLED                                                                                                     |

> Resume only the previously bounded Phase 7B.3c-1 migration/schema/reconciliation implementation using this frozen package with R1, R2 and R3 and its refreshed evidence. Start from a NEW clean disposable PostgreSQL database, not the failed R2/R3 rehearsal database. Apply only the approved two public-ID CHECK corrections; snapshot exact stored canonical IDs with no case transformation. Preserve the unchanged exact deferred guards, all receipt signed-field semantics and all other SQL. Use the two corrected synthetic receipt vectors; retain their previous versions as historical evidence. Implement the six acceptance groups above and every original migration/reconciliation, concurrency, rollback, custody/lifecycle and quality gate. Preserve historical failures and stop on any further design incompatibility. Do not implement provider verification, runtime receipt authority, promotion/rotation services, gateway credential consumption, 7B.3c-2, provider calls or production inference/activation.

**PHASE 7B.3c-1 PUBLIC-ID CORRECTION APPROVED — MIGRATION IMPLEMENTATION MAY RESUME**
