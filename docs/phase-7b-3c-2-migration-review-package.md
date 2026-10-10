# Phase 7B.3c-2 — migration review package (pre-execution)

Date: 2026-10-10. **Revision 4.** Revision 2 applied the changes required by [the first, same-author review](phase-7b-3c-2-migration-ddl-review.md). Revisions 3 and 4 apply text-only changes required by [the independent review](phase-7b-3c-2-migration-ddl-review-r2.md) and its follow-up. **No listing has changed since revision 2: the three listing hashes are identical** (§14). **Review listings only.** No migration was applied to any project, development or shared database, no migration file was created, no runtime code or test was written, and no provider request was made. Provider verification and inference remain hard-disabled in checked-in configuration.

This is the first bounded step of the handoff in §17 of the [3c-2 design](phase-7b-3c-2-gateway-consumption-design.md). It supplies the concrete DDL for the additive `invocation_authority_v1` successor so that it can be reviewed independently **before** anything is run, as supplement §6 requires. Machine-readable evidence is in [the JSON companion](phase-7b-3c-2-migration-review-evidence.json).

## 1. Decision, scope and evidence limits

**Baseline verified for this package (2026-10-09):** all 802 checkpoint inventory files present with 0 drift; 14/14 frozen hashes exact; 7/7 dependency and configuration hashes exact. The frozen schema listing this package derives from is `app/Fiscal/TenantAiVerificationSchema.php`, SHA-256 `e5de679739cb30018e7e23b5c875c22de62f41da1c003112cc61456f4ba1928b`. The design it implements has SHA-256 `bb5dfe36ec33aabccb80e814f46da7724ebb13d8c794d302a3c35d861a4d77fb`. Git HEAD is `4e77285…`; as recorded in the design, it differs from the checkpoint's `648703f…` only by one owner-approved commit of files under `videos/`.

**Scope.** Two proposed migrations, PostgreSQL only (SQLite keeps its closed historical boundary, as for 3c-1):

| Proposed file (name only) | Content |
| --- | --- |
| `2026_10_10_010000_install_ai_verification_invocation_successor.php` (**I1**) | Preflight; two replaced functions; two replaced CHECK constraints (`NOT VALID`); two new deferred constraint triggers; two partial indexes. Guarded down. |
| `2026_10_10_010100_validate_ai_verification_invocation_successor.php` (**I2**) | Validates the two CHECK constraints. No down. |

Both names contain `_ai_verification_` on purpose: existing tests select AI migrations by that substring (§6).

No table, no column, no data change, no backfill, no seeded profile, grant, approval, acknowledgement or enabled control.

**Limits that a reviewer must keep in mind:**

- **The listings have been rehearsed only in disposable databases**: by the package's author (§11.2) and, separately and with its own scripts, by the independent reviewer (§11.3). Neither is the authorized execution.
- The author did not run the frozen PostgreSQL suites with the new migrations present. The independent reviewer did, in a scratch copy of the repository with one disclosed deviation (§11.3).
- No PHP inference writer exists yet, so no rehearsal exercised one. Lifecycle writes in every race were SQL reproductions of what the PHP services write. Nothing was assessed at production-like volume.
- The underlying 3c-1 evidence is unchanged: a partitioned SQLite manifest, a broad concurrency run with one error followed by explicit reruns, and two unavailable intermediate setup-migration logs. It is not one uninterrupted clean execution.
- Every test in §10 is **NOT RUN**. The rehearsal is not a substitute for them.

## 2. Corrections to the design found while writing concrete DDL

Writing exact DDL against the frozen listing changed four details of design §12.1. None alters a design decision.

1. **Tighter admission bounds.** The intent branch uses the accepted legacy admission proxy, `estimated_tokens` 1,024–8,192 and `request_bytes` 1–7,168 (bytes + 1,024 ≤ 8,192), not the wider 1–8,192 and 1–16,384 written in the design.
2. **No new unique index on `interaction_id`.** The original table already declares it `UNIQUE` (`2026_10_08_183139_create_assistant_provider_metadata_tables.php`). The proposed partial unique index is dropped as redundant.
3. **Two deferred constraint triggers are added.** The design listed shape and guard changes only. With concrete DDL it is cheap and worthwhile for the database itself to refuse an admission or a send authorization against changed authority, and to refuse a use timestamp that is not the send instant of an admitted inference attempt (§3.6, §3.7).
4. **The intent branch is a complete third branch.** The frozen gateway branch requires `proposed_activated_generation`, `verifier_policy_key` and `verifier_policy_sha256` to be non-null, so the intent branch cannot share its null rules. Keeping it separate also lets the two existing branches stay byte-identical (§4).

5. **Revision 2 — file names.** Both migration names now contain `_ai_verification_` (review defect D1).
6. **Revision 2 — `WHEN` clause.** The attempt trigger fires only for gateway inference rows and thereby depends on the two columns it names (review defect D2, §3.6).

Also confirmed: the 3a guards `date_last_used_at` and `monotonic_last_used_at` already exist on `tenant_ai_credentials` and remain. The successor guard is stricter and consistent with them.

## 3. Concrete schema diff and ordering

The **exclusive** table locks inside I1 are taken in canonical class order (credentials → approvals → attempts). That is not the first thing I1 locks: its preflight reads attempts, then approvals, then credentials, taking `ACCESS SHARE` on each and holding it to commit, and those are upgraded later. `ACCESS SHARE` conflicts only with `ACCESS EXCLUSIVE`; with admission closed and the 250 ms lock timeout a conflict fails and leaves the catalog unchanged. The I1 header comment says "table locks are taken in canonical class order"; read it as describing the exclusive locks. The comment is deliberately left as it is so that the reviewed listing keeps its hash. The migration must issue the whole I1 listing as **one statement batch** (a single `DB::unprepared` call): several existing tests call a migration's `up()` and `down()` directly, outside the migrator's transaction, and a single batch is atomic there too. The same holds for the down.

### 3.1 I1 up — complete listing

