<?php

namespace Modules\Chat\Enums;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
}
