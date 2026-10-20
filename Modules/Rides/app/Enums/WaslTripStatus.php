<?php

namespace Modules\Rides\Enums;

/** Whether a completed trip reached Wasl. */
enum WaslTripStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case NotRequired = 'not_required';
}
