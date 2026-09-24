<?php

namespace Modules\Wallet\Enums;

enum PaymentMethodType: string
{
    case Online = 'online';
    case Manual = 'manual';
}
