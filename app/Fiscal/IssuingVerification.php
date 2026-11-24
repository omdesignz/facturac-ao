<?php

namespace App\Fiscal;

use App\Models\User;
use App\Models\Workspace;

/**
 * Whether someone has done enough to issue fiscal documents for a company.
 *
 * Two-step verification is required unless the company's owner has waived it
 * for issuing. The waiver covers issuing and selling only: signing in, the AGT
 * connection, billing, integrations and other sensitive operations keep their
 * own requirements, and the password is still asked before issuing.
 */
class IssuingVerification
{
    public static function satisfiedBy(User $user, Workspace|int $workspace): bool
    {
        return $user->hasEnabledTwoFactorAuthentication()
            || ! self::requiredFor($workspace);
    }

    public static function requiredFor(Workspace|int $workspace): bool
    {
        if ($workspace instanceof Workspace) {
            return $workspace->requires_mfa_to_issue !== false;
        }

        return Workspace::query()->whereKey($workspace)->value('requires_mfa_to_issue') !== false;
    }
}
