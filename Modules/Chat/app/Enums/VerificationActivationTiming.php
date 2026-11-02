<?php

namespace Modules\Chat\Enums;

/**
 * When the badge turns on for a track (REQ-02-02). The admin sets one per track; someone allowed
 * can change it for one request.
 */
enum VerificationActivationTiming: string
{
    case AfterIdentityCheck = 'after_identity_check';
    case AfterApproval = 'after_approval';
    case AfterPayment = 'after_payment';
    case AfterApprovalAndPayment = 'after_approval_and_payment';
    case AfterProofApproved = 'after_proof_approved';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
