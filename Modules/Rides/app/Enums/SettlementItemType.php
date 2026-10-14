<?php

namespace Modules\Rides\Enums;

/** One money item between DORR and the driver for a ride. */
enum SettlementItemType: string
{
    case TripEarning = 'trip_earning';
    case Commission = 'commission';
    case Tax = 'tax';
    case Tip = 'tip';
    case DiscountCompensation = 'discount_compensation';
    case CancelCompensation = 'cancel_compensation';
    case DebtCollected = 'debt_collected';
    case Adjustment = 'adjustment';
}
