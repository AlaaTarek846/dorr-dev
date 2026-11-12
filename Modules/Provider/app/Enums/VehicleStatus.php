<?php

namespace Modules\Provider\Enums;

/** A vehicle sends offers only once approved; an edit sends it back to pending. */
enum VehicleStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Inactive = 'inactive';
}
