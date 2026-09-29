<?php

namespace Modules\Wallet\Enums;

/**
 * Why a `pin_recovery_request` exists — the admin review screen (list, images, approve/reject) is the
 * same for both, but the copy and the notification wording differ.
 */
enum PinRecoveryReason: string
{
    /** "I forgot my PIN" via the ID/passport photo the owner chose as their recovery method. */
    case RecoveryDocument = 'recovery_document';

    /** The PIN got permanently frozen (a wrong attempt right after a temporary lock) — a selfie + an ID
     *  photo, regardless of the owner's configured recovery method. */
    case SecurityFreeze = 'security_freeze';
}
