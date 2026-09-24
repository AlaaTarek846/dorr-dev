<?php

namespace Modules\Wallet\Enums;

enum WalletDirection: string
{
    case Credit = 'credit';
    case Debit = 'debit';
}
