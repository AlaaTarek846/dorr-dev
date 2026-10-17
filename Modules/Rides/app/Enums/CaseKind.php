<?php

namespace Modules\Rides\Enums;

enum CaseKind: string
{
    /** A problem with the driver, the car, the price, the payment, safety or privacy (R-133, R-134). */
    case Complaint = 'complaint';
    /** An objection to a charge on the ride — its own record, apart from general support (R-135, R-136, D-235). */
    case Dispute = 'dispute';
    /** The captain says the rider left without paying a cash ride (D-229). */
    case UnpaidReport = 'unpaid_report';
}
