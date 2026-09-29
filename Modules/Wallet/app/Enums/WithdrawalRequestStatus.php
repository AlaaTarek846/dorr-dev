<?php

namespace Modules\Wallet\Enums;

enum WithdrawalRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