```sql
-- REVIEW LISTING ONLY. No execution authorized.
-- I1: 2026_10_10_010000_install_ai_verification_invocation_successor.php (proposed name only)
-- Laravel wraps I1 in one PostgreSQL transaction, and the migration issues this whole listing as ONE
-- statement batch, so it is also atomic when a test calls up() directly. Execute only with all AI admission closed
-- and writers drained. Table locks are taken in canonical class order:
-- credentials, then approvals, then attempts.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
DO $pre$
BEGIN
  -- Fail closed on any state outside the accepted 3c-1 boundary.
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts
       WHERE contract_version='gateway_v1' AND purpose IS DISTINCT FROM 'connection_probe')
    OR EXISTS(SELECT 1 FROM public.assistant_provider_tenants
       WHERE contract_version='gateway_v1' AND purpose IS DISTINCT FROM 'connection_probe')
    OR EXISTS(SELECT 1 FROM public.tenant_ai_credentials WHERE last_used_at IS NOT NULL)
    THEN RAISE EXCEPTION 'AI invocation migration precondition failed'; END IF;
  -- The two checks being replaced must be the validated 3c-1 constraints.
  IF NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='public.assistant_provider_attempts'::regclass
       AND conname='assistant_provider_attempts_values' AND convalidated)
    OR NOT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid='public.assistant_provider_tenants'::regclass
       AND conname='ai3c_approval_shape_ck' AND convalidated)
    THEN RAISE EXCEPTION 'AI invocation migration precondition failed'; END IF;
  -- The two functions being replaced must carry exactly the frozen 3c-1 bodies.
  IF (SELECT md5(prosrc) FROM pg_proc WHERE proname='ai3c_metadata_guard' AND pronamespace='public'::regnamespace)
       IS DISTINCT FROM '575a44e857a1bbee79e2e936172bb640'
    OR (SELECT md5(prosrc) FROM pg_proc WHERE proname='ai3c_credential_guard' AND pronamespace='public'::regnamespace)
       IS DISTINCT FROM 'f5b51587e2fad3f8742b5a494f6e6ff6'
    OR NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='ai3c_verification_authority_v1' AND NOT tgisinternal)
    OR NOT EXISTS(SELECT 1 FROM pg_trigger WHERE tgname='ai3c_attempt_integrity' AND NOT tgisinternal)
    THEN RAISE EXCEPTION 'AI invocation migration precondition failed'; END IF;
END $pre$;

-- Successor of the frozen guard: only the gateway-attempt mutable list is branched by purpose.
CREATE OR REPLACE FUNCTION public.ai3c_metadata_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
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
    IF OLD.purpose='assistant_intent' THEN
      mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','input_tokens','output_tokens','actual_micro_usd'];
    ELSE
    mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','observed_at','verification_outcome',
      'claim_strength','observed_organization_id','observed_workspace_id','observed_model_id','evidence_id',
      'receipt_key_id','receipt_mac','promotion_expires_at','promotion_disposition','committed_activated_generation'];
    END IF;
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

-- Successor of the frozen guard: only the first statement (unconditional last_used_at refusal) changes.
CREATE OR REPLACE FUNCTION public.ai3c_credential_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE fields text[];
BEGIN
  IF TG_OP='INSERT' THEN
    IF NEW.last_used_at IS NOT NULL THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
  ELSIF NEW.last_used_at IS DISTINCT FROM OLD.last_used_at THEN
    IF NEW.last_used_at IS NULL OR OLD.state<>'active' OR NEW.state<>'active'
      OR NEW.verification_state<>'verified' OR NEW.secret_destroyed_at IS NOT NULL
      OR (OLD.last_used_at IS NOT NULL AND NEW.last_used_at<=OLD.last_used_at)
      OR (to_jsonb(NEW)-ARRAY['last_used_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['last_used_at','updated_at'])
      THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
  END IF;
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

-- A use timestamp exists only as the send-authorization instant of one admitted inference attempt.
CREATE FUNCTION public.ainv1_credential_use_constraint() RETURNS trigger LANGUAGE plpgsql AS $f$
BEGIN
  IF NEW.last_used_at IS NOT DISTINCT FROM OLD.last_used_at THEN RETURN NULL; END IF;
  IF NOT EXISTS (SELECT 1 FROM public.assistant_provider_attempts a
    WHERE a.contract_version='gateway_v1' AND a.purpose='assistant_intent'
      AND a.credential_version_id=NEW.id AND a.connection_id=NEW.connection_id
      AND a.workspace_id=NEW.workspace_id AND a.deployment_id=NEW.deployment_id
      AND a.send_authorized_at=NEW.last_used_at)
    THEN RAISE EXCEPTION 'AI credential use without authorized attempt'; END IF;
  RETURN NULL;
END $f$;
CREATE CONSTRAINT TRIGGER ainv1_credential_use AFTER UPDATE ON public.tenant_ai_credentials
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ainv1_credential_use_constraint();

-- Owner approval shape: legacy and connection_probe branches are byte-identical to the frozen
-- listing; one assistant_intent branch is appended.
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_approval_shape_ck;
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
  OR (contract_version='gateway_v1' AND legal_entity_id IS NOT NULL AND environment='production'
    AND deployment_id IS NOT NULL AND profile_id IS NOT NULL AND ownership_kind='customer_managed'
    AND account_budget_id IS NOT NULL AND connection_id IS NOT NULL AND selection_revision>0
    AND disclosure_id IS NOT NULL AND entitlement_revision>0 AND purpose='assistant_intent'
    AND verifier_policy_sha256 IS NULL AND account_mapping_sha256 ~ '^[0-9a-f]{64}$')
) IS TRUE) NOT VALID;

-- Attempt shape: same method.
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
 )
 OR (contract_version='gateway_v1' AND purpose='assistant_intent'
  AND deployment_id IS NOT NULL AND provider_key IS NOT NULL AND ownership_kind='customer_managed'
  AND profile_id IS NOT NULL AND connection_id IS NOT NULL AND credential_version_id IS NOT NULL
  AND vap_reference_id IS NULL AND endpoint_policy_key IS NOT NULL AND disclosure_id IS NOT NULL
  AND usage_budget_id IS NOT NULL AND aggregate_budget_id IS NOT NULL
  AND owner_approval_id IS NOT NULL AND acknowledgement_id IS NOT NULL
  AND workspace_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND legal_entity_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND credential_created_at IS NOT NULL AND credential_family IS NOT NULL
  AND acknowledged_at_snapshot IS NOT NULL
  AND monetary_applicable AND reserved_micro_usd>0 AND reserved_output_units>0
  AND selection_revision>0 AND entitlement_revision>0
  AND credential_version_number>0 AND credential_wrap_revision>0
  AND expected_settings_revision=selection_revision AND expected_connection_revision>0
  AND prior_selected_version_id=credential_version_id
  AND prior_active_generation>=1 AND proposed_activated_generation IS NULL
  AND actor_membership_id>0
  AND profile_manifest_sha256 ~ '^[0-9a-f]{64}$' AND account_mapping_sha256 ~ '^[0-9a-f]{64}$'
  AND verifier_policy_key IS NULL AND verifier_policy_sha256 IS NULL
  AND public.ai3c_refs_shape(authority_references)
  AND evidence_realm IN ('provider_tls','offline_fixture')
  AND estimated_tokens BETWEEN 1024 AND 8192 AND request_bytes BETWEEN 1 AND 7168
  AND verification_outcome='not_observed' AND observed_at IS NULL AND claim_strength IS NULL
  AND observed_organization_id IS NULL AND observed_workspace_id IS NULL AND observed_model_id IS NULL
  AND evidence_id IS NULL AND receipt_key_id IS NULL AND receipt_mac IS NULL
  AND promotion_expires_at IS NULL AND promotion_disposition IS NULL
  AND committed_activated_generation IS NULL
  AND (send_authorized_at IS NULL OR send_authorized_at>=admitted_at)
  AND ((state='admitted' AND outcome IS NULL AND finalized_at IS NULL
      AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
   OR (finalized_at IS NOT NULL AND finalized_at>=admitted_at
      AND (send_authorized_at IS NULL OR send_authorized_at<=finalized_at)
      AND ((state='failed' AND outcome='not_sent' AND send_authorized_at IS NULL
          AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
        OR (state='usage_unknown' AND outcome='usage_unknown' AND send_authorized_at IS NOT NULL
          AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
        OR (send_authorized_at IS NOT NULL
          AND ((state='received' AND outcome='received')
            OR (state='failed' AND outcome IN ('failed','profile_mismatch')))
          AND input_tokens BETWEEN 0 AND 1000000 AND output_tokens BETWEEN 0 AND 1024
          AND actual_micro_usd>=0))))
 )
 )) IS TRUE) NOT VALID;

-- Structural backstop for admission and the one send-authorization transition.
-- It is an integrity validator, not a cryptographic verifier or a policy-admission routine.
CREATE FUNCTION public.ainv1_intent_constraint() RETURNS trigger LANGUAGE plpgsql AS $f$
DECLARE s public.tenant_ai_settings%ROWTYPE; c public.tenant_ai_connections%ROWTYPE;
        v public.tenant_ai_credentials%ROWTYPE; r public.assistant_provider_attempts%ROWTYPE;
BEGIN
  IF NEW.contract_version IS DISTINCT FROM 'gateway_v1' OR NEW.purpose IS DISTINCT FROM 'assistant_intent'
    THEN RETURN NULL; END IF;
  -- Finalization and recovery must still succeed after revoke, rotate or disable,
  -- so current authority is asserted only at admission and at send authorization.
  IF NOT (TG_OP='INSERT' OR (OLD.send_authorized_at IS NULL AND NEW.send_authorized_at IS NOT NULL))
    THEN RETURN NULL; END IF;
  -- FOR SHARE in canonical order. Redundant when the writer already holds FOR UPDATE; otherwise
  -- it makes a concurrent lifecycle commit wait for, or be seen by, this check.
  SELECT * INTO s FROM public.tenant_ai_settings
    WHERE workspace_id=NEW.workspace_id AND deployment_id=NEW.deployment_id FOR SHARE;
  SELECT * INTO c FROM public.tenant_ai_connections WHERE id=NEW.connection_id FOR SHARE;
  SELECT * INTO v FROM public.tenant_ai_credentials WHERE id=NEW.credential_version_id FOR SHARE;
  IF s.workspace_id IS NULL OR c.id IS NULL OR v.id IS NULL
    OR s.mode IS DISTINCT FROM 'customer_managed' OR s.profile_id IS DISTINCT FROM NEW.profile_id
    OR s.connection_id IS DISTINCT FROM NEW.connection_id
    OR s.credential_version_id IS DISTINCT FROM NEW.credential_version_id
    OR s.revision IS DISTINCT FROM NEW.expected_settings_revision
    OR s.entitlement_revision IS DISTINCT FROM NEW.entitlement_revision
    OR c.disabled OR c.revoked_at IS NOT NULL
    OR c.revision IS DISTINCT FROM NEW.expected_connection_revision
    OR c.active_generation IS DISTINCT FROM NEW.prior_active_generation
    OR v.state IS DISTINCT FROM 'active' OR v.verification_state IS DISTINCT FROM 'verified'
    OR v.secret_destroyed_at IS NOT NULL
    OR v.activated_generation IS DISTINCT FROM NEW.prior_active_generation
    OR v.wrap_revision IS DISTINCT FROM NEW.credential_wrap_revision
    THEN RAISE EXCEPTION 'AI invocation authority changed'; END IF;
  SELECT * INTO r FROM public.assistant_provider_attempts WHERE id=v.verification_operation_id;
  IF r.id IS NULL OR r.promotion_disposition IS DISTINCT FROM 'promoted'
    OR r.account_mapping_sha256 IS DISTINCT FROM NEW.account_mapping_sha256
    OR r.legal_entity_id IS DISTINCT FROM NEW.legal_entity_id
    OR r.profile_id IS DISTINCT FROM NEW.profile_id
    THEN RAISE EXCEPTION 'AI invocation receipt binding'; END IF;
  IF TG_OP='UPDATE' AND v.last_used_at IS DISTINCT FROM NEW.send_authorized_at
    THEN RAISE EXCEPTION 'AI invocation use unrecorded'; END IF;
  RETURN NULL;
END $f$;
-- The WHEN clause keeps legacy and probe rows from queueing this check, and makes the trigger depend on
-- both columns, so the 3c-1 metadata down refuses to drop them while this successor is installed.
CREATE CONSTRAINT TRIGGER ainv1_intent_authority AFTER INSERT OR UPDATE ON public.assistant_provider_attempts
  DEFERRABLE INITIALLY DEFERRED FOR EACH ROW
  WHEN (NEW.contract_version='gateway_v1' AND NEW.purpose='assistant_intent')
  EXECUTE FUNCTION public.ainv1_intent_constraint();

CREATE INDEX ainv1_intent_use_idx ON public.assistant_provider_attempts(credential_version_id,send_authorized_at)
  WHERE contract_version='gateway_v1' AND purpose='assistant_intent' AND send_authorized_at IS NOT NULL;
CREATE INDEX ainv1_intent_recovery_idx ON public.assistant_provider_attempts(admitted_at,id)
  WHERE contract_version='gateway_v1' AND purpose='assistant_intent' AND state='admitted';
```

