<?php

namespace App\Fiscal;

/** Exact reviewed PostgreSQL successor SQL for inference attempts. No provider, receipt or admission authority. */
final class TenantAiInvocationSchema
{
    /** @return literal-string */
    public static function install(): string
    {
        return <<<'SQL'
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
SQL;
    }

    /** @return literal-string */
    public static function validation(): string
    {
        return <<<'SQL'
-- REVIEW LISTING ONLY. I2: 2026_10_10_010100_validate_ai_verification_invocation_successor.php (proposed name only).
-- One transaction; no admission or feature-availability change.
SET LOCAL lock_timeout = '250ms';
SET LOCAL statement_timeout = '10s';
ALTER TABLE public.assistant_provider_attempts VALIDATE CONSTRAINT assistant_provider_attempts_values;
ALTER TABLE public.assistant_provider_tenants VALIDATE CONSTRAINT ai3c_approval_shape_ck;
SQL;
    }

    /** @return literal-string */
    public static function down(): string
    {
        return <<<'SQL'
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
SQL;
    }
}
