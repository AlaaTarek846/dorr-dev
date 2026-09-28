<?php

namespace Modules\Wallet\Enums;

enum PinRecoveryStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