### 3.2 I2 up — complete listing

```sql
-- REVIEW LISTING ONLY. I2: 2026_10_10_010100_validate_ai_verification_invocation_successor.php (proposed name only).
-- One transaction; no admission or feature-availability change.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT assistant_provider_attempts_values;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_shape_ck;
```

### 3.3 I1 down — complete listing

```sql
-- REVIEW LISTING ONLY. I1 down. I2 has no down (validation is not reversed).
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
DO $down$
BEGIN
  IF EXISTS(SELECT 1 FROM public.assistant_provider_attempts WHERE contract_version='gateway_v1' AND purpose IS DISTINCT FROM 'connection_probe')
    OR EXISTS(SELECT 1 FROM public.assistant_provider_tenants WHERE contract_version='gateway_v1' AND purpose IS DISTINCT FROM 'connection_probe')
    OR EXISTS(SELECT 1 FROM public.tenant_ai_credentials WHERE last_used_at IS NOT NULL)
    THEN RAISE EXCEPTION 'AI invocation downgrade refused: retained data'; END IF;
END $down$;
DROP TRIGGER ainv1_credential_use ON public.tenant_ai_credentials;
DROP FUNCTION public.ainv1_credential_use_constraint();
-- Restore the frozen 3c-1 checks byte-for-byte, then validate (no intent rows can exist here).
ALTER TABLE public.assistant_provider_tenants DROP CONSTRAINT ai3c_approval_shape_ck;
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
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_shape_ck;
DROP TRIGGER ainv1_intent_authority ON public.assistant_provider_attempts;
DROP FUNCTION public.ainv1_intent_constraint();
DROP INDEX public.ainv1_intent_recovery_idx;
DROP INDEX public.ainv1_intent_use_idx;
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
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT assistant_provider_attempts_values;
-- Restore the frozen 3c-1 function bodies byte-for-byte.
CREATE OR REPLACE FUNCTION public.ai3c_credential_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
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
CREATE OR REPLACE FUNCTION public.ai3c_metadata_guard() RETURNS trigger LANGUAGE plpgsql AS $f$
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
```

### 3.4 The new attempt branch, isolated for reading

```sql
 OR (contract_version='gateway_v1' AND purpose='assistant_intent'
  AND deployment_id IS NOT NULL AND provider_key IS NOT NULL AND ownership_kind='customer_managed'
  AND profile_id IS NOT NULL AND connection_id IS NOT NULL AND credential_version_id IS NOT NULL
  AND vap_reference_id IS NULL AND endpoint_policy_key IS NOT NULL AND disclosure_id IS NOT NULL
  AND usage_budget_id IS NOT NULL AND aggregate_budget_id IS NOT NULL
  AND owner_approval_id IS NOT NULL AND acknowledgement_id IS NOT NULL
  AND workspace_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND legal_entity_public_id ~ '^[0-7][0-9a-hjkmnp-tv-z]{25}$'
  AND credential_created_at IS NOT NULL AND credential_family IS NOT NULL
  AND acknowledged_at_snapshot IS NOT NULL
  AND monetary_applicable AND reserved_micro_usd>0 AND reserved_output_units>0
  AND selection_revision>0 AND entitlement_revision>0
  AND credential_version_number>0 AND credential_wrap_revision>0
  AND expected_settings_revision=selection_revision AND expected_connection_revision>0
  AND prior_selected_version_id=credential_version_id
  AND prior_active_generation>=1 AND proposed_activated_generation IS NULL
  AND actor_membership_id>0
  AND profile_manifest_sha256 ~ '^[0-9a-f]{64}$' AND account_mapping_sha256 ~ '^[0-9a-f]{64}$'
  AND verifier_policy_key IS NULL AND verifier_policy_sha256 IS NULL
  AND public.ai3c_refs_shape(authority_references)
  AND evidence_realm IN ('provider_tls','offline_fixture')
  AND estimated_tokens BETWEEN 1024 AND 8192 AND request_bytes BETWEEN 1 AND 7168
  AND verification_outcome='not_observed' AND observed_at IS NULL AND claim_strength IS NULL
  AND observed_organization_id IS NULL AND observed_workspace_id IS NULL AND observed_model_id IS NULL
  AND evidence_id IS NULL AND receipt_key_id IS NULL AND receipt_mac IS NULL
  AND promotion_expires_at IS NULL AND promotion_disposition IS NULL
  AND committed_activated_generation IS NULL
  AND (send_authorized_at IS NULL OR send_authorized_at>=admitted_at)
  AND ((state='admitted' AND outcome IS NULL AND finalized_at IS NULL
      AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
   OR (finalized_at IS NOT NULL AND finalized_at>=admitted_at
      AND (send_authorized_at IS NULL OR send_authorized_at<=finalized_at)
      AND ((state='failed' AND outcome='not_sent' AND send_authorized_at IS NULL
          AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
        OR (state='usage_unknown' AND outcome='usage_unknown' AND send_authorized_at IS NOT NULL
          AND input_tokens IS NULL AND output_tokens IS NULL AND actual_micro_usd IS NULL)
        OR (send_authorized_at IS NOT NULL
          AND ((state='received' AND outcome='received')
            OR (state='failed' AND outcome IN ('failed','profile_mismatch')))
          AND input_tokens BETWEEN 0 AND 1000000 AND output_tokens BETWEEN 0 AND 1024
          AND actual_micro_usd>=0))))
 )
```

