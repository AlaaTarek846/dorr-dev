<?php

namespace Modules\Rides\Enums;

/** One money item between DORR and the driver for a ride. */
enum SettlementItemType: string
{
    case TripEarning = 'trip_earning';
    case Commission = 'commission';
    case Tax = 'tax';
    case Tip = 'tip';
    // DORR's fees a driver kept in a cash ride's money (rides_tasks R-47, R-48).
    case CustomerFee = 'customer_fee';
    case PaymentMethodFee = 'payment_method_fee';
    case DiscountCompensation = 'discount_compensation';
    case CancelCompensation = 'cancel_compensation';
    case DebtCollected = 'debt_collected';
    case Adjustment = 'adjustment';
}
