<?php

namespace App\Fiscal;

use Closure;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Contracts\Activity;

/** Exact reviewed PostgreSQL schema SQL. No provider or receipt authority. */
final class TenantAiVerificationSchema
{
    /**
     * Controlled maintenance only: the operator must close admission, drain writers,
     * and independently approve the immutable mapping before calling this procedure.
     * No route, command, grant inference, or provider authority is supplied here.
     *
     * @param  list<array{legacy_budget_id: string, deployment_id: string, usage_budget_id: string}>  $manifest
     * @param  Closure(): ?Activity  $audit
     */
    public static function reconcile(array $manifest, Closure $audit): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() !== 0) {
            throw new \RuntimeException('AI reconciliation requires its own PostgreSQL transaction.');
        }

        try {
            DB::transaction(function () use ($manifest, $audit): void {
                $sql = self::reconciliation();
                $position = strpos($sql, 'DO $reconcile$');
                if ($position === false) {
                    throw new \LogicException('Reviewed reconciliation boundary missing.');
                }
                DB::connection()->getPdo()->exec(substr($sql, 0, $position));
                DB::insert('INSERT INTO ai3c_legacy_map SELECT legacy_budget_id,deployment_id,usage_budget_id FROM jsonb_to_recordset(?::jsonb) AS x(legacy_budget_id uuid,deployment_id uuid,usage_budget_id uuid)', [json_encode($manifest, JSON_THROW_ON_ERROR)]);
                DB::connection()->getPdo()->exec(substr($sql, $position));
                RequiredAudit::record($audit);
            }, 1);
        } finally {
            if (DB::select("SELECT name FROM pg_prepared_statements WHERE name='ai3c_load_map'") !== []) {
                DB::unprepared('DEALLOCATE ai3c_load_map');
            }
        }
    }

    /** @return literal-string */
    public static function metadata(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function constraints(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function guards(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function validation(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function reconciliation(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function downPreflight(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function integrityDown(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }

    /** @return literal-string */
    public static function metadataDown(): string
    {
        return <<<'SQL'
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
SQL."\n";
    }
}