What it permits, in words:

| State | Outcome | Send mark | Usage fields |
| --- | --- | --- | --- |
| `admitted` | null | null or set | all null |
| `failed` | `not_sent` | **null** | all null |
| `usage_unknown` | `usage_unknown` | **set** | all null |
| `received` | `received` | set | all set, within the legacy bounds |
| `failed` | `failed` or `profile_mismatch` | set | all set, within the legacy bounds |

A send that was authorized can never be recorded as not sent, and an attempt that was never authorized can never be recorded as possibly transmitted. That is the database form of the design's truthful-cancellation rule.

The branch keeps `verification_outcome='not_observed'` for the whole life of an intent attempt. That leaves the frozen insert guard `ai3c_attempt_initial_guard`, which requires that value for every gateway insert, untouched.

### 3.5 The new approval branch, isolated for reading

```sql
  OR (contract_version='gateway_v1' AND legal_entity_id IS NOT NULL AND environment='production'
    AND deployment_id IS NOT NULL AND profile_id IS NOT NULL AND ownership_kind='customer_managed'
    AND account_budget_id IS NOT NULL AND connection_id IS NOT NULL AND selection_revision>0
    AND disclosure_id IS NOT NULL AND entitlement_revision>0 AND purpose='assistant_intent'
    AND verifier_policy_sha256 IS NULL AND account_mapping_sha256 ~ '^[0-9a-f]{64}$')
```

### 3.6 `ainv1_intent_authority` — what it enforces and what it does not

It acts only on gateway intent attempts, and only on two events: the insert (admission) and the single update that sets `send_authorized_at` (the linearization transaction of design §8). At the commit of that transaction it requires, from current rows:

- settings: customer mode, same profile, connection and selected version, same revision and entitlement revision;
- connection: not disabled, not revoked, same revision, same active generation;
- credential: active, verified, envelope not destroyed, same activated generation and wrap revision;
- the credential's promoted verification attempt carries the same account-mapping digest, legal entity and profile;
- on send authorization, `credential.last_used_at` equals the attempt's `send_authorized_at`.

It deliberately does nothing on finalization or recovery, so those succeed after revoke, rotate or disable.

The trigger carries `WHEN (NEW.contract_version='gateway_v1' AND NEW.purpose='assistant_intent')`. Legacy and probe rows therefore never queue it. The clause also makes the trigger depend on those two columns, which is what turns an out-of-order reversal of the 3c-1 migrations from silent damage into a refusal (§8).

It takes `FOR SHARE` on the settings, connection and credential rows, in that order. When the writer already holds the deployment root and `FOR UPDATE` on those rows, as the design requires, the reads are a no-op.

**They are not safe for a writer that skipped those locks, and revision 2 was wrong to imply they were.** Such a writer reaches commit already holding later locks (its attempt row, and the credential row from the use write or the foreign-key lock from admission) and only then asks for the settings row. Every lifecycle operation takes root, then settings, then connection, then credentials. The two can therefore form a lock cycle: the lifecycle writer holds settings and waits for the credential, the inference writer holds the credential and waits for settings. For that writer the trigger does exactly what the canonical order forbids, acquiring an earlier lock class late. The independent review reproduced it for both send authorization and bare admission. What then happens:

- PostgreSQL resolves it by lock timeout where the waiting transaction has set one, and otherwise by deadlock detection. **Not every existing writer sets a lock timeout.** The verification, active-lifecycle and recovery transactions set 250 ms through `TenantAiVerificationAdmission::limits`, and the design's inference transactions are specified to. `TenantAiLifecycle::transaction`, to which `disableWorkspace` delegates, and `TenantAiStorage::configure` set none in their own code, so against those a cycle waits for deadlock detection (about one second in the reviewer's runs).
- **Either transaction can be the one aborted, including a revoke or a disable**, which then has changed nothing and must be retried. In the reviewer's three reproductions the aborted transaction was the revoke each time. That the sender can be the victim instead, and that a disable behaves like a revoke here, follow from the lock graph and were not observed.
- It is fail-safe: in no interleaving is a send authorized after a lifecycle change has committed.

**Invariant for the implementation:** every transaction that inserts an inference attempt or sets a send mark must hold the deployment root and the settings, connection and credential row locks, in canonical order, **before its first write**. Design §7 step 4, §8 and §9.1 already specify this, and every existing writer of these rows takes the root first. With both sides taking the root first no cycle is possible, and none was observed. The trigger is a backstop behind that invariant, not a replacement for it.

A fail-fast variant (`FOR SHARE NOWAIT`) was rehearsed by the independent reviewer and makes the non-conforming writer the one refused. It is not adopted here: it would change the reviewed listing, and it does not remove the underlying wait, because a lifecycle writer still blocks on the credential row the non-conforming writer has already updated. The invariant is the actual requirement.

It is a structural backstop. It does not verify the receipt MAC, does not prove provider contact, does not check approvals' expiry, acknowledgements, controls or budgets, and is not a substitute for the application's admission and prerequisite checks.

### 3.7 `ainv1_credential_use` and the successor credential guard

Changed statement in `ai3c_credential_guard` (the rest of the function is byte-identical):

```sql
-- frozen
  IF NEW.last_used_at IS NOT NULL THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
-- successor
  IF TG_OP='INSERT' THEN
    IF NEW.last_used_at IS NOT NULL THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
  ELSIF NEW.last_used_at IS DISTINCT FROM OLD.last_used_at THEN
    IF NEW.last_used_at IS NULL OR OLD.state<>'active' OR NEW.state<>'active'
      OR NEW.verification_state<>'verified' OR NEW.secret_destroyed_at IS NOT NULL
      OR (OLD.last_used_at IS NOT NULL AND NEW.last_used_at<=OLD.last_used_at)
      OR (to_jsonb(NEW)-ARRAY['last_used_at','updated_at']) IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['last_used_at','updated_at'])
      THEN RAISE EXCEPTION 'Customer inference unavailable'; END IF;
  END IF;
```

Together with the deferred `ainv1_credential_use` check: a use timestamp can be written only on an active, verified, undestroyed row, only forward in time, with no other column changing except `updated_at`, and only if a gateway intent attempt for that exact version, connection, workspace and deployment has that instant as its send authorization. No revision or generation changes, so concurrent invocations of one tenant do not stale each other. Retirement keeps the historical value.

The refusal keeps the text `Customer inference unavailable`.

### 3.8 The successor metadata guard

Changed statement in `ai3c_metadata_guard` (the rest is byte-identical, including the control, approval and acknowledgement branches):

```sql
-- frozen
    mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','observed_at','verification_outcome',
      'claim_strength','observed_organization_id','observed_workspace_id','observed_model_id','evidence_id',
      'receipt_key_id','receipt_mac','promotion_expires_at','promotion_disposition','committed_activated_generation'];
-- successor
    IF OLD.purpose='assistant_intent' THEN
      mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','input_tokens','output_tokens','actual_micro_usd'];
    ELSE
    mutable:=ARRAY['state','outcome','finalized_at','send_authorized_at','observed_at','verification_outcome',
      'claim_strength','observed_organization_id','observed_workspace_id','observed_model_id','evidence_id',
      'receipt_key_id','receipt_mac','promotion_expires_at','promotion_disposition','committed_activated_generation'];
    END IF;
```

Intent attempts may change only state, outcome, finalization time, the send mark and the three usage fields. They cannot write any receipt, observation or promotion field. Probe attempts keep exactly the frozen list and still cannot write usage. The existing rules that a terminal row is immutable and that the send mark is write-once apply to both.

### 3.9 Indexes

| Index | Purpose |
| --- | --- |
| `ainv1_intent_use_idx (credential_version_id, send_authorized_at)`, partial | Bounded lookup for the use-timestamp check; without it the check would scan every attempt of a busy credential. |
| `ainv1_intent_recovery_idx (admitted_at, id)`, partial on `state='admitted'` | Bounded recovery scan. |

Both are created inside I1's transaction, not concurrently, which is acceptable only because I1 runs with admission closed.

