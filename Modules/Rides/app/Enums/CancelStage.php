<?php

namespace Modules\Rides\Enums;

/** How far the ride had gone when it was cancelled — the fee depends on it. */
enum CancelStage: string
{
    case BeforeAccept = 'before_accept';
    case AfterAccept = 'after_accept';
    case AfterArrival = 'after_arrival';
    case InProgress = 'in_progress';
}
