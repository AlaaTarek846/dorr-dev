<?php

namespace Modules\Chat\Enums;

enum CallType: string
{
    case Audio = 'audio';
    case Video = 'video';
}
