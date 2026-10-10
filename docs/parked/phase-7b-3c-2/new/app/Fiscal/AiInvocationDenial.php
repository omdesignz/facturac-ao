<?php

namespace App\Fiscal;

/** Closed internal reasons; the public response stays the fixed payload-free failure. */
enum AiInvocationDenial: string
{
    case AiDisabled = 'ai_disabled';
    case VapUnavailable = 'vap_unavailable';
    case CredentialPending = 'credential_pending';
    case RouteDisabled = 'route_disabled';
    case ProfileUnsupported = 'profile_unsupported';
    case ConnectionUnavailable = 'connection_unavailable';
    case CredentialNotActive = 'credential_not_active';
    case CredentialExpired = 'credential_expired';
    case ReceiptUntrusted = 'receipt_untrusted';
    case GenerationMismatch = 'generation_mismatch';
    case BindingMismatch = 'binding_mismatch';
    case EntitlementMissing = 'entitlement_missing';
    case ApprovalMissing = 'approval_missing';
    case AcknowledgementMissing = 'acknowledgement_missing';
    case ControlBlocked = 'control_blocked';
    case QuotaExceeded = 'quota_exceeded';
    case AuthorityChanged = 'authority_changed';
    case DeadlineExceeded = 'deadline_exceeded';
    case RuntimeUnsupported = 'runtime_unsupported';
}
