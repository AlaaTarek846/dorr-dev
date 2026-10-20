<?php

namespace Modules\Rides\Enums;

/** The regulator's answer about a driver or a vehicle. */
enum RegulatorStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Pending = 'pending';
    case Rejected = 'rejected';
}
