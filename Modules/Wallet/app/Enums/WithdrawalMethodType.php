<?php

namespace Modules\Wallet\Enums;

enum WithdrawalMethodType: string
{
    case Bank = 'bank';
    case MobileWallet = 'mobile_wallet';
}