## 4. Enforcement matrix and old/new equivalence

**Method: identity, not argument.** The legacy and `connection_probe` branches of both CHECK constraints are not rewritten. The generator copies them from the frozen listing by line range and asserts the copied bytes appear unmodified inside the new statements. A row with `purpose='connection_probe'` or a legacy row is therefore judged by exactly the text that judges it today, and a row cannot move between branches because the three branches are disjoint on `contract_version` and `purpose`.

| Frozen fragment reproduced verbatim | Frozen lines | SHA-256 of fragment | Bytes |
| --- | --- | --- | --- |
| attempt check: header conjunct, legacy branch and connection_probe branch | 379-434 | `96d35ca9da155749513706097aec7e40f49539c49a3dd31138b5d59d30a036dd` | 7,256 |
| approval check: legacy branch and connection_probe branch | 336-345 | `285345b2c8621b8cbe263ea0455abbadebb56eb40a8b644b5b0aba8e008b7361` | 887 |
| attempt check, whole frozen statement (restored by down) | 378-435 | `763e7c4e492d94e0a0d0a7030e2d55e00d68fb0da3cddc21f9e9f8d94bde4acd` | 7,387 |
| approval check, whole frozen statement (restored by down) | 335-346 | `24f7ef372cf06cfa30c0f8cdccf853e73a75ed6be9490730646ff7037e66c9f2` | 1,002 |
| gateway-attempt mutable list for non-intent purposes | 462-464 | `8fc5c1c2dbec297777753cbec0348e9864a4d4a78875a8a17c0b5e8b576e4c71` | 338 |

One structural note for the attempt check: in the frozen statement the gateway branch's closing parenthesis is the first character of the final line (line 435). The new statement reproduces lines 379–434 verbatim, closes that branch, appends the intent branch, then closes the remaining two groups. The frozen final line is therefore split, not altered in meaning. Parenthesis balance of every statement was checked mechanically (§11).

For the two replaced functions, the generator also proves the converse: reversing the single stated edit in each successor yields the frozen function text exactly.

| Requirement | Enforced by | New or reused |
| --- | --- | --- |
| Customer inference attempt can exist | third branch of `assistant_provider_attempts_values` | new |
| Inference approval can exist, and only binds inference | third branch of `ai3c_approval_shape_ck`; existing binding check compares purpose and both digests null-safely | new + reused |
| Attempt bound to its profile, connection, credential, approval, acknowledgement and budgets | `ai3c_check_attempt` via `ai3c_attempt_integrity` | **reused unchanged** |
| Exact nine-allocation set | same function via `ai3c_allocation_integrity` | **reused unchanged** |
| Admission fields immutable, terminal rows immutable, send mark write-once | `ai3c_metadata_guard` | reused; one list branched |
| Usage written once at the terminal transition | `ai3c_metadata_guard` intent list + terminal shapes of the CHECK | new |
| Authorized send never recorded as not sent | terminal shapes of the CHECK | new |
| Admission and send authorization only against current active authority | `ainv1_intent_authority` | new |
| Use timestamp only as the send instant of an admitted inference attempt | `ai3c_credential_guard` successor + `ainv1_credential_use` | new |
| Verified credential still matches its promoted receipt attempt after a use write | `ai3c_verification_authority_v1` | **reused unchanged** |
| Receipt authenticity, approvals' expiry, acknowledgement, controls, budgets, actor | application only | not a database claim |

I read `ai3c_check_attempt` line by line for probe-only assumptions (the design asked the package to confirm this). It has none that affects intent rows: its observed-model and promotion clauses are conditional on values an intent row never carries, and it compares no current revision, expiry or enabled state, so it passes on finalization after authority changes, as its own comment states.

## 5. Transaction and lock matrix

### 5.1 Migration

| Step | Lock taken | Held until |
| --- | --- | --- |
| I1 preflight (reads attempts, approvals, credentials, in that order) | ACCESS SHARE on each | I1 commit; upgraded by the rows below |
| `CREATE OR REPLACE FUNCTION` ×2, `CREATE FUNCTION` ×2 | none on tables | – |
| `CREATE CONSTRAINT TRIGGER` on `tenant_ai_credentials` | SHARE ROW EXCLUSIVE | I1 commit |
| `DROP`/`ADD CONSTRAINT … NOT VALID` on `assistant_provider_tenants` | ACCESS EXCLUSIVE | I1 commit |
| `DROP`/`ADD CONSTRAINT … NOT VALID`, trigger and two indexes on `assistant_provider_attempts` | ACCESS EXCLUSIVE | I1 commit |
| `VALIDATE CONSTRAINT` ×2 (I2) | SHARE UPDATE EXCLUSIVE, full scan of each table | I2 commit |
| **I1 down** preflight | ACCESS SHARE on attempts, approvals, credentials | down commit |
| **I1 down** `DROP TRIGGER` on `tenant_ai_credentials` | **ACCESS EXCLUSIVE** — stronger than anything the up takes on this table; blocks every read of credentials until commit | down commit |
| **I1 down** `DROP`/`ADD CONSTRAINT`, `VALIDATE` on `assistant_provider_tenants` | ACCESS EXCLUSIVE | down commit |
| **I1 down** `DROP TRIGGER`, `DROP INDEX` ×2, `DROP`/`ADD CONSTRAINT`, `VALIDATE` on `assistant_provider_attempts` | ACCESS EXCLUSIVE | down commit |

`lock_timeout` 250 ms and `statement_timeout` 10 s are set locally in each migration. DDL is transactional: a timeout or a failed precondition leaves the catalog exactly as it was. Between the drop and the re-add of a constraint there is no window, because both are in one transaction under ACCESS EXCLUSIVE.

Required operating state for the up **and the down**, as for 3c-1 M2: every AI admission switch closed, old writers drained, compatible code deployed. Not run against a busy system. Plan the down's window around the `ACCESS EXCLUSIVE` lock on `tenant_ai_credentials`: while it is held, nothing can read a credential row.

### 5.2 Runtime transactions of the design and the triggers each fires

| Design transaction | Writes | Immediate | Deferred at commit |
| --- | --- | --- | --- |
| 1 — admit | insert attempt, nine allocations, window updates | `ai3c_attempt_initial`, attempt CHECK, `ai3c_window_guard` | `ai3c_attempt_integrity`, `ai3c_allocation_integrity`, **`ainv1_intent_authority`** (insert) |
| 2 — JIT decrypt | none | – | – |
| 3 — send prerequisite | attempt `send_authorized_at`; credential `last_used_at` | `ai3c_attempt_guard`, attempt CHECK, `ai3c_credential_guard` (successor), 3a date/monotonic guards | `ai3c_attempt_integrity`, **`ainv1_intent_authority`** (send), `ai3c_verification_authority_v1`, **`ainv1_credential_use`** |
| 4 — finalize, and recovery | attempt terminal fields, window counters | `ai3c_attempt_guard`, attempt CHECK terminal shape, `ai3c_window_guard` | `ai3c_attempt_integrity`; `ainv1_intent_authority` returns without checking |

No new lock class. The only locks added are the `FOR SHARE` reads of §3.6. For a writer that holds the root and the canonical row locks before its first write they are redundant and the canonical order is unchanged. For a writer that does not, they are a late acquisition of an earlier lock class and can complete a lock cycle with a lifecycle operation, resolved by lock timeout or deadlock detection with either side aborted (§3.6). The invariant in §3.6 is therefore a condition of this design, not an optimisation.

## 6. Coexistence with the frozen 3c-1 tests

Four existing tests select AI migrations by file name. With I1 and I2 present:

| Test | How it selects | Effect with the revision-2 names |
| --- | --- | --- |
| `tests/Unit/PostgresAiVerificationUpgradeTest.php` (**frozen**) | "historical" = every migration whose name does **not** contain `_ai_verification_`; then `artisan migrate` | Both new files are excluded from the historical boundary and run after the three 3c-1 migrations, in timestamp order. No change to the frozen file. |
| `tests/Unit/PostgresTenantAiStorageTest.php` | glob `*_ai_verification_*.php`, reversed in name order, then re-applied | The successor is reversed first and re-applied last, which is the correct order. No edit needed. |
| `tests/Feature/TenantAiStorageTest.php` | same glob (PostgreSQL only) | Same. No edit needed. |
| `tests/Unit/PostgresAssistantProviderTest.php` (accepted, not frozen) | an explicit list of exactly the three 3c-1 files | **Must be edited**: reverse I2 and I1 before the three, re-apply them after. It adds steps and removes no assertion, so it is not a weakening. Without the edit this test performs the out-of-order reversal of §8 and now fails loudly at the 3c-1 metadata down. |

