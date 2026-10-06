<?php

namespace Modules\Wallet\Enums;

enum CheckoutStatus: string
{
    /** Waiting to be paid (or for the gateway it was handed to). */
    case Pending = 'pending';
    case Paid = 'paid';
    /** The gateway's money arrived but couldn't settle it (it stays in the wallet). */
    case Failed = 'failed';
    /** Never paid in time. */
    case Expired = 'expired';
}
