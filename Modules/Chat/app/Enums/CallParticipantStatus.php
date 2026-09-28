<?php

namespace Modules\Chat\Enums;

enum CallParticipantStatus: string
{
    case Ringing = 'ringing';
    case Joined = 'joined';
    case Declined = 'declined';
    case Missed = 'missed';
    case Left = 'left';
}