With the revision-1 names the frozen upgrade test would have run I1 against the pre-3c-1 schema and failed; the review reproduced that. The corrected names avoid it.

Other frozen assertions were inspected and are unaffected:

| Frozen assertion | Effect of I1+I2 |
| --- | --- |
| Listing hashes of `TenantAiVerificationSchema` methods and the 3c-1 package hash | Unaffected: that class and document are not edited. |
| Legacy bounds dataset and shape refusals naming `assistant_provider_attempts_values` | Unaffected: constraint name kept, legacy and probe branches byte-identical. |
| `count(*) … conname LIKE 'ai3c_%' AND NOT convalidated` equals 0 after a full migrate | Holds: `ai3c_approval_shape_ck` is revalidated by I2. New objects use the prefix `ainv1_`, which that pattern does not match. Confirmed in rehearsal. |
| `ai3c_stage_closed_%` counts; window-table constraint definitions; `downPreflight()` refusal | Unaffected. |

No frozen test asserts the unconditional `last_used_at` refusal, an `assistant_intent` refusal, or a usage-write refusal on anything but probe rows. The frozen schema fixtures were run in rehearsal with I1+I2 installed and still built a promoted active credential.

**No frozen file needs to change. One non-frozen test needs the edit above.** This is established by reading the tests and reproducing their selection; MP-21, MP-25 and MP-26 must prove it by running them.

## 7. Populated-data rehearsal plan — NOT RUN

To be executed only after this review permits it, in a disposable UTF-8 PostgreSQL database whose name carries the unchanged disposable-reset prefix:

1. Build the 3c-1 boundary with the existing migrations only. Capture a catalog snapshot (constraint definitions, function sources, triggers, indexes) and its hash.
2. Populate through the real offline 3c-1 paths, never by raw insertion of active rows: legacy attempts in every terminal state; probe attempts promoted, rejected, failed and abandoned; an active credential, a replaced one and a pending candidate; approvals and acknowledgements. Snapshot every row.
3. Confirm the I1 function-body MD5 preconditions against the live catalog. **If either differs, stop: that is a finding about the listing or the catalog, not something to adjust.**
4. Apply I1. Assert every snapshotted row is byte-identical, every receipt MAC still verifies, both checks are `NOT VALID` yet enforced on new rows.
5. Interrupt before I2; insert conforming and non-conforming rows; then apply I2 and assert both checks validated.
6. Run the direct-SQL matrix MP-02 to MP-16.
7. With no intent data, run the I1 down and assert the catalog snapshot hash equals step 1. Re-apply I1 and I2.
8. With intent data present, assert the down refuses and changes nothing.
9. Hold a conflicting lock and assert I1 and I2 fail within the lock timeout with the catalog unchanged.
10. Record commands, environment, exits, durations, log hashes and every failed attempt. Do not relabel a failed or partial run.

## 8. Down, restore and forward-only boundary

- I1 down refuses while any intent attempt, intent approval or use timestamp exists. Those rows are retained evidence; there is no destructive path.
- With none present, it removes the new triggers, functions and indexes and restores both checks and both functions from the frozen text, then validates the restored checks.
- I2 has no down, matching 3c-1's validation migration.
- A full rollback runs the I1 down before the 3c-1 downs, whose own preflight is unchanged.
- **Ordering rule: the three 3c-1 migrations must never be reversed while this successor is installed.** The migrator's own order guarantees it; a direct call to their `down()` does not. If it happens with no retained data, the 3c-1 integrity down runs, then the 3c-1 metadata down **refuses** because `ainv1_intent_authority` depends on `contract_version` and `purpose`. The gateway columns survive, legacy inserts keep working, the gateway branches are closed by the restored stage checks, and the operator sees the failure. That is fail-safe, not atomic. Rehearsed recovery: re-apply the 3c-1 integrity and validation migrations, then run the I1 down; the catalog is then byte-identical to the clean 3c-1 state. Running the I1 down directly at that point does **not** work: it fails because the 3c-1 integrity down has already removed a constraint the I1 down expects to replace. Without the `WHEN` clause the same mistake succeeded silently and broke every legacy attempt insert; the review reproduced that.
- Once inference data exists the migration is forward-only. A restored backup must stay closed and be reconciled before any enablement, as supplement §9 already requires.

## 9. Later implementation-file map — no changes in this checkpoint

| File | Change in the later, separately authorized run |
| --- | --- |
| `app/Fiscal/TenantAiInvocationSchema.php` (new) | The three listings of §3 as literal strings, mirroring `TenantAiVerificationSchema`. |
| Two migration files named in §1 (new) | Execute those listings; PostgreSQL only. |
| A schema contract test (new) | Binds each listing's SHA-256 to this package's evidence, as `AiVerificationSchemaContractTest` does for 3c-1. |
| Runtime classes and edits of design §12.2 | Unchanged from the design. |
| `tests/Unit/PostgresAssistantProviderTest.php` (not frozen) | Extend its explicit successor list as described in §6. Adds steps, removes no assertion. |
| Every 3a/3b/3c-1 custody, verifier, receipt, lifecycle and schema file; all 14 frozen files | **No change.** |

### 9.1 Conditions on the later implementation, found in independent rehearsal

These need no change to the listings. They bind the writer of transaction 3 and its tests.

1. **Locks before the first write** — the invariant of §3.6.
2. **Both writes before constraints are forced.** Once `SET CONSTRAINTS ALL IMMEDIATE` has run, the send mark and the use instant can no longer be written in either order. A test that builds its tenant with the frozen `verificationMigrationActiveFixture()` and continues in the same transaction must first issue `SET CONSTRAINTS ALL DEFERRED`, because that fixture leaves the transaction in immediate mode.
3. **One send authorization per credential per transaction.**
4. **Read the send instant after the credential row lock is held**, from the primary's `clock_timestamp()` only. An instant read before waiting is refused if a later one commits first.
5. **Nothing in the database ties the instant to real time.** A far-future instant is accepted and then blocks every later use of that credential until rotation. The writer must never accept an instant from anywhere but the primary clock.
6. **The two checks do not prove the credential row was written.** An attempt admitted earlier can be authorized at the credential's existing use instant without a credential write. Authority is still checked at that commit, so nothing is authorized against changed authority, but the writer must always perform both writes.
7. **Expiry is not a database rule** for the credential, the owner approval or profile validity at send time. The reviewer executed the credential and approval cases; that profile validity is not re-checked by the database at send time was established by reading. The application checks of design §6 remain the only enforcement.

## 10. Proposed tests — ALL NOT RUN

