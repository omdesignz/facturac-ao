<?php

namespace App;

enum BillingPaymentEventType: string
{
    case ReferenceCreated = 'reference_created';
    case StatusChecked = 'status_checked';
    case PaymentConfirmed = 'payment_confirmed';
    case ReferenceExpired = 'reference_expired';
    case ReferenceCancelled = 'reference_cancelled';
    case SubscriptionActivated = 'subscription_activated';
    case ProviderFailed = 'provider_failed';
}
