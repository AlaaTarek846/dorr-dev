<?php

namespace Modules\Rides\Enums;

/** What a tax is charged on — the commission in Saudi Arabia (same as Jawad). */
enum TaxBase: string
{
    case Commission = 'commission';
    case Fare = 'fare';
    case FareAndFees = 'fare_and_fees';
}