| ID | Requirement |
| --- | --- |
| MP-01 | I1 preflight refuses each out-of-boundary state: an existing non-probe gateway attempt, a non-probe gateway approval, a non-null last_used_at, an unvalidated check, an altered function body, a missing 3c-1 trigger. |
| MP-02 | Legacy attempt rows: every accepted and refused value in the frozen legacy dataset behaves identically before and after I1+I2. |
| MP-03 | connection_probe attempt rows: every accepted and refused shape in the frozen probe dataset behaves identically before and after. |
| MP-04 | A probe row can never satisfy the intent branch and an intent row can never satisfy the probe branch (purpose swap, each field of the other branch set). |
| MP-05 | Intent admission shape: each required field null, each forbidden field non-null, each bound at and beyond its limit (estimated tokens 1023/1024/8192/8193, request bytes 0/1/7168/7169, generation 0/1). |
| MP-06 | Intent terminal shapes: admitted, not_sent, usage_unknown, received, failed, profile_mismatch each accepted only with its exact combination of send mark, usage and outcome; not_sent with a send mark and usage_unknown without one refused. |
| MP-07 | Intent attempt immutability: usage fields writable once at the terminal transition; any admission field, any later change and a second send mark refused. Probe attempts still refuse usage writes. |
| MP-08 | Owner approval: intent purpose accepted with a null verifier digest and refused with one; probe approvals unchanged; unknown purpose refused. |
| MP-09 | An intent attempt bound to a probe approval, or to an approval of another entity, profile, account, connection or revision, is refused by the existing binding check. |
| MP-10 | last_used_at: refused on insert, on pending, replaced and revoked rows, when decreasing or equal, when nulled, when any other column changes in the same update, and when no intent attempt carries that send instant. |
| MP-11 | last_used_at accepted only together with the matching send-authorization in one transaction; committing either write alone is refused. |
| MP-12 | Intent admission refused when settings mode, profile, connection, selected version, revision or entitlement differ, the connection is disabled or revoked or its revision or generation differs, or the credential is not active, not verified, destroyed, of another generation or wrap revision. |
| MP-13 | Send authorization refused in each of the same states; finalization and recovery still succeed in every one of them. |
| MP-14 | Independent PostgreSQL workers with barriers: send authorization and admission versus revoke, rotation, connection disable, workspace disable, candidate store and settings change, in both commit orders. WITH the writer holding root and the canonical row locks before its first write: the later transaction either completes or fails on the 250 ms lock timeout having changed nothing, and no deadlock is reported. WITHOUT them (the interleaving of package section 3.6): abort of EITHER party by lock timeout or deadlock detection is accepted, and the test asserts only what is guaranteed, namely no send mark after a committed lifecycle change and no partial write by the aborted party. |
| MP-15 | Receipt binding: an intent attempt whose account-mapping digest, legal entity or profile differs from the credential's promoted verification attempt is refused. |
| MP-16 | Exact nine-allocation set enforced for intent attempts by the unchanged allocation check; missing, extra and duplicate rows refused. |
| MP-17 | Populated upgrade: legacy rows, probe attempts with promoted and failed receipts, active and replaced credentials, approvals and acknowledgements are byte-identical after I1+I2; receipt MACs still verify. |
| MP-18 | Staged validation: interruption between I1 and I2 leaves both checks enforcing new rows; I2 rerun completes; no ai3c_ constraint is left unvalidated after a full migrate. |
| MP-19 | Down refused with any intent attempt, intent approval or use timestamp; with none, down restores constraint definitions and both function bodies identical to the pre-I1 catalog snapshot. |
| MP-20 | Up then down then up is idempotent on the catalog; the 3c-1 down chain still runs after the I1 down. |
| MP-21 | All frozen 3c-1 schema, migration, upgrade and concurrency tests pass unmodified with I1 and I2 present, including the frozen upgrade test whose historical selection must exclude both new files by name. |
| MP-25 | The two tests that select *_ai_verification_*.php by glob reverse the successor first and re-apply it last, and pass unmodified. |
| MP-26 | The updated explicit-list round trip in PostgresAssistantProviderTest reverses I2 and I1 before the three 3c-1 migrations and re-applies them after; every existing assertion is kept. |
| MP-27 | Out-of-order reversal: with the successor installed and no retained data, the 3c-1 metadata down refuses on the column dependency, the gateway columns survive and a legacy attempt insert still commits. |
| MP-22 | Query plans: the use-timestamp lookup and the recovery scan use their partial indexes with bounded rows. |
| MP-23 | Lock behaviour: I1 and I2 complete or fail within the 250 ms lock timeout against a held conflicting lock, leaving the catalog unchanged on failure. |
| MP-24 | SQLite: both migrations are skipped and the closed historical boundary is unchanged. |

These cover the migration only. The runtime acceptance criteria of design §13 are separate and also not run.

## 11. This checkpoint's validation

### 11.1 Without a database

| Check | Result |
| --- | --- |
| Inventory, frozen and dependency hashes | 802 files, 0 drift; 14/14; 7/7 |
| Line anchors in the frozen listing (constraint headers, branch starts, function boundaries, the two edited statements) | all asserted before extraction |
| Verbatim fragments present unmodified in the new and down listings | all five |
| Successor functions equal the frozen text after reversing the one stated edit | both |
| Parentheses balanced outside string literals; dollar quotes paired | every listing and both new checks |
| No frozen file, runtime file, test, migration or configuration changed | confirmed after generation |

Listing identities:

| Listing | SHA-256 | Bytes | Lines |
| --- | --- | --- | --- |
| `I1_up` | `d56daa0c527cacab02099c0a01c7606f782f99c69b63360a8f113ca3a2b02d79` | 24,952 | 314 |
| `I2_up` | `48268c0af718a7f9a8580d4deb7d6614b330b65782da9b2a9012660e7c771b64` | 446 | 6 |
| `I1_down` | `be38814f04bb5c98c4b3920a8274453deb9f7f16bd808d4759d46e57b3c0d84b` | 14,612 | 164 |

Frozen function bodies used as I1 preconditions (MD5 of the text between the dollar quotes): `ai3c_metadata_guard` `575a44e857a1bbee79e2e936172bb640`, `ai3c_credential_guard` `f5b51587e2fad3f8742b5a494f6e6ff6`.

### 11.2 Rehearsal of exactly these listings in a disposable database

Private throwaway PostgreSQL 18.6 cluster, UTF-8 databases named `facturac_test_phase7b3c2_ddl_r2_utf8*`, stopped afterwards. The 3c-1 boundary was built with the existing migrations; rows were built with the frozen schema fixtures. The generator refuses to embed these results unless they were produced from listings with the SHA-256 values above.

