<?php

namespace Modules\Rides\Enums;

/** Who may pick a cancel reason. */
enum CancelReasonActor: string
{
    case Rider = 'rider';
    case Driver = 'driver';
    case Both = 'both';
}
