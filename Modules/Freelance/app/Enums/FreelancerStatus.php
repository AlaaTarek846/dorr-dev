<?php

namespace Modules\Freelance\Enums;

/**
 * Where a freelancer's account stands (FL-108 / FL-110):
 *
 *   registered → (name + terms) → profile_ready → (submits ID documents) → verification_submitted
 *     → verified                 the admin approved: the freelancer can apply, accept and get paid
 *     → verification_rejected    turned down with a reason; they may submit again
 *     → changes_requested        a document must be fixed (also when an approved one expires)
 *
 * The admin may also suspend (they sign in and see why) or block (they can't sign in). Only `verified`
 * may do the work — the gate is FreelancerGate.
 */
enum FreelancerStatus: string
{
    case Registered = 'registered';
    case ProfileReady = 'profile_ready';
    case VerificationSubmitted = 'verification_submitted';
    case Verified = 'verified';
    case VerificationRejected = 'verification_rejected';
    case ChangesRequested = 'changes_requested';
    case Suspended = 'suspended';
    case Blocked = 'blocked';

    /** The states from which a verification request may be sent. */
    public function canSubmitVerification(): bool
    {
        return in_array($this, [self::ProfileReady, self::VerificationRejected, self::ChangesRequested], true);
    }

    /** What the admin can set by hand: suspend / block, and lift that back to where the account was. */
    public static function adminSettable(): array
    {
        return [self::Verified->value, self::ProfileReady->value, self::Suspended->value, self::Blocked->value];
    }
}
