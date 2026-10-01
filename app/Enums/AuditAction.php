<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditAction: string
{
    case InvitationSent = 'invitation.sent';
    case InvitationResent = 'invitation.resent';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';
    case InvitationDeclined = 'invitation.declined';
    case MemberRemoved = 'member.removed';
    case MemberRankChanged = 'member.rank_changed';
    case OwnershipTransferred = 'ownership.transferred';
    case CheckoutStarted = 'billing.checkout_started';
    case SubscriptionCancelled = 'billing.subscription_cancelled';
    case SubscriptionResumed = 'billing.subscription_resumed';
    case EntitlementRefreshRequested = 'entitlements.refresh_requested';
    case EntitlementOverrideGranted = 'entitlements.override_granted';
    case EntitlementOverrideRevoked = 'entitlements.override_revoked';
    case WebhookReplayed = 'billing.webhook_replayed';
}