| Rehearsed | Result |
| --- | --- |
| Function-body MD5 preconditions versus the live 3c-1 catalog | both equal |
| I1 applied in one transaction on the 3c-1 catalog | parses and installs cleanly |
| I2 | both checks validated; no ai3c_ constraint left unvalidated |
| Catalog difference after I1+I2 among 1819 objects | 16 changed lines: 2 replaced checks, 2 replaced functions and 8 new ainv1 entries; nothing else |
| WHEN clause stored on ainv1_intent_authority | yes (trigger qualification present: gateway_v1 and assistant_intent) |
| I1 down with no inference data | catalog byte-identical to the pre-I1 snapshot |
| Up, down, up | identical catalog |
| I1 and I2 over rows created at the 3c-1 boundary (acks=1 allocations=9 approvals=1 attempts=2 connections=1 controls=6 credentials=1 settings=1 windows=9 ) | every row byte-identical; both checks validate |
| I1 down over those rows | succeeds; rows byte-identical |
| Frozen schema fixtures run after I1+I2 | promoted active credential built; 3c-1 writes accepted by the successor guards |
| Behaviour matrix against the real tables with every trigger active, each case rolled back | 53 cases, 0 unexpected |
| Down with inference data retained | refused (ERROR:  AI invocation downgrade refused: retained data); catalog unchanged |
| I1 re-run with inference data retained | refused (ERROR:  AI invocation migration precondition failed); catalog unchanged |
| Down while another session holds a conflicting lock | ERROR:  canceling statement due to lock timeout after 0.32s; catalog unchanged |
| Race: revoke commits first, send authorization second | authorization refused (ERROR:  Customer inference unavailable); send mark absent, credential revoked, connection enabled |
| Race: send authorization commits first, revoke second | revoke waited 1.04s; send mark kept, credential revoked, connection enabled; finalization then succeeded |
| Race: connection disable commits first; sender holds no lock of its own on settings or connection | sender waited 1.03s, then refused (ERROR:  AI invocation authority changed); send mark absent, credential active, connection disabled |
| Race: send authorization checked first and still uncommitted; disable second | disable waited 1.04s; send mark kept, credential active, connection disabled |
| Out-of-order reversal: the three 3c-1 downs with the successor installed and no retained data | integrity down runs; metadata down REFUSES (cannot drop column purpose of table assistant_provider_attempts); gateway columns survive (2/2); legacy insert committed |
| Recovery after an out-of-order reversal: re-apply the 3c-1 integrity and validation migrations, then run the I1 down | catalog byte-identical to the clean 3c-1 snapshot |
| Correct-order round trip: I1 down, three 3c-1 downs, three 3c-1 ups, I1, I2 | every step succeeds; catalog byte-identical to the installed snapshot |
| Old versus new attempt check on trigger-free copies, 3688 rows (five valid base rows, every single-column mutation, every purpose flip combined with a second change) | 2623 non-inference rows: accepted by both 699, refused by both 1924, disagreements 0; inference rows: old accepts 0, new accepts 200 and refuses 865; legacy or probe rows accepted as inference: 0 |
| File-name selection used by existing tests | both names contain _ai_verification_ (excluded from the frozen upgrade test's historical boundary), match the glob *_ai_verification_*.php, and sort after the three 3c-1 files |

"Catalog byte-identical" in this table means identical definitions as rendered by `pg_get_constraintdef`, `pg_get_functiondef`, `pg_get_triggerdef` and `indexdef`. After the correct-order round trip the physical column numbers differ, because 3c-1's own down drops columns that its up re-adds; the independent reviewer observed this and found `pg_dump -s` identical.

Not performed by the author: the authorized execution; any test in §10; the frozen suites with migration files present; the receipt-binding refusal branch; query plans at volume.

### 11.3 Independent review of revision 2

A separate reviewer reviewed revision 2 and rehearsed it in its own disposable cluster with its own scripts. It read this package, the design and the first review in full, so it saw the author's documented reasoning; what it did not read or reuse were the author's scripts and scratch material. It is a different agent of the same model family, not a different organisation or person. Its report is [here](phase-7b-3c-2-migration-ddl-review-r2.md), including its follow-up on revision 3; the results below are the reviewer's, not the author's.

- **Verdict: changes required**, three text-only changes, no listing change. Revision 3 applies them (§14).
- Confirmed by its own hashing and execution: the fragment hashes, both function-edit reversals, the body MD5 preconditions, single-batch atomicity of I1, I2 and the down, refusal of a second I1, populated rows byte-identical, and the out-of-order reversal and its recovery.
- Its own behaviour matrix of 223 cases came out as it had predicted from reading the listings. Its constraint differentials compared the **frozen checks with the successor checks**, not either with a specification, and found no disagreement on 29,187 non-inference attempt rows and 304,708 non-inference approval rows.
- It exercised `AI invocation receipt binding`, which the author had not, three ways.
- It ran existing tests with both draft migrations present in a scratch copy of the repository: all four frozen 3c-1 test files pass, the two glob-selecting tests pass unmodified, and `PostgresAssistantProviderTest` passes 18 of 19 unmodified and 19 of 19 with the one edit of §6. Within what it ran — all sixteen `tests/Unit/Postgres*.php` files, `Feature/TenantAiStorageTest` on PostgreSQL, and the whole SQLite suite — nothing else failed because of the drafts. Three SQLite tests did not pass, with or without the drafts; the reviewer judged them environmental. Feature tests other than `TenantAiStorageTest` were run on SQLite only.
- Disclosed deviation of that run: because the scratch copy lived under a world-writable directory, it exempted that one directory on one line each of two key-file permission checks, in the scratch copy only, and used PHP 8.5.10 rather than 8.4.
- It found a fifth test that selects AI migrations by name, the frozen contract test's SQLite glob, which the new names fall outside. It is unaffected.
- Not verified, per the reviewer's own list (this summary is not a substitute for it): the authorized execution; production-like volume (index choice was checked on 60,000 synthetic rows only); any PHP inference writer, since none exists; the proposed tests as tests; receipt MAC verification after I1+I2 (row bytes were compared, the verifier was not run); PHP 8.4, and the unmodified key-file permission check, for the test runs; Feature tests other than `TenantAiStorageTest` on PostgreSQL; `REPEATABLE READ` and `SERIALIZABLE`; whether the two writers without a lock timeout are ever called under an outer wrapper that sets one.

## 12. Questions the review must answer

The six questions of revision 1, with the outcome of both reviews.

1. **Late `FOR SHARE` in the deferred trigger — keep, for a corrected reason.** Revision 2 said no cycle had been found. The independent review found one for a writer that skips the canonical locks (§3.6). The reads stay because they still make the backstop hold for the case they were added for, and because the cycle is fail-safe and impossible for a conforming writer. The invariant of §3.6 is now stated as a condition.
2. **Exact equality of use timestamp and send instant — keep.** It fails closed on a backwards clock step. See also §9.1 items 4 and 5.
3. **Permanent `verification_outcome='not_observed'` on inference rows — keep.**
4. **Settings revision and entitlement equality in the trigger — keep.**
5. **Reused refusal text `Customer inference unavailable` — keep.**
6. **Account circuit on unknown usage needs no DDL — confirmed.**

The independent reviewer agreed with 2 to 6, and with keeping the reads in 1 but not with revision 2's stated reason.

## 13. Follow-up review handoff

> Confirm that revision 4 of the Phase 7B.3c-2 migration package closes the three sentence-level changes required by the follow-up review of revision 3 (the lock-timeout statement in §3.6, the description of the reviewer in §11.3, and the three overstatements in §11.3), and that the three required changes of the independent review of revision 2 remain closed. The follow-up may be limited to the changed text and a re-check that the three listing hashes equal those reviewed: (1) the corrected statement about the late `FOR SHARE` reads and the writer-lock invariant in §3.6, §5.2 and §12; (2) the expected results now given for MP-14; (3) the completed lock matrix in §5.1 and the reworded lock-order sentence in §3. Verify the 802-file inventory and all 14 frozen hashes first. Do not apply anything to a project, development or production database, write runtime code, edit frozen files, enable any switch or make any provider request.
>
> Finish with exactly one of `PHASE 7B.3c-2 MIGRATION PACKAGE — APPROVED FOR BOUNDED EXECUTION` or `PHASE 7B.3c-2 MIGRATION PACKAGE — CHANGES REQUIRED`, with a finite list of required changes, then stop. Approval authorizes only the retained implementation specification in design §17, not activation.

## 14. Revision history

| Revision | Change | Reason |
| --- | --- | --- |
| 1 | Initial package. Listings not executed. | – |
| 2 | Migration file names contain `_ai_verification_`. | Review D1: the frozen upgrade test selected I1 as a historical migration and it failed on the pre-3c-1 schema. |
| 2 | `WHEN` clause on `ainv1_intent_authority`. | Review D2: reversing the 3c-1 migrations first left the trigger orphaned and broke legacy attempt inserts. |
| 2 | §6 rewritten around the four file-name-selecting tests; one required non-frozen test edit recorded; MP-21 narrowed and MP-25 to MP-27 added. | Review required change 3. |
| 2 | §8 states the down-ordering rule; §3 states single-batch execution. | Review required change 4. |
| 2 | Listings and evidence regenerated; rehearsal repeated on the revised listings (§11.2). | Review required change 5. |
| 3 | §3.6, §5.2 and §12 corrected: the late `FOR SHARE` reads can complete a lock cycle for a writer that skipped the canonical locks; the writer-lock invariant is stated. | Independent review D1, required change 1. |
| 3 | MP-14 given expected results for writers with and without their locks. | Independent review required change 2. |
| 3 | §5.1 completed with the preflight's and the down's locks; lock-order sentence in §3 reworded. The I1 header comment is unchanged to keep the listing hash. | Independent review D2, required change 3. |
| 3 | §9.1 added (conditions on the implementation), §11.3 added (independent review results), one precision note under §11.2. | Independent review findings N2 to N7 and N11. |
| 4 | §3.6: removed the false statement that every writer sets a 250 ms lock timeout; named the two that do not; recorded which party was aborted in the reviewer's runs. | Follow-up review F1 and the reviewer's correction to its own record. |
| 4 | §11.3: the reviewer described accurately (it read the author's documents, not the author's scripts); differential wording corrected; the test claim qualified; the not-verified list completed. §9.1 item 7 says what was executed and what was read. | Follow-up review F2, F3 and the reviewer's N7 correction. |

Between revisions 1 and 2 the only listing changes were the trigger definition and the file names in comments. **Since revision 2 no listing has changed at all**; the generator refuses to build unless the three listing hashes equal those that were rehearsed and independently reviewed.

PHASE 7B.3c-2 MIGRATION PACKAGE — READY FOR PRE-EXECUTION REVIEW
