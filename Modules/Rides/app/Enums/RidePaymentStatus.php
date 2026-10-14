<?php

namespace Modules\Rides\Enums;

/** Where the ride's money stands. */
enum RidePaymentStatus: string
{
    case Pending = 'pending';
    case AwaitingTopup = 'awaiting_topup';
    case Held = 'held';
    case Captured = 'captured';
    case Released = 'released';
    case CashDue = 'cash_due';
    case CashCollected = 'cash_collected';
    case Failed = 'failed';
}
